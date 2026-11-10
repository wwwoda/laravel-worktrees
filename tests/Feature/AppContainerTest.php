<?php

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Woda\Worktrees\Contracts\BootstrapStrategy;
use Woda\Worktrees\Contracts\TemplateRecipe;
use Woda\Worktrees\Shared\DatabaseProvisioner;
use Woda\Worktrees\Shared\DatabaseServer;
use Woda\Worktrees\Shared\MainCheckout;
use Woda\Worktrees\Shared\QueueList;
use Woda\Worktrees\SharedServicesBootstrapStrategy;
use Woda\Worktrees\Tests\Support\FakeDatabaseServer;
use Woda\Worktrees\Tests\Support\FakeTemplateRecipe;
use Woda\Worktrees\WorktreeManager;

/*
 * The app container part of the shared-services strategy: shared stack,
 * dependencies, frontend build in the container, hot file, queue worker.
 * Docker and git calls are faked.
 */
beforeEach(function () {
    $this->base = sys_get_temp_dir().'/worktrees-shared-'.uniqid();
    $this->migrations = 'database/lw-test-migrations-'.uniqid();
    File::ensureDirectoryExists($this->base);

    File::put(base_path('.env'), "APP_NAME=App\nAPP_URL=https://app.test\nDB_DATABASE=app\nCOMPOSE_PROJECT_NAME=app\n");
    config()->set('app.url', 'https://app.test');
    config()->set('worktrees.base_path', $this->base);
    config()->set('worktrees.copy_files', ['.env']);
    config()->set('worktrees.database.strategy', 'none');
    config()->set('worktrees.bootstrap.strategy', 'shared');
    config()->set('worktrees.bootstrap.build_frontend', false);
    config()->set('worktrees.env_overrides', ['COMPOSE_PROJECT_NAME' => 'app-{name}']);
    config()->set('worktrees.shared.database.prefix', 'hp');
    config()->set('worktrees.shared.database.main', 'hungryport');
    config()->set('worktrees.shared.database.parallel_workers', 2);
    config()->set('worktrees.shared.database.template.hash_paths', ['database/migrations']);
    config()->set('worktrees.shared.env.DB_TEST_DATABASE', '{db_test}');

    $this->server = new FakeDatabaseServer;
    $this->server->databases['hungryport'] = ['production-like'];
    $this->recipe = new FakeTemplateRecipe($this->server);
    app()->instance(DatabaseServer::class, $this->server);
    app()->instance(TemplateRecipe::class, $this->recipe);

    $this->cpResult = Process::result();
    $this->worktrees = [];
    $this->checkoutFiles = [];
    $this->main = $this->base.'/main';
    File::ensureDirectoryExists($this->main.'/.git');
    $this->commonDir = $this->main.'/.git';
    $this->fakeProcesses = function () {
        Process::fake([
            'git show-ref *' => Process::result(exitCode: 1),
            'git worktree add *' => function (PendingProcess $process) {
                preg_match("/worktree add -b '([^']+)' '([^']+)'/", (string) $process->command, $match);
                File::ensureDirectoryExists($match[2]);
                $this->worktrees[$match[1]] = $match[2];
                foreach ($this->checkoutFiles as $file => $content) {
                    File::ensureDirectoryExists(dirname($match[2].'/'.$file));
                    File::put($match[2].'/'.$file, $content);
                }

                return Process::result();
            },
            'git worktree list --porcelain' => fn () => Process::result(implode('', array_map(
                fn (string $branch, string $path) => "worktree {$path}\nHEAD abc\nbranch refs/heads/{$branch}\n\n",
                array_keys(['master' => base_path(), ...$this->worktrees]),
                ['master' => base_path(), ...$this->worktrees],
            ))),
            "docker compose exec -T 'laravel.test' sh -c *echo*" => Process::result('ready'),
            'git rev-parse --path-format=absolute --git-common-dir' => fn () => Process::result($this->commonDir),
            "'cp' *" => fn (PendingProcess $process) => $this->cpResult,
            'docker compose -f shared.yml up -d' => function () {
                $this->server->up = true;

                return Process::result();
            },
            'docker compose config --format json' => Process::result(),
            'docker compose up -d' => fn () => isset($this->onUp) ? ($this->onUp)() : Process::result(),
            '*' => Process::result(),
        ]);
    };
    ($this->fakeProcesses)();
    MainCheckout::forget();
    $this->path = fn (string $name) => $this->base.'/'.basename(base_path()).'-'.$name;
});

