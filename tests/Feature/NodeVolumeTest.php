<?php

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Woda\Worktrees\Contracts\BootstrapStrategy;
use Woda\Worktrees\Contracts\TemplateRecipe;
use Woda\Worktrees\Shared\DatabaseServer;
use Woda\Worktrees\Shared\EnvFile;
use Woda\Worktrees\Shared\MainCheckout;
use Woda\Worktrees\Tests\Support\FakeDatabaseServer;
use Woda\Worktrees\Tests\Support\FakeTemplateRecipe;
use Woda\Worktrees\WorktreeManager;

/*
 * The host node install and the app container's node_modules volume (#23):
 * the host install runs before the containers start, a running container that
 * lost its volume is restarted, and the printed URL is expanded.
 * Docker and git calls are faked.
 */
beforeEach(function () {
    $this->base = sys_get_temp_dir().'/worktrees-node-'.uniqid();
    File::ensureDirectoryExists($this->base);

    File::put(base_path('.env'), "APP_NAME=App\nAPP_URL=https://app.test\nDB_DATABASE=app\nCOMPOSE_PROJECT_NAME=app\n");
    config()->set('app.url', 'https://app.test');
    config()->set('worktrees.base_path', $this->base);
    config()->set('worktrees.copy_files', ['.env']);
    config()->set('worktrees.database.strategy', 'none');
    config()->set('worktrees.bootstrap.strategy', 'shared');
    config()->set('worktrees.bootstrap.build_frontend', true);
    config()->set('worktrees.env_overrides', ['COMPOSE_PROJECT_NAME' => 'app-{name}']);
    config()->set('worktrees.shared.database.prefix', 'hp');
    config()->set('worktrees.shared.database.main', 'hungryport');
    config()->set('worktrees.shared.database.parallel_workers', 2);
    config()->set('worktrees.shared.database.template.hash_paths', ['database/migrations']);

    $this->server = new FakeDatabaseServer;
    $this->server->databases['hungryport'] = ['production-like'];
    app()->instance(DatabaseServer::class, $this->server);
    app()->instance(TemplateRecipe::class, new FakeTemplateRecipe($this->server));

    $this->worktrees = [];
    $this->checkoutFiles = [];
    // Commands (patterns below) that fail once.
    $this->failOnce = [];
    // The app service's compose config, and its mount table before and after a restart.
    $this->composeConfig = [];
    $this->mounts = ['/var/www/html/node_modules'];
    $this->mountsAfterRestart = ['/var/www/html/node_modules'];
    $this->restarted = false;
    $this->main = $this->base.'/main';
    File::ensureDirectoryExists($this->main.'/.git');
    $mountinfo = fn (array $points) => implode('', array_map(
        fn (string $point, int $i) => sprintf("%d 1 252:0 /data %s rw,relatime - ext4 /dev/sda rw\n", 100 + $i, str_replace(' ', '\\040', $point)),
        $points, array_keys($points),
    ));
    $once = fn (string $key, Closure $then) => function (PendingProcess $process) use ($key, $then) {
        if (in_array($key, $this->failOnce, true)) {
            $this->failOnce = array_values(array_diff($this->failOnce, [$key]));

            return Process::result(errorOutput: "{$key} failed", exitCode: 1);
        }

        return $then($process);
    };
    Process::fake([
        'git show-ref *' => Process::result(exitCode: 1),
        'git worktree add *' => function (PendingProcess $process) {
            preg_match("/worktree add -b '([^']+)' '([^']+)'/", (string) $process->command, $match);
            File::ensureDirectoryExists($match[2]);
            $this->worktrees[$match[1]] = $match[2];
            foreach ($this->checkoutFiles as $file => $content) {
                File::put($match[2].'/'.$file, $content);
            }

            return Process::result();
        },
        'git worktree list --porcelain' => fn () => Process::result(implode('', array_map(
            fn (string $branch, string $path) => "worktree {$path}\nHEAD abc\nbranch refs/heads/{$branch}\n\n",
            array_keys(['master' => base_path(), ...$this->worktrees]),
            ['master' => base_path(), ...$this->worktrees],
        ))),
        'git rev-parse --path-format=absolute --git-common-dir' => fn () => Process::result($this->main.'/.git'),
        "docker compose exec -T 'laravel.test' sh -c *echo*" => Process::result('ready'),
        "docker compose exec -T 'laravel.test' sh -c 'cat /proc/self/mountinfo'" => fn () => Process::result($mountinfo($this->restarted ? $this->mountsAfterRestart : $this->mounts)),
        "docker compose restart 'laravel.test'" => function () {
            $this->restarted = true;

            return Process::result();
        },
        'docker compose config --format json' => fn (PendingProcess $p) => Process::result((string) json_encode(['name' => 'project-'.md5((string) $p->path)] + $this->composeConfig)),
        'docker compose up -d' => $once('up', fn () => Process::result()),
        // The host install makes node_modules, as pnpm does.
        'pnpm install' => $once('node', function (PendingProcess $process) {
            File::ensureDirectoryExists($process->path.'/node_modules');

            return Process::result();
        }),
        '*composer install*' => $once('composer', fn () => Process::result()),
        '*pnpm install --frozen-lockfile && pnpm run build*' => $once('build', function (PendingProcess $process) {
            File::ensureDirectoryExists($process->path.'/public/build');
            File::put($process->path.'/public/build/manifest.json', '{}');

            return Process::result();
        }),
        '*php artisan migrate --force*' => $once('migrations', fn () => Process::result()),
        "'cp' *" => Process::result(),
        '*' => Process::result(),
    ]);
    MainCheckout::forget();
    app()->forgetInstance(BootstrapStrategy::class);
    app()->forgetInstance(WorktreeManager::class);
    $this->path = fn (string $name) => $this->base.'/'.basename(base_path()).'-'.$name;
    // The commands that ran, in order, from the given position on.
    $this->ran = fn (int $from = 0) => array_slice(array_map(
        fn (array $pair) => is_array($pair[0]->command) ? implode(' ', $pair[0]->command) : (string) $pair[0]->command,
        (fn () => $this->recorded)->call(Process::getFacadeRoot()),
    ), $from);
    // The host install is exactly `pnpm install`; the build in the container also holds that text.
    $this->position = fn (array $ran, string $needle) => array_key_first(array_filter($ran, fn (string $command) => $needle === 'pnpm install' ? $command === $needle : str_contains($command, $needle)));
});

