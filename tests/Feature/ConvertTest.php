<?php

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Woda\Worktrees\Contracts\TemplateRecipe;
use Woda\Worktrees\Shared\DatabaseServer;
use Woda\Worktrees\Shared\MainCheckout;
use Woda\Worktrees\Shared\WorktreeState;
use Woda\Worktrees\Tests\Support\FakeDatabaseServer;
use Woda\Worktrees\Tests\Support\FakeTemplateRecipe;

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
            'docker compose up -d' => fn () => $this->upFails-- > 0 ? Process::result(errorOutput: 'compose failed', exitCode: 1) : Process::result(),
            'git rev-parse --path-format=absolute --git-common-dir' => fn () => Process::result($this->commonDir),
            "'docker' 'ps' '-aq' *" => fn (PendingProcess $p) => Process::result(implode("\n", $this->containers[substr(end($p->command), strlen('label=com.docker.compose.project='))] ?? [])),
            "'docker' 'rm' '-f' 'worktree-convert-*" => Process::result(),
            "'docker' 'rm' '-f' *" => function (PendingProcess $p) {
                $this->removed[] = implode(' ', $p->command);
                $this->order[] = 'remove old containers';

                return Process::result();
            },
            "'docker' 'volume' 'inspect' *" => fn (PendingProcess $p) => Process::result(exitCode: array_key_exists(end($p->command), $this->volumes) ? 0 : 1),
            "'docker' 'run' *" => function (PendingProcess $p) {
                $volume = $p->command[array_search('-v', $p->command, true) + 1];
                $this->runningVolume = explode(':', $volume)[0];
                $this->order[] = 'start temporary database';

                return Process::result('cid');
            },
            "'docker' 'exec' 'worktree-convert-*' 'mariadb' '-N' '-e' 'SELECT 1'" => Process::result('1'),
            "'docker' 'exec' 'worktree-convert-*' 'mariadb' '-N' '-e' 'SELECT COUNT*" => fn () => Process::result((string) count($this->volumes[$this->runningVolume])),
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
    expect($imports)->toBe(['import hungryport', 'import hp_old1']);
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