afterEach(function () {
    File::deleteDirectory($this->base);
    File::deleteDirectory(base_path('storage/app/worktrees'));
    File::delete(base_path('.env'));
});

test('the shared stack is started only when the database server does not answer', function () {
    config()->set('worktrees.shared.stack.start_command', 'docker compose -f shared.yml up -d');

    $this->artisan('worktree:create', ['name' => 'up'])->assertSuccessful();
    Process::assertNotRan('docker compose -f shared.yml up -d');

    $this->server->up = false;
    $this->artisan('worktree:create', ['name' => 'down'])->assertSuccessful();
    Process::assertRan(fn (PendingProcess $p) => $p->command === 'docker compose -f shared.yml up -d' && $p->path === $this->main);
});

test('a down database server without a start command stops the setup with a message', function () {
    $this->server->up = false;

    $this->artisan('worktree:create', ['name' => 't9'])
        ->expectsOutputToContain('does not answer')
        ->assertFailed();
});

test('the app container is started and the bundle is built inside it', function () {
    config()->set('worktrees.bootstrap.build_frontend', true);
    app()->forgetInstance(BootstrapStrategy::class);
    app()->forgetInstance(WorktreeManager::class);

    $this->artisan('worktree:create', ['name' => 't9'])->assertSuccessful();

    Process::assertRan(fn (PendingProcess $p) => $p->command === 'docker compose up -d' && $p->path === ($this->path)('t9'));
    Process::assertRan(fn (PendingProcess $p) => $p->command === "docker compose exec -T 'laravel.test' sh -c 'pnpm install --frozen-lockfile && pnpm run build'");
    Process::assertNotRan('pnpm run build');
});

test('vendor and node_modules are copied with copy-on-write when the lock files match', function () {
    File::ensureDirectoryExists($this->main.'/vendor');
    File::ensureDirectoryExists($this->main.'/node_modules');
    File::put($this->main.'/composer.lock', 'lock-a');
    File::put($this->main.'/pnpm-lock.yaml', 'pnpm-a');
    $this->checkoutFiles = ['composer.lock' => 'lock-a', 'pnpm-lock.yaml' => 'pnpm-a'];

    $this->artisan('worktree:create', ['name' => 't9'])->assertSuccessful();

    $flag = PHP_OS_FAMILY === 'Darwin' ? '-c' : '--reflink=always';
    Process::assertRan(fn (PendingProcess $p) => is_array($p->command) && in_array($flag, $p->command, true) && in_array($this->main.'/vendor', $p->command, true));
    Process::assertRan(fn (PendingProcess $p) => is_array($p->command) && in_array($this->main.'/node_modules', $p->command, true));
    // After the copy, composer install completes it (the main checkout may have been
    // installed with --no-dev) and builds the autoloader for this branch.
    Process::assertRan(fn (PendingProcess $p) => $p->command === "docker compose exec -T -e 'COMPOSER_DISABLE_NETWORK=1' 'laravel.test' sh -c 'composer install --no-interaction --prefer-dist'" && $p->path === ($this->path)('t9'));
    // The host install completes the copy (the main checkout may lack dev packages).
    Process::assertRan(fn (PendingProcess $p) => $p->command === 'pnpm install --prefer-offline' && $p->path === ($this->path)('t9'));
});

test('copy-on-write is skipped when the lock files differ', function () {
    File::ensureDirectoryExists($this->main.'/vendor');
    File::put($this->main.'/composer.lock', 'lock-a');
    $this->checkoutFiles = ['composer.lock' => 'lock-b'];

    $this->artisan('worktree:create', ['name' => 't9'])->assertSuccessful();

    Process::assertNotRan(fn (PendingProcess $p) => is_array($p->command) && $p->command[0] === 'cp');
    Process::assertRan(fn (PendingProcess $p) => is_string($p->command) && str_contains($p->command, 'composer install'));
    Process::assertRan('pnpm install --prefer-offline');
});

