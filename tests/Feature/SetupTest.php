<?php

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Woda\Worktrees\Contracts\BootstrapStrategy;
use Woda\Worktrees\Contracts\TemplateRecipe;
use Woda\Worktrees\DatabaseCloner;
use Woda\Worktrees\Shared\DatabaseServer;
use Woda\Worktrees\Shared\MainCheckout;
use Woda\Worktrees\Shared\WorktreeState;
use Woda\Worktrees\Tests\Support\FakeDatabaseServer;
use Woda\Worktrees\Tests\Support\FakeTemplateRecipe;
use Woda\Worktrees\WorktreeManager;

/*
 * worktree:setup for a folder that another tool made, repeated runs of setup
 * and create, the queue worker switch and the setup log. Docker and git are faked.
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
    $this->upFails = 0;
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
            'docker compose up -d' => fn () => $this->upFails-- > 0 ? Process::result(errorOutput: 'compose failed', exitCode: 1) : Process::result(),
            'git rev-parse --path-format=absolute --git-common-dir' => fn () => Process::result($this->commonDir),
            "'cp' *" => fn (PendingProcess $process) => $this->cpResult,
            'docker compose -f shared.yml up -d' => function () {
                $this->server->up = true;

                return Process::result();
            },
            'docker compose config --format json' => Process::result(),
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

function frontierWorktree(object $test, string $folder, string $branch): string
{
    $path = $test->base.'/frontier/'.$folder;
    File::ensureDirectoryExists($path);
    $test->worktrees[$branch] = $path;

    return $path;
}

test('setup of a folder outside the base path gives the same result as create', function () {
    $this->artisan('worktree:create', ['name' => 'a1'])->assertSuccessful();
    $path = frontierWorktree($this, 't901', 'lane-901');

    $this->artisan('worktree:setup', ['path' => $path])->assertSuccessful();

    $created = WorktreeState::load(($this->path)('a1'));
    $setUp = WorktreeState::load($path);
    $keys = fn (string $env) => array_map(fn ($line) => explode('=', $line)[0], array_filter(explode("\n", $env)));
    expect(array_keys($setUp->data))->toEqualCanonicalizing(array_keys($created->data))
        ->and($setUp->string('name'))->toBe('t901')
        ->and($setUp->string('branch'))->toBe('lane-901')
        ->and($keys(File::get($path.'/.env')))->toEqualCanonicalizing($keys(File::get(($this->path)('a1').'/.env')))
        ->and($this->server->databases)->toHaveKeys(['hp_t901', 'hp_t901_test', 'hp_t901_e2e'])
        ->and($this->server->users['hp_t901']['databases'])->toContain('hp_t901_test_test_2');
    Process::assertRan(fn (PendingProcess $p) => $p->command === 'docker compose up -d' && $p->path === $path);
});

test('setup takes the name from --name', function () {
    $path = frontierWorktree($this, 'some-folder', 'lane');

    $this->artisan('worktree:setup', ['path' => $path, '--name' => 't902'])->assertSuccessful();

    expect(WorktreeState::load($path)?->string('name'))->toBe('t902');
});

test('setup refuses a folder that git does not register', function () {
    $path = $this->base.'/frontier/stray';
    File::ensureDirectoryExists($path);

    $this->artisan('worktree:setup', ['path' => $path])
        ->expectsOutputToContain('not a registered worktree')
        ->assertFailed();
});

test('a setup stopped after the database step completes without copying again', function () {
    $path = frontierWorktree($this, 't903', 'lane');
    $this->upFails = 1;

    $this->artisan('worktree:setup', ['path' => $path])->assertFailed();
    $copies = array_filter($this->server->log, fn ($line) => str_starts_with($line, 'copy '));
    expect($copies)->toHaveCount(3);

    $this->artisan('worktree:setup', ['path' => $path])->assertSuccessful();
    expect(array_filter($this->server->log, fn ($line) => str_starts_with($line, 'copy ')))->toHaveCount(3)
        ->and(json_decode(File::get($path.'/storage/worktree-bootstrap.json'), true)['status'])->toBe('ready');
});

test('a second run on a complete worktree changes nothing', function () {
    $path = frontierWorktree($this, 't904', 'lane');
    $this->artisan('worktree:setup', ['path' => $path])->assertSuccessful();
    $env = File::get($path.'/.env');
    $state = File::get(WorktreeState::path($path));
    $log = $this->server->log;

    $this->artisan('worktree:setup', ['path' => $path])->assertSuccessful();

    expect(File::get($path.'/.env'))->toBe($env)
        ->and(File::get(WorktreeState::path($path)))->toBe($state)
        ->and($this->server->log)->toBe($log);
});

test('create run again completes the worktree instead of failing', function () {
    $this->upFails = 1;
    $this->artisan('worktree:create', ['name' => 't9'])->assertFailed();

    $this->artisan('worktree:create', ['name' => 't9'])
        ->expectsOutputToContain('completing its setup')
        ->assertSuccessful();

    expect(array_filter($this->server->log, fn ($line) => str_starts_with($line, 'copy ')))->toHaveCount(3);
});

test('a setup keeps a .env that the folder already has', function () {
    $path = frontierWorktree($this, 't905', 'lane');
    File::put($path.'/.env', "APP_NAME=Lane\nLANE_SETTING=keep\n");

    $this->artisan('worktree:setup', ['path' => $path])->assertSuccessful();

    expect(File::get($path.'/.env'))->toContain('LANE_SETTING=keep')->toContain('DB_DATABASE=hp_t905');
});

test('the worker switch changes the mode and restarts the worker', function () {
    $this->artisan('worktree:create', ['name' => 't9'])->assertSuccessful();

    $this->artisan('worktree:worker', ['worktree' => 't9', 'mode' => 'horizon'])->assertSuccessful();

    expect(File::get(($this->path)('t9').'/.env'))->toContain('WORKTREE_QUEUE_WORKER=horizon');
    Process::assertRan(fn (PendingProcess $p) => $p->command === "docker compose up -d --force-recreate --no-deps 'laravel.test'" && $p->path === ($this->path)('t9'));

    $this->artisan('worktree:worker', ['worktree' => 't9'])->expectsOutput('horizon')->assertSuccessful();
    $this->artisan('worktree:worker', ['worktree' => 't9', 'mode' => 'supervisor'])->assertFailed();
});

test('the worker switch refuses the main checkout', function () {
    $this->artisan('worktree:worker', ['worktree' => base_path(), 'mode' => 'horizon'])
        ->expectsOutputToContain('main checkout')
        ->assertFailed();

    expect(File::get(base_path('.env')))->not->toContain('WORKTREE_QUEUE_WORKER');
    Process::assertNotRan(fn (PendingProcess $p) => str_contains((string) $p->command, '--force-recreate'));
});

test('every step is logged to a file in the worktree', function () {
    $path = frontierWorktree($this, 't906', 'lane');

    $this->artisan('worktree:setup', ['path' => $path])->assertSuccessful();

    expect(File::get($path.'/storage/logs/worktree-setup.log'))
        ->toContain("Setup of 't906'")
        ->toContain('database done in')
        ->toContain('Ready.');
});

test('setup refuses the main checkout', function () {
    $this->worktrees = [];
    $env = File::get(base_path('.env'));

    $this->artisan('worktree:setup', ['path' => base_path()])
        ->expectsOutputToContain('This is the main checkout')
        ->assertFailed();

    expect(File::get(base_path('.env')))->toBe($env);
});

test('setup with the native strategy keeps an existing SQLite database', function () {
    config()->set('worktrees.bootstrap.strategy', 'native');
    config()->set('worktrees.database.strategy', 'sqlite');
    app()->forgetInstance(BootstrapStrategy::class);
    app()->forgetInstance(DatabaseCloner::class);
    app()->forgetInstance(WorktreeManager::class);
    File::ensureDirectoryExists(base_path('database'));
    File::put(base_path('database/database.sqlite'), 'main data');
    $path = frontierWorktree($this, 'native-lane', 'lane');
    File::ensureDirectoryExists($path.'/database');
    File::put($path.'/database/database.sqlite', 'lane data');

    File::put(base_path('.env'), File::get(base_path('.env'))."DB_CONNECTION=sqlite\nDB_DATABASE=".base_path('database/database.sqlite')."\n");

    try {
        $this->artisan('worktree:setup', ['path' => $path])->assertSuccessful();
        expect(File::get($path.'/database/database.sqlite'))->toBe('lane data')
            ->and(File::get($path.'/.env'))->toContain('DB_DATABASE='.realpath($path.'/database/database.sqlite'))
            ->and(File::get($path.'/.env'))->not->toContain('DB_DATABASE='.base_path('database/database.sqlite'));
    } finally {
        File::delete(base_path('database/database.sqlite'));
    }
});

test('a legacy clone that this setup started and did not finish is cloned again', function () {
    config()->set('worktrees.bootstrap.strategy', 'native');
    config()->set('worktrees.database.strategy', 'sqlite');
    app()->forgetInstance(BootstrapStrategy::class);
    app()->forgetInstance(DatabaseCloner::class);
    app()->forgetInstance(WorktreeManager::class);
    File::ensureDirectoryExists(base_path('database'));
    File::put(base_path('database/database.sqlite'), 'main data');
    $path = frontierWorktree($this, 'native-lane', 'lane');
    File::ensureDirectoryExists($path.'/database');
    File::put($path.'/database/database.sqlite', 'half');
    File::ensureDirectoryExists($path.'/storage');
    File::put($path.'/storage/worktree-bootstrap.json', json_encode(['completed' => ['config', 'environment', 'composer', 'node'], 'stage' => 'database', 'status' => 'failed', 'branch' => 'lane']));
    File::put($path.'/storage/worktree-bootstrap.clone', 'started');
    File::put($path.'/.env', "APP_NAME=Lane\n");

    try {
        $this->artisan('worktree:setup', ['path' => $path])->assertSuccessful();
        expect(File::get($path.'/database/database.sqlite'))->toBe('main data');
    } finally {
        File::delete(base_path('database/database.sqlite'));
    }
});

test('a database stage that stopped before its clone started keeps the existing database', function () {
    config()->set('worktrees.bootstrap.strategy', 'native');
    config()->set('worktrees.database.strategy', 'sqlite');
    app()->forgetInstance(BootstrapStrategy::class);
    app()->forgetInstance(DatabaseCloner::class);
    app()->forgetInstance(WorktreeManager::class);
    File::ensureDirectoryExists(base_path('database'));
    File::put(base_path('database/database.sqlite'), 'main data');
    $path = frontierWorktree($this, 'native-lane', 'lane');
    File::ensureDirectoryExists($path.'/database');
    File::put($path.'/database/database.sqlite', 'lane data');
    File::ensureDirectoryExists($path.'/storage');
    // The record says the stage started (e.g. the existence check timed out), but no clone began.
    File::put($path.'/storage/worktree-bootstrap.json', json_encode(['completed' => ['config', 'environment', 'composer', 'node'], 'stage' => 'database', 'status' => 'failed', 'branch' => 'lane']));
    File::put($path.'/.env', "APP_NAME=Lane\n");

    try {
        $this->artisan('worktree:setup', ['path' => $path])->assertSuccessful();
        expect(File::get($path.'/database/database.sqlite'))->toBe('lane data');
    } finally {
        File::delete(base_path('database/database.sqlite'));
    }
});

test('setup refuses the main checkout before it writes its log there', function () {
    $this->worktrees = [];
    File::delete(base_path('storage/logs/worktree-setup.log'));

    $this->artisan('worktree:setup', ['path' => base_path()])->assertFailed();

    expect(File::exists(base_path('storage/logs/worktree-setup.log')))->toBeFalse();
});

test('the worker switch refuses a worktree with the main checkout\'s compose project', function () {
    $this->artisan('worktree:create', ['name' => 't9'])->assertSuccessful();
    $env = File::get(($this->path)('t9').'/.env');
    Process::fake(['docker compose config --format json' => Process::result(json_encode(['name' => 'app']))]);

    $this->artisan('worktree:worker', ['worktree' => 't9', 'mode' => 'horizon'])
        ->expectsOutputToContain('same compose project')
        ->assertFailed();

    expect(File::get(($this->path)('t9').'/.env'))->toBe($env);
    Process::assertNotRan(fn (PendingProcess $p) => str_contains((string) $p->command, '--force-recreate'));
});

test('a setup run again keeps the name of the first run', function () {
    $path = frontierWorktree($this, 't907', 'lane');
    $this->upFails = 1;
    $this->artisan('worktree:setup', ['path' => $path, '--name' => 'lane7'])->assertFailed();

    $this->artisan('worktree:setup', ['path' => $path])->assertSuccessful();
    expect(WorktreeState::load($path)?->string('name'))->toBe('lane7');

    $this->artisan('worktree:setup', ['path' => $path, '--name' => 'other'])
        ->expectsOutputToContain("'lane7'")
        ->assertFailed();
});

test('create run again after its .env was copied, but before the config stage was recorded, completes', function () {
    $this->upFails = 1;
    $this->artisan('worktree:create', ['name' => 't9'])->assertFailed();
    $record = ($this->path)('t9').'/storage/worktree-bootstrap.json';
    $state = json_decode(File::get($record), true);
    $state['completed'] = [];
    File::put($record, json_encode($state));

    $this->artisan('worktree:create', ['name' => 't9'])->assertSuccessful();
});
