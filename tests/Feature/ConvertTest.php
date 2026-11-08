<?php

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Woda\Worktrees\Contracts\BootstrapStrategy;
use Woda\Worktrees\Contracts\TemplateRecipe;
use Woda\Worktrees\Shared\DatabaseProvisioner;
use Woda\Worktrees\Shared\DatabaseServer;
use Woda\Worktrees\Shared\MainCheckout;
use Woda\Worktrees\Shared\MariaDbServer;
use Woda\Worktrees\Shared\SailConverter;
use Woda\Worktrees\Shared\WorktreeState;
use Woda\Worktrees\Tests\Support\FakeDatabaseServer;
use Woda\Worktrees\Tests\Support\FakeTemplateRecipe;
use Woda\Worktrees\WorktreeManager;

/*
 * worktree:convert and worktree:volumes with a fake old Sail layout: old
 * containers by compose project label, an old database volume per worktree.
 * Docker and git are faked.
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
    $this->containers = [];
    $this->removed = [];
    $this->order = [];
    $this->countFails = false;
    $this->ups = [];
    $this->volumes = [];
    $this->volumeRemovals = [];
    $this->runningVolume = null;
    config()->set('worktrees.shared.convert.volume', '{compose_project}_mariadb');
    config()->set('worktrees.shared.convert.backup_volumes', '/_mariadb$/');
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
                array_keys(['master' => $this->main, ...$this->worktrees]),
                ['master' => $this->main, ...$this->worktrees],
            ))),
            "docker compose exec -T 'laravel.test' sh -c *echo*" => Process::result('ready'),
            'docker compose up -d' => function (PendingProcess $p) {
                $this->ups[] = $p->path;

                return $this->upFails-- > 0 ? Process::result(errorOutput: 'compose failed', exitCode: 1) : Process::result();
            },
            'git rev-parse --path-format=absolute --git-common-dir' => fn () => Process::result($this->commonDir),
            "'docker' 'ps' '-a' '--filter' *" => fn (PendingProcess $p) => Process::result(implode("\n", array_map(fn (string $name) => "{$name} {$name}",
                $this->containers[substr($p->command[4], strlen('label=com.docker.compose.project='))] ?? []))),
            "'docker' 'rm' '-f' 'worktree-convert-*" => fn () => ($this->tempRmFails ?? false) ? Process::result(errorOutput: 'device busy', exitCode: 1) : Process::result(),
            "'docker' 'container' 'inspect' 'worktree-convert-*" => fn () => ($this->tempRmFails ?? false) ? Process::result('[{}]') : Process::result(errorOutput: 'No such container', exitCode: 1),
            "'docker' 'rm' '-f' *" => function (PendingProcess $p) {
                $this->removed[] = implode(' ', $p->command);
                $this->order[] = 'remove old containers';

                return Process::result();
            },
            "'docker' 'volume' 'inspect' *" => fn (PendingProcess $p) => ($this->inspectBroken ?? false)
                ? Process::result(errorOutput: 'Cannot connect to the Docker daemon', exitCode: 1)
                : (array_key_exists(end($p->command), $this->volumes) ? Process::result('[{}]') : Process::result('[]', 'Error response from daemon: get '.end($p->command).': no such volume', 1)),
            "'docker' 'run' *" => function (PendingProcess $p) {
                $volume = $p->command[array_search('-v', $p->command, true) + 1];
                $this->runningVolume = explode(':', $volume)[0];
                $this->order[] = 'start temporary database';

                return Process::result('cid');
            },
            "'docker' 'exec' 'worktree-convert-*' 'mariadb' '-N' '-e' 'SELECT 1'" => Process::result('1'),
            "'docker' 'exec' 'worktree-convert-*' 'mariadb' '-N' '-e' 'SELECT COUNT*" => fn () => $this->countFails
                ? Process::result(errorOutput: 'server gone', exitCode: 1)
                : Process::result((string) count($this->volumes[$this->runningVolume])),
            'docker exec *mariadb-dump*' => function (PendingProcess $p) {
                preg_match("/> '([^']+)'$/", (string) $p->command, $m);
                File::put($m[1], implode(',', $this->volumes[$this->runningVolume]));

                return Process::result();
            },
            "'docker' 'volume' 'rm' *" => function (PendingProcess $p) {
                $this->volumeRemovals[] = implode(' ', $p->command);

                return Process::result();
            },
            'docker system df -v --format json' => fn () => Process::result(json_encode(['Volumes' => array_map(
                fn (string $name) => ['Name' => $name, 'Size' => '12.5MB'], array_keys($this->volumes))])),
            'git log -1 --format=%cs' => Process::result('2026-11-01'),
            "'git' 'merge-base' '--is-ancestor' 'merged-branch' 'master'" => Process::result(),
            "'git' 'merge-base' *" => Process::result(exitCode: 1),
            "'cp' *" => fn (PendingProcess $process) => $this->cpResult,
            'docker compose -f shared.yml up -d' => function () {
                $this->server->up = true;

                return Process::result();
            },
            'docker compose --profile "*" down*' => fn () => ($this->downFails ?? false) ? Process::result(errorOutput: 'down failed', exitCode: 1) : Process::result(),
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

function sailWorktree(object $test, string $name, string $branch, array $tables = ['users']): string
{
    $path = $test->base.'/'.basename(base_path()).'-'.$name;
    File::ensureDirectoryExists($path);
    File::put($path.'/.env', "APP_NAME=App\nCOMPOSE_PROJECT_NAME=hungryport-{$name}\nDB_HOST=mariadb\nDB_DATABASE=hungryport\nDB_USERNAME=sail\nDB_PASSWORD=password\n");
    $test->worktrees[$branch] = $path;
    $test->containers["hungryport-{$name}"] = ["{$name}-app", "{$name}-db"];
    if ($tables !== []) {
        $test->volumes["hungryport-{$name}_mariadb"] = $tables;
    }

    return $path;
}

beforeEach(function () {
    File::put($this->main.'/.env', "APP_NAME=App\nCOMPOSE_PROJECT_NAME=hungryport\nDB_DATABASE=hungryport\n");
    $this->containers['hungryport'] = ['main-app'];
});

test('converting moves the old data into the new app database and keeps the old volume', function () {
    $path = sailWorktree($this, 'old1', 'feature-old1', ['users', 'orders']);

    $this->artisan('worktree:convert', ['--all' => true])->assertSuccessful();

    $state = WorktreeState::load($path);
    expect($this->server->databases['hp_old1'])->toBe(['imported: users,orders'])
        ->and($this->server->databases['hp_old1_e2e'])->toBe(['migrated', 'seeded'])
        ->and($state?->string('source'))->toBe('converted')
        ->and($state?->data['converted_at'] ?? null)->not->toBeNull()
        ->and($state?->string('old_volume'))->toBe('hungryport-old1_mariadb')
        ->and(File::get($path.'/.env'))->toContain('DB_DATABASE=hp_old1')->toContain('DB_USERNAME=hp_old1')
        ->and($this->volumeRemovals)->toBe([]);
    expect(collect($this->removed)->contains(fn ($c) => str_contains($c, 'old1-app old1-db')))->toBeTrue();
    Process::assertRan(fn (PendingProcess $p) => $p->command === 'docker compose up -d' && $p->path === $path);
});

test('the old containers are removed before the temporary database reads the volume', function () {
    sailWorktree($this, 'old1', 'feature-old1');

    $this->artisan('worktree:convert', ['name' => 'old1'])->assertSuccessful();

    expect($this->order)->toBe(['remove old containers', 'start temporary database']);
});

test('the list shows branch, last commit date and merged state; --all asks nothing', function () {
    sailWorktree($this, 'old1', 'merged-branch');
    sailWorktree($this, 'old2', 'open-branch');

    $this->artisan('worktree:convert', ['--all' => true])
        ->expectsTable(['Worktree', 'Branch', 'Last commit', 'Merged', 'Status'], [
            ['main', 'master', '2026-11-01', '', 'to convert'],
            ['old1', 'merged-branch', '2026-11-01', 'yes', 'to convert'],
            ['old2', 'open-branch', '2026-11-01', 'no', 'to convert'],
        ])
        ->assertSuccessful();
});

test('without --all each worktree is asked; a merged one suggests delete', function () {
    sailWorktree($this, 'old1', 'merged-branch');

    $this->artisan('worktree:convert')
        ->expectsChoice('main (master, 2026-11-01)', 'skip', ['convert' => 'Convert', 'skip' => 'Skip'])
        ->expectsChoice('old1 (merged-branch, 2026-11-01): merged, delete suggested', 'skip', ['convert' => 'Convert', 'delete' => 'Delete the worktree', 'skip' => 'Skip'])
        ->assertSuccessful();

    expect($this->server->databaseExists('hp_old1'))->toBeFalse();
});

test('an empty old volume leads to a template copy and a message', function () {
    $path = sailWorktree($this, 'old1', 'feature-old1');
    $this->volumes['hungryport-old1_mariadb'] = [];

    $this->artisan('worktree:convert', ['name' => 'old1'])
        ->expectsOutputToContain('holds no data: the worktree starts from the template')
        ->assertSuccessful();

    expect($this->server->databases['hp_old1'])->toBe(['migrated', 'seeded'])
        ->and(WorktreeState::load($path)?->string('source'))->toBe('template');
});

test('a missing old volume leads to a template copy and a message', function () {
    sailWorktree($this, 'old1', 'feature-old1', []);

    $this->artisan('worktree:convert', ['name' => 'old1'])
        ->expectsOutputToContain("No old volume 'hungryport-old1_mariadb'")
        ->assertSuccessful();

    expect($this->server->databases['hp_old1'])->toBe(['migrated', 'seeded']);
});

test('the main checkout converts first, into the main database', function () {
    sailWorktree($this, 'old1', 'feature-old1');
    unset($this->server->databases['hungryport']);
    $this->volumes['hungryport_mariadb'] = ['main_users'];

    $this->artisan('worktree:convert', ['--all' => true])->assertSuccessful();

    $state = WorktreeState::load($this->main);
    expect($state?->string('role'))->toBe('main')
        ->and($this->server->databases['hungryport'])->toBe(['imported: main_users'])
        ->and($this->server->users['hungryport']['databases'])->toContain('hungryport_test_test_2')
        ->and($state?->redis())->toBe([0, 1])
        ->and(File::get($this->main.'/.env'))->toContain('DB_USERNAME=hungryport');
    $imports = array_values(array_filter($this->server->log, fn ($l) => str_starts_with($l, 'import ')));
    expect($imports)->toBe(['import hungryport as hungryport', 'import hp_old1 as hp_old1']);
});

test('running convert again skips converted worktrees', function () {
    sailWorktree($this, 'old1', 'feature-old1');
    $this->artisan('worktree:convert', ['--all' => true])->assertSuccessful();
    $log = $this->server->log;

    $this->artisan('worktree:convert', ['--all' => true])
        ->expectsOutputToContain('Nothing to convert')
        ->assertSuccessful();

    expect($this->server->log)->toBe($log);
});

test('a convert that stopped is completed with the recorded old names', function () {
    $path = sailWorktree($this, 'old1', 'feature-old1');
    $this->upFails = 1;
    $this->artisan('worktree:convert', ['name' => 'old1'])->assertFailed();
    expect(File::get($path.'/.env'))->toContain('DB_DATABASE=hp_old1');

    $this->artisan('worktree:convert', ['name' => 'old1'])->assertSuccessful();

    expect($this->server->databases['hp_old1'])->toBe(['imported: users'])
        ->and(WorktreeState::load($path)?->data)->not->toHaveKey('converting');
});

test('the backup list shows each old volume with its size; only named volumes are removed', function () {
    sailWorktree($this, 'old1', 'feature-old1');
    $this->volumes['unrelated-volume'] = [];

    $this->artisan('worktree:volumes')
        ->expectsTable(['Volume', 'Size'], [['hungryport-old1_mariadb', '12.5MB']])
        ->assertSuccessful();

    $this->artisan('worktree:volumes', ['--remove' => ['unrelated-volume']])->assertFailed();
    expect($this->volumeRemovals)->toBe([]);

    $this->artisan('worktree:volumes', ['--remove' => ['hungryport-old1_mariadb']])->assertSuccessful();
    expect($this->volumeRemovals)->toHaveCount(1);
});

test('the main checkout gets the shared layout and a running app container before its template build', function () {
    config()->set('worktrees.shared.app.compose_file', 'compose.worktree.yml');
    app()->forgetInstance(BootstrapStrategy::class);
    unset($this->server->databases['hungryport']);
    $test = $this;
    app()->instance(TemplateRecipe::class, new class($test) implements TemplateRecipe
    {
        public function __construct(private readonly object $test) {}

        public function build(string $database, string $user, string $password, string $seedCommand, ?Closure $output = null): void
        {
            if (! in_array($this->test->main, $this->test->ups, true)) {
                throw new RuntimeException('The main app container is not running.');
            }
            $this->test->server->databases[$database] = ['migrated', 'seeded'];
        }
    });

    $this->artisan('worktree:convert', ['name' => 'main'])->assertSuccessful();

    expect(File::get($this->main.'/.env'))->toContain('COMPOSE_FILE=compose.worktree.yml');
    Process::assertRan(fn (PendingProcess $p) => $p->command === 'docker compose up -d --force-recreate' && $p->path === $this->main);
});

test('a failed read of the old database stops the conversion instead of using the template', function () {
    $path = sailWorktree($this, 'old1', 'feature-old1');
    $this->countFails = true;

    $this->artisan('worktree:convert', ['name' => 'old1'])
        ->expectsOutputToContain('Reading the old database from volume')
        ->assertFailed();

    expect($this->server->databaseExists('hp_old1'))->toBeFalse()
        ->and(SailConverter::isConverted($path))->toBeFalse();

    $this->countFails = false;
    $this->artisan('worktree:convert', ['name' => 'old1'])->assertSuccessful();
    expect($this->server->databases['hp_old1'])->toBe(['imported: users']);
});

test('delete is offered only for a worktree whose name leads to its own folder', function () {
    sailWorktree($this, 'old1', 'merged-branch');
    $outside = $this->base.'/elsewhere/old1';
    File::ensureDirectoryExists($outside);
    File::put($outside.'/.env', "COMPOSE_PROJECT_NAME=other-old1\nDB_DATABASE=hungryport\n");
    $this->worktrees['merged-branch-2'] = $outside;

    $this->artisan('worktree:convert')
        ->expectsChoice('main (master, 2026-11-01)', 'skip', ['convert' => 'Convert', 'skip' => 'Skip'])
        ->expectsChoice('old1 (merged-branch, 2026-11-01): merged, delete suggested', 'skip', ['convert' => 'Convert', 'delete' => 'Delete the worktree', 'skip' => 'Skip'])
        ->expectsChoice('old1 (merged-branch-2, 2026-11-01)', 'skip', ['convert' => 'Convert', 'skip' => 'Skip'])
        ->assertSuccessful();
});

test('convert refuses to change anything without the shared strategy', function () {
    sailWorktree($this, 'old1', 'feature-old1');
    config()->set('worktrees.bootstrap.strategy', 'sail');
    app()->forgetInstance(BootstrapStrategy::class);
    app()->forgetInstance(WorktreeManager::class);

    $this->artisan('worktree:convert', ['name' => 'old1'])
        ->expectsOutputToContain('needs the shared bootstrap strategy')
        ->assertFailed();

    expect($this->removed)->toBe([]);
});

test('an old database name with a dash is converted', function () {
    $path = sailWorktree($this, 'old1', 'feature-old1');
    File::put($path.'/.env', str_replace('DB_DATABASE=hungryport', 'DB_DATABASE=my-app', File::get($path.'/.env')));

    $this->artisan('worktree:convert', ['name' => 'old1'])->assertSuccessful();

    expect($this->server->databases['hp_old1'])->toBe(['imported: users']);
});

test('an old volume without a usable database name stops before anything is removed', function () {
    $path = sailWorktree($this, 'old1', 'feature-old1');
    File::put($path.'/.env', "COMPOSE_PROJECT_NAME=hungryport-old1\nDB_DATABASE=bad'name\n");

    $this->artisan('worktree:convert', ['name' => 'old1'])
        ->expectsOutputToContain('unsupported database name')
        ->assertFailed();

    expect($this->removed)->toBe([])
        ->and($this->server->databaseExists('hp_old1'))->toBeFalse();
});

test('a worktree set up with shared services before conversion still converts while its old volume exists', function () {
    $path = sailWorktree($this, 'old1', 'feature-old1');
    (new WorktreeState(['source' => 'template', 'name' => 'old1']))->save($path);

    $this->artisan('worktree:convert', ['name' => 'old1'])->assertSuccessful();

    expect($this->server->databases['hp_old1'])->toBe(['imported: users'])
        ->and(SailConverter::isConverted($path))->toBeTrue();
});

test('a shared worktree without an old volume counts as converted', function () {
    $path = sailWorktree($this, 'new1', 'feature-new1', []);
    (new WorktreeState(['source' => 'template', 'name' => 'new1']))->save($path);
    $this->containers['hungryport'] = [];
    (new WorktreeState(['converted_at' => '2026-11-08T00:00:00+00:00']))->save($this->main);

    $this->artisan('worktree:convert', ['--all' => true])
        ->expectsOutputToContain('Nothing to convert')
        ->assertSuccessful();
});

test('a name that several worktrees share is refused; a path picks one', function () {
    sailWorktree($this, 'old1', 'feature-old1');
    $outside = $this->base.'/elsewhere/old1';
    File::ensureDirectoryExists($outside);
    File::put($outside.'/.env', "COMPOSE_PROJECT_NAME=other-old1\nDB_DATABASE=hungryport\n");
    $this->worktrees['feature-old1-b'] = $outside;

    $this->artisan('worktree:convert', ['name' => 'old1'])
        ->expectsOutputToContain('Several worktrees')
        ->assertFailed();
    expect($this->server->databaseExists('hp_old1'))->toBeFalse();

    // A path picks one. The outside folder would get the same name, and with it the
    // same databases, as the worktree in the base path: it is refused.
    $this->artisan('worktree:convert', ['name' => $outside])
        ->expectsOutputToContain('already uses the name')
        ->assertFailed();
    expect(implode(' ', $this->removed))->not->toContain('old1');
    $this->artisan('worktree:convert', ['name' => ($this->path)('old1')])->assertSuccessful();
    expect(SailConverter::isConverted(($this->path)('old1')))->toBeTrue()
        ->and(SailConverter::isConverted($outside))->toBeFalse();
});

test('a failed delete makes convert fail', function () {
    sailWorktree($this, 'old1', 'merged-branch');
    $this->downFails = true;

    $this->artisan('worktree:convert')
        ->expectsChoice('main (master, 2026-11-01)', 'skip', ['convert' => 'Convert', 'skip' => 'Skip'])
        ->expectsChoice('old1 (merged-branch, 2026-11-01): merged, delete suggested', 'delete', ['convert' => 'Convert', 'delete' => 'Delete the worktree', 'skip' => 'Skip'])
        ->expectsConfirmation("Delete worktree 'old1'?", 'yes')
        ->assertFailed();
});

test('an existing shared database stops the conversion when the old volume holds data', function () {
    $path = sailWorktree($this, 'old1', 'feature-old1');
    (new WorktreeState(['source' => 'template', 'name' => 'old1']))->save($path);
    $this->server->databases['hp_old1'] = ['template data'];

    $this->artisan('worktree:convert', ['name' => 'old1'])
        ->expectsOutputToContain('exists already')
        ->assertFailed();

    expect($this->server->databases['hp_old1'])->toBe(['template data'])
        ->and(SailConverter::isConverted($path))->toBeFalse();

    unset($this->server->databases['hp_old1']);
    $this->artisan('worktree:convert', ['name' => 'old1'])->assertSuccessful();
    expect($this->server->databases['hp_old1'])->toBe(['imported: users']);
});

test('a failed volume inspect stops convert instead of reading as no volume', function () {
    $path = sailWorktree($this, 'old1', 'feature-old1');
    (new WorktreeState(['source' => 'template', 'name' => 'old1']))->save($path);
    $this->inspectBroken = true;

    $this->artisan('worktree:convert', ['--all' => true])
        ->expectsOutputToContain('Cannot inspect')
        ->assertFailed();
});

test('a worktree whose .env already names its shared database is not converted with guessed old names', function () {
    $path = sailWorktree($this, 'old1', 'feature-old1');
    File::put($path.'/.env', "APP_NAME=App\nCOMPOSE_PROJECT_NAME=hungryport-old1\nDB_DATABASE=hp_old1\n");

    $this->artisan('worktree:convert', ['name' => 'old1'])
        ->expectsOutputToContain('old Sail database name is unknown')
        ->assertFailed();

    expect($this->removed)->toBe([]);
});

test('the main conversion keeps the shared database container', function () {
    config()->set('worktrees.shared.database.container', 'main-db');
    app()->forgetInstance(SailConverter::class);
    $this->containers['hungryport'] = ['main-app', 'main-db'];

    $this->artisan('worktree:convert', ['name' => 'main'])->assertSuccessful();

    expect(implode(' ', $this->removed))->toContain('main-app')->not->toContain('main-db');
});

test('the temporary database runs without a network', function () {
    sailWorktree($this, 'old1', 'feature-old1');

    $this->artisan('worktree:convert', ['name' => 'old1'])->assertSuccessful();

    Process::assertRan(fn (PendingProcess $p) => is_array($p->command) && $p->command[1] === 'run' && in_array('--network', $p->command, true)
        && $p->command[array_search('--network', $p->command, true) + 1] === 'none');
});

test('a retry after a stopped import keeps going: the target is its own', function (Closure $mark) {
    $path = sailWorktree($this, 'old1', 'feature-old1');
    $this->server->databases['hp_old1'] = ['imported: users'];
    $mark($path);

    $this->artisan('worktree:convert', ['name' => 'old1'])->assertSuccessful();

    expect(SailConverter::isConverted($path))->toBeTrue();
})->with([
    'import marker' => [function (string $path) {
        File::ensureDirectoryExists($path.'/storage/app/worktree');
        File::put($path.'/storage/app/worktree/convert-imported', 'hp_old1');
    }],
    'unfinished copy' => [function (string $path) {
        (new WorktreeState(['pending_copies' => ['hp_old1']]))->save($path);
    }],
]);

test('the shared stack is started before the target check', function () {
    config()->set('worktrees.shared.stack.start_command', 'docker compose -f shared.yml up -d');
    app()->forgetInstance(SailConverter::class);
    sailWorktree($this, 'old1', 'feature-old1');
    $this->server->up = false;

    $this->artisan('worktree:convert', ['name' => 'old1'])->assertSuccessful();

    expect($this->server->databases['hp_old1'])->toBe(['imported: users']);
});

test('the recorded old project decides whether an old volume still waits', function () {
    $path = sailWorktree($this, 'old1', 'feature-old1');
    File::put($path.'/.env', "APP_NAME=App\nCOMPOSE_PROJECT_NAME=app-old1\nDB_DATABASE=hp_old1\n");
    (new WorktreeState(['source' => 'template', 'name' => 'old1', 'old_project' => 'hungryport-old1', 'old_database' => 'hungryport']))->save($path);

    $this->artisan('worktree:convert', ['name' => 'old1'])->assertSuccessful();

    expect($this->server->databases['hp_old1'])->toBe(['imported: users']);
});

test('a temporary database that cannot be removed fails the conversion', function () {
    sailWorktree($this, 'old1', 'feature-old1');
    $this->tempRmFails = true;

    $this->artisan('worktree:convert', ['name' => 'old1'])
        ->expectsOutputToContain('Removing the temporary database')
        ->assertFailed();

    expect($this->server->databaseExists('hp_old1'))->toBeFalse();
});

test('after the main checkout\'s old containers are removed, the shared stack is started again', function () {
    config()->set('worktrees.shared.stack.start_command', 'docker compose -f shared.yml up -d');
    app()->forgetInstance(SailConverter::class);
    app()->forgetInstance(DatabaseProvisioner::class);

    $this->artisan('worktree:convert', ['name' => 'main'])->assertSuccessful();

    Process::assertRan('docker compose -f shared.yml up -d');
});

test('the definer rewrite of an import keeps the dump private', function () {
    Process::fake(['*' => Process::result()]);
    $server = new MariaDbServer('mariadb', null, '127.0.0.1', 3306, 'root', '');

    $server->importFile('hp_t9', '/tmp/dump.sql', owner: 'hp_t9');

    Process::assertRan(fn (PendingProcess $p) => str_starts_with((string) $p->command, 'umask 077 && sed -E'));
});

test('a worktree with the main checkout\'s compose project is not converted', function () {
    $path = sailWorktree($this, 'old1', 'feature-old1');
    File::put($path.'/.env', "APP_NAME=App\nCOMPOSE_PROJECT_NAME=hungryport\nDB_DATABASE=hungryport\n");

    $this->artisan('worktree:convert', ['name' => 'old1'])
        ->expectsOutputToContain('compose project of the main checkout')
        ->assertFailed();

    expect($this->removed)->toBe([]);
});