test('a filesystem without copy-on-write falls back to a normal install', function () {
    File::ensureDirectoryExists($this->main.'/vendor');
    File::put($this->main.'/composer.lock', 'lock-a');
    $this->checkoutFiles = ['composer.lock' => 'lock-a'];
    $this->cpResult = Process::result(errorOutput: 'Operation not supported', exitCode: 1);

    $this->artisan('worktree:create', ['name' => 't9'])->assertSuccessful();

    Process::assertRan(fn (PendingProcess $p) => is_string($p->command) && str_contains($p->command, 'composer install'));
});

test('a public/hot whose dev server does not answer is removed; a live one stays', function () {
    $dir = $this->base.'/hot';
    File::ensureDirectoryExists($dir.'/public');
    $strategy = fn (bool $answers) => new SharedServicesBootstrapStrategy(
        provisioner: app(DatabaseProvisioner::class),
        appService: 'laravel.test', nodePackageManager: 'pnpm', buildFrontend: false,
        devServerAnswers: fn (string $url) => $answers,
    );

    File::put($dir.'/public/hot', 'http://localhost:5173');
    $strategy(true)->bringUp($dir);
    expect(File::exists($dir.'/public/hot'))->toBeTrue();

    $strategy(false)->bringUp($dir);
    expect(File::exists($dir.'/public/hot'))->toBeFalse();
});

test('the queue list comes from every queue the Horizon config names', function () {
    $dir = $this->base.'/horizon';
    File::ensureDirectoryExists($dir.'/config');
    File::put($dir.'/composer.json', json_encode(['require' => ['laravel/horizon' => '^5.0']]));
    File::put($dir.'/config/horizon.php', <<<'PHP'
        <?php
        return [
            'defaults' => ['supervisor-1' => ['queue' => ['default', 'mail']]],
            'environments' => [
                'production' => ['supervisor-1' => ['queue' => ['default', 'mail']], 'media' => ['queue' => 'media,thumbnails']],
                'local' => ['supervisor-1' => ['queue' => ['default']]],
            ],
        ];
        PHP);

    expect(QueueList::for($dir))->toBe(['default', 'mail', 'media', 'thumbnails']);

    File::put($dir.'/composer.json', json_encode(['require' => ['laravel/framework' => '^12.0']]));
    expect(QueueList::for($dir))->toBe(['default']);
});

test('the worker mode and queues go to .env; --horizon picks Horizon', function () {
    $this->artisan('worktree:create', ['name' => 't9'])->assertSuccessful();
    $this->artisan('worktree:create', ['name' => 'h1', '--horizon' => true])->assertSuccessful();

    expect(File::get(($this->path)('t9').'/.env'))->toContain('WORKTREE_QUEUE_WORKER=listen')->toContain('WORKTREE_QUEUES=default')
        ->and(File::get(($this->path)('h1').'/.env'))->toContain('WORKTREE_QUEUE_WORKER=horizon');
});

test('the compose file goes to .env as COMPOSE_FILE', function () {
    config()->set('worktrees.shared.app.compose_file', 'compose.worktree.yml');
    app()->forgetInstance(BootstrapStrategy::class);
    app()->forgetInstance(WorktreeManager::class);

    $this->artisan('worktree:create', ['name' => 't9'])->assertSuccessful();

    expect(File::get(($this->path)('t9').'/.env'))->toContain('COMPOSE_FILE=compose.worktree.yml');
});

test('create prints the time of each step and the URL', function () {
    $this->artisan('worktree:create', ['name' => 't9'])
        ->expectsOutputToContain('database')
        ->expectsOutputToContain('total')
        ->expectsOutputToContain('https://app-t9.test')
        ->assertSuccessful();
});

test('Horizon in require-dev also gives the queue list of its config', function () {
    $dir = $this->base.'/horizon-dev';
    File::ensureDirectoryExists($dir.'/config');
    File::put($dir.'/composer.json', json_encode(['require-dev' => ['laravel/horizon' => '^5.0']]));
    File::put($dir.'/config/horizon.php', "<?php return ['defaults' => ['supervisor-1' => ['queue' => ['default', 'mail']]]];");

    expect(QueueList::for($dir))->toBe(['default', 'mail']);
});