afterEach(function () {
    File::deleteDirectory($this->base);
    File::deleteDirectory(base_path('storage/app/worktrees'));
    File::delete(base_path('.env'));
});

test('the node_modules copy and the host node install run before the app container starts', function () {
    File::ensureDirectoryExists($this->main.'/node_modules');
    File::put($this->main.'/pnpm-lock.yaml', 'pnpm-a');
    $this->checkoutFiles = ['pnpm-lock.yaml' => 'pnpm-a'];

    $this->artisan('worktree:create', ['name' => 't9'])->assertSuccessful();

    $ran = ($this->ran)();
    $at = fn (string $needle) => ($this->position)($ran, $needle);
    expect($at($this->main.'/node_modules'))->not->toBeNull()
        ->and($at($this->main.'/node_modules'))->toBeLessThan($at('pnpm install'))
        ->and($at('pnpm install'))->toBeLessThan($at('docker compose up -d'))
        ->and($at('docker compose up -d'))->toBeLessThan($at('composer install'))
        ->and($at('composer install'))->toBeLessThan($at('pnpm run build'));
    $record = json_decode(File::get(($this->path)('t9').'/storage/worktree-bootstrap.json'), true);
    expect($record['completed'])->toBe(['config', 'database', 'node', 'environment', 'composer', 'build', 'migrations']);
});

test('the sail strategy installs node on the host before the containers start too', function () {
    config()->set('worktrees.bootstrap.strategy', 'sail');
    config()->set('worktrees.bootstrap.build_frontend', false);
    config()->set('worktrees.bootstrap.run_migrations', false);
    app()->forgetInstance(BootstrapStrategy::class);
    app()->forgetInstance(WorktreeManager::class);

    $this->artisan('worktree:create', ['name' => 't9'])->assertSuccessful();

    $ran = ($this->ran)();
    expect(($this->position)($ran, 'pnpm install'))->toBeLessThan(($this->position)($ran, 'docker compose up -d'));
});

test('the native strategy keeps composer before the node install', function () {
    config()->set('worktrees.bootstrap.strategy', 'native');
    config()->set('worktrees.bootstrap.build_frontend', false);
    config()->set('worktrees.bootstrap.run_migrations', false);
    app()->forgetInstance(BootstrapStrategy::class);
    app()->forgetInstance(WorktreeManager::class);

    $this->artisan('worktree:create', ['name' => 't9'])->assertSuccessful();

    $ran = ($this->ran)();
    expect(($this->position)($ran, 'composer install'))->toBeLessThan(($this->position)($ran, 'pnpm install'));
});

test('a setup that failed at a step resumes, and the host install never follows the container start', function (string $stage, string $failing, bool $nodeAgain) {
    $this->failOnce = [$failing];

    $this->artisan('worktree:create', ['name' => 't9'])->assertFailed();
    $record = json_decode(File::get(($this->path)('t9').'/storage/worktree-bootstrap.json'), true);
    expect($record['status'])->toBe('failed')->and($record['stage'])->toBe($stage);

    $from = count(($this->ran)());
    $this->artisan('worktree:bootstrap', ['name' => 't9', '--resume' => true])->assertSuccessful();

    $ran = ($this->ran)($from);
    $install = ($this->position)($ran, 'pnpm install');
    if ($nodeAgain) {
        expect($install)->not->toBeNull()->toBeLessThan(($this->position)($ran, 'docker compose up -d'));
    } else {
        expect($install)->toBeNull();
    }
    $record = json_decode(File::get(($this->path)('t9').'/storage/worktree-bootstrap.json'), true);
    expect($record['status'])->toBe('ready')
        ->and($record['completed'])->toEqualCanonicalizing(['config', 'database', 'node', 'environment', 'composer', 'build', 'migrations']);
})->with([
    'node' => ['node', 'node', true],
    'environment' => ['environment', 'up', false],
    'composer' => ['composer', 'composer', false],
    'build' => ['build', 'build', false],
    'migrations' => ['migrations', 'migrations', false],
]);

