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
    Process::assertNotRan(fn (PendingProcess $p) => str_contains((string) (is_array($p->command) ? implode(' ', $p->command) : $p->command), 'composer install'));
    Process::assertNotRan('pnpm install');
});

test('copy-on-write is skipped when the lock files differ', function () {
    File::ensureDirectoryExists($this->main.'/vendor');
    File::put($this->main.'/composer.lock', 'lock-a');
    $this->checkoutFiles = ['composer.lock' => 'lock-b'];

    $this->artisan('worktree:create', ['name' => 't9'])->assertSuccessful();

    Process::assertNotRan(fn (PendingProcess $p) => is_array($p->command) && $p->command[0] === 'cp');
    Process::assertRan(fn (PendingProcess $p) => is_string($p->command) && str_contains($p->command, 'composer install'));
    Process::assertRan('pnpm install');
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