test('the bundle build in the container installs with the lock file of each package manager', function (string $pm, string $install) {
    config()->set('worktrees.bootstrap.build_frontend', true);
    config()->set('worktrees.bootstrap.node_package_manager', $pm);
    app()->forgetInstance(BootstrapStrategy::class);
    app()->forgetInstance(WorktreeManager::class);

    $this->artisan('worktree:create', ['name' => 't9'])->assertSuccessful();

    Process::assertRan(fn (PendingProcess $p) => $p->command === "docker compose exec -T 'laravel.test' sh -c '{$install} && {$pm} run build'");
})->with([
    'pnpm' => ['pnpm', 'pnpm install --frozen-lockfile'],
    'npm' => ['npm', 'npm ci'],
    'yarn' => ['yarn', 'yarn install --frozen-lockfile'],
    'bun' => ['bun', 'bun install --frozen-lockfile'],
]);

test('the shared stack starts without the per-worktree variables of this process', function () {
    config()->set('worktrees.shared.stack.start_command', 'docker compose -f shared.yml up -d');
    $this->server->up = false;
    putenv('COMPOSE_PROJECT_NAME=app-lane');

    try {
        $this->artisan('worktree:create', ['name' => 't9'])->assertSuccessful();
    } finally {
        putenv('COMPOSE_PROJECT_NAME');
    }

    Process::assertRan(fn (PendingProcess $p) => $p->command === 'docker compose -f shared.yml up -d' && $p->path === $this->main
        && ($p->environment['COMPOSE_PROJECT_NAME'] ?? null) === false
        && ($p->environment['DB_DATABASE'] ?? null) === false);
});

test('a worktree whose compose project is the main checkout\'s is neither started nor torn down', function () {
    // Both folders answer with the project name `app`: the worktree's .env kept the main checkout's name.
    Process::fake(['docker compose config --format json' => Process::result(json_encode(['name' => 'app', 'services' => []]))]);

    $this->artisan('worktree:create', ['name' => 't9'])
        ->expectsOutputToContain('same compose project')
        ->assertFailed();
    Process::assertNotRan('docker compose up -d');

    expect(fn () => app(BootstrapStrategy::class)->tearDown(($this->path)('t9')))->toThrow(RuntimeException::class, 'same compose project');
    Process::assertNotRan(fn (PendingProcess $p) => str_contains((string) $p->command, 'down'));
});

test('the dev server probe counts only a successful answer', function () {
    $router = $this->base.'/router.php';
    File::put($router, '<?php http_response_code(str_contains($_SERVER["REQUEST_URI"], "/ok/") ? 200 : 404); echo "x";');
    $port = 20000 + random_int(0, 9999);
    $server = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", $router], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    $deadline = microtime(true) + 5;
    while (! @fsockopen('127.0.0.1', $port) && microtime(true) < $deadline) {
        usleep(50_000);
    }

    try {
        expect(SharedServicesBootstrapStrategy::devServerAnswers("http://127.0.0.1:{$port}/ok"))->toBeTrue()
            ->and(SharedServicesBootstrapStrategy::devServerAnswers("http://127.0.0.1:{$port}/missing"))->toBeFalse()
            ->and(SharedServicesBootstrapStrategy::devServerAnswers('http://127.0.0.1:1'))->toBeFalse();
    } finally {
        proc_terminate($server);
        proc_close($server);
    }
});

test('missing Sail build contexts are lent from the main checkout', function () {
    $worktree = $this->base.'/wt';
    File::ensureDirectoryExists($worktree);
    File::ensureDirectoryExists($this->main.'/vendor/laravel/sail/runtimes/8.4');
    File::put($this->main.'/vendor/laravel/sail/runtimes/8.4/Dockerfile', 'FROM php');
    $seen = null;
    Process::fake(['docker compose config --format json' => Process::result(json_encode(['services' => ['laravel.test' => ['build' => ['context' => $worktree.'/vendor/laravel/sail/runtimes/8.4']]]]))]);
    $this->onUp = function () use ($worktree, &$seen) {
        $seen = is_file($worktree.'/vendor/laravel/sail/runtimes/8.4/Dockerfile');

        return Process::result();
    };

    app(BootstrapStrategy::class)->bringUp($worktree);

    expect($seen)->toBeTrue();
});