test('worktree:setup on a folder runs the host install before the container start', function () {
    $path = $this->base.'/frontier/t901';
    File::ensureDirectoryExists($path);
    $this->worktrees['lane-901'] = $path;

    $this->artisan('worktree:setup', ['path' => $path])->assertSuccessful();

    $ran = ($this->ran)();
    expect(($this->position)($ran, 'pnpm install'))->toBeLessThan(($this->position)($ran, 'docker compose up -d'));
});

test('a running app container that lost its node_modules volume is restarted before the next step', function () {
    $this->composeConfig = ['services' => ['laravel.test' => ['volumes' => [
        ['type' => 'bind', 'source' => '/src', 'target' => '/var/www/html'],
        ['type' => 'volume', 'source' => 'node_modules', 'target' => '/var/www/html/node_modules'],
    ]]]];
    $this->mounts = ['/var/www/html'];

    $this->artisan('worktree:create', ['name' => 't9'])->assertSuccessful();

    $ran = ($this->ran)();
    expect(($this->position)($ran, "docker compose restart 'laravel.test'"))->not->toBeNull()
        ->toBeLessThan(($this->position)($ran, 'composer install'));
});

test('a container with its volumes mounted is not restarted', function () {
    $this->composeConfig = ['services' => ['laravel.test' => ['volumes' => [
        ['type' => 'volume', 'source' => 'node_modules', 'target' => '/var/www/html/node_modules'],
        ['type' => 'volume', 'source' => 'cache', 'target' => '/var/www/html/my cache/'],
    ]]]];
    $this->mounts = ['/var/www/html', '/var/www/html/node_modules', '/var/www/html/my cache'];

    $this->artisan('worktree:create', ['name' => 't9'])->assertSuccessful();

    Process::assertNotRan(fn (PendingProcess $p) => str_contains((string) $p->command, 'restart'));
});

test('a volume that a restart does not mount again stops the setup with a message', function () {
    $this->composeConfig = ['services' => ['laravel.test' => ['volumes' => [
        ['type' => 'volume', 'source' => 'node_modules', 'target' => '/var/www/html/node_modules'],
    ]]]];
    $this->mounts = ['/'];
    $this->mountsAfterRestart = ['/'];

    $this->artisan('worktree:create', ['name' => 't9'])
        ->expectsOutputToContain('no volume at /var/www/html/node_modules after a restart')
        ->assertFailed();
    Process::assertNotRan(fn (PendingProcess $p) => str_contains((string) $p->command, 'pnpm run build'));
});

test('the printed URL expands references to other keys of the worktree .env', function () {
    File::put(base_path('.env'), "APP_NAME=App\nWORKTREE_HOST=app.test\nAPP_URL=https://\${WORKTREE_HOST}\nDB_DATABASE=app\nCOMPOSE_PROJECT_NAME=app\n");
    config()->set('app.url', '');
    config()->set('worktrees.env_overrides', ['COMPOSE_PROJECT_NAME' => 'app-{name}', 'WORKTREE_HOST' => 'app-{name}.test']);
    app()->forgetInstance(WorktreeManager::class);

    $this->artisan('worktree:create', ['name' => 't9'])
        ->expectsOutputToContain('https://app-t9.test')
        ->doesntExpectOutputToContain('${WORKTREE_HOST}')
        ->assertSuccessful();
});

test('the expanded value reads only the file, and a file dotenv cannot parse gives the value as written', function () {
    $env = $this->base.'/url.env';
    putenv('LW_TEST_HOST=from-process.test');
    try {
        File::put($env, "LW_TEST_HOST=from-file.test\nAPP_URL=\"https://\${LW_TEST_HOST}/x\"\nOTHER=\${MISSING_KEY}\n");
        expect(EnvFile::resolved($env, 'APP_URL'))->toBe('https://from-file.test/x')
            ->and(EnvFile::resolved($env, 'NOPE'))->toBeNull();

        File::put($env, "APP_URL=https://\${LW_TEST_HOST}\nBROKEN=\"unclosed\n");
        expect(EnvFile::resolved($env, 'APP_URL'))->toBe('https://${LW_TEST_HOST}');
    } finally {
        putenv('LW_TEST_HOST');
    }
});
