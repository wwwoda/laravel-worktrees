<?php

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Woda\Worktrees\Contracts\BootstrapStrategy;
use Woda\Worktrees\Contracts\TemplateRecipe;
use Woda\Worktrees\Shared\DatabaseServer;
use Woda\Worktrees\Shared\MainCheckout;
use Woda\Worktrees\Shared\TemplateManager;
use Woda\Worktrees\Shared\WorktreeState;
use Woda\Worktrees\Tests\Support\FakeDatabaseServer;
use Woda\Worktrees\Tests\Support\FakeTemplateRecipe;
use Woda\Worktrees\WorktreeManager;

/*
 * worktree:delete with shared services: container first, then only the
 * worktree's own names, then the folder, with checks of the result.
 * Docker and git are faked; the database server is an in-memory fake.
 */
beforeEach(function () {
    $this->base = sys_get_temp_dir().'/worktrees-shared-'.uniqid();
    $this->migrations = 'database/lw-test-migrations-'.uniqid();
    File::ensureDirectoryExists($this->base);
    File::ensureDirectoryExists(base_path($this->migrations));
    File::put(base_path($this->migrations.'/2026_01_01_000000_create_items.php'), '<?php // v1');
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
    config()->set('worktrees.shared.database.template.hash_paths', [$this->migrations]);
    config()->set('worktrees.shared.env.DB_TEST_DATABASE', '{db_test}');

    $this->server = new FakeDatabaseServer;
    $this->server->databases['hungryport'] = ['production-like'];
    $this->recipe = new FakeTemplateRecipe($this->server);
    app()->instance(DatabaseServer::class, $this->server);
    app()->instance(TemplateRecipe::class, $this->recipe);

    $this->worktrees = [];
    $this->stuck = [];
    $this->atDown = null;
    $this->flushed = [];
    $this->branchExists = false;
    $this->branchMerged = false;
    $this->branchDeleted = false;
    config()->set('worktrees.shared.redis.container', 'shared-redis');
    $this->commonDir = '';
    $this->fakeProcesses = function () {
        Process::fake([
            'git show-ref *' => fn () => Process::result(exitCode: $this->branchExists ? 0 : 1),
            'git worktree add *' => function (PendingProcess $process) {
                preg_match("/worktree add -b '([^']+)' '([^']+)'/", (string) $process->command, $match);
                File::ensureDirectoryExists($match[2]);
                $this->worktrees[$match[1]] = $match[2];

                return Process::result();
            },
            'git worktree list --porcelain' => function () {
                $listed = array_filter($this->worktrees, fn (string $path) => is_dir($path) || in_array($path, $this->stuck, true));

                return Process::result(implode('', array_map(
                    fn (string $branch, string $path) => "worktree {$path}\nHEAD abc\nbranch refs/heads/{$branch}\n\n",
                    array_keys(['master' => base_path(), ...$listed]),
                    ['master' => base_path(), ...$listed],
                )));
            },
            'docker compose --profile "*" down -v' => function (PendingProcess $p) {
                $this->atDown = ['databases' => array_keys($this->server->databases), 'folder' => is_dir((string) $p->path)];

                return Process::result();
            },
            "'docker' 'exec' 'shared-redis' 'redis-cli' *" => function (PendingProcess $p) {
                $this->flushed[] = (int) $p->command[5];

                return Process::result('OK');
            },
            'git merge-base --is-ancestor *' => fn () => Process::result(exitCode: $this->branchMerged ? 0 : 1),
            'git branch -d *' => function () {
                $this->branchDeleted = true;

                return Process::result();
            },
            "docker compose exec -T 'laravel.test' sh -c *echo*" => Process::result('ready'),
            'git rev-parse --path-format=absolute --git-common-dir' => fn () => Process::result($this->commonDir),
            '*' => Process::result(),
        ]);
    };
    ($this->fakeProcesses)();
    MainCheckout::forget();
    $this->path = fn (string $name) => $this->base.'/'.basename(base_path()).'-'.$name;
});

afterEach(function () {
    File::deleteDirectory($this->base);
    File::deleteDirectory(base_path($this->migrations));
    File::deleteDirectory(base_path('storage/app/worktrees'));
    File::delete(base_path('.env'));
});

test('delete stops the container first, then drops only its own databases, user and Redis data', function () {
    $this->artisan('worktree:create', ['name' => 't9'])->assertSuccessful();
    $this->artisan('worktree:create', ['name' => 't9-1'])->assertSuccessful();
    $template = app(TemplateManager::class)->name();
    $redis = WorktreeState::load(($this->path)('t9'))?->redis();

    $this->artisan('worktree:delete', ['name' => 't9', '--force' => true])->assertSuccessful();

    expect($this->atDown['databases'])->toContain('hp_t9')
        ->and($this->atDown['folder'])->toBeTrue()
        ->and($this->server->databases)->not->toHaveKeys(['hp_t9', 'hp_t9_test', 'hp_t9_e2e'])
        ->and($this->server->databases)->toHaveKeys(['hp_t9_1', 'hp_t9_1_test', 'hp_t9_1_e2e', 'hungryport', $template])
        ->and($this->server->users)->not->toHaveKey('hp_t9')
        ->and($this->server->users)->toHaveKey('hp_t9_1')
        ->and($this->flushed)->toBe($redis)
        ->and(is_dir(($this->path)('t9')))->toBeFalse();
});

test('a state that names a database the worktree does not own stops the delete before any drop', function () {
    $this->artisan('worktree:create', ['name' => 't9'])->assertSuccessful();
    $state = WorktreeState::load(($this->path)('t9'));
    $state->data['databases']['e2e'] = 'hungryport';
    $state->save(($this->path)('t9'));

    $this->artisan('worktree:delete', ['name' => 't9', '--force' => true])
        ->expectsOutputToContain("Refusing to drop 'hungryport'")
        ->assertFailed();

    expect($this->server->databases)->toHaveKeys(['hungryport', 'hp_t9'])
        ->and(is_dir(($this->path)('t9')))->toBeTrue();
});

test('a delete whose folder removal fails exits non-zero and says so', function () {
    $this->artisan('worktree:create', ['name' => 't9'])->assertSuccessful();
    $locked = ($this->path)('t9').'/locked';
    File::ensureDirectoryExists($locked);
    File::put($locked.'/file', 'owned elsewhere');
    chmod($locked, 0500);

    try {
        $this->artisan('worktree:delete', ['name' => 't9', '--force' => true])
            ->expectsOutputToContain('could not be removed completely')
            ->doesntExpectOutputToContain("Worktree 't9' deleted.")
            ->assertFailed();
    } finally {
        chmod($locked, 0700);
    }
});

test('a worktree that git still lists after the removal fails the delete', function () {
    $this->artisan('worktree:create', ['name' => 't9'])->assertSuccessful();
    $this->stuck[] = ($this->path)('t9');

    $this->artisan('worktree:delete', ['name' => 't9', '--force' => true])
        ->expectsOutputToContain('git still lists the worktree')
        ->assertFailed();
});

test('a remaining merged branch is deleted on request; an unmerged one is reported', function () {
    $this->artisan('worktree:create', ['name' => 't9'])->assertSuccessful();
    $this->artisan('worktree:create', ['name' => 't8'])->assertSuccessful();
    $this->branchExists = true;

    $this->branchMerged = false;
    $this->artisan('worktree:delete', ['name' => 't8', '--force' => true])
        ->expectsOutputToContain("Branch 't8' remains; it is not merged")
        ->assertSuccessful();
    expect($this->branchDeleted)->toBeFalse();

    $this->branchMerged = true;
    $this->artisan('worktree:delete', ['name' => 't9', '--force' => true, '--delete-branch' => true])
        ->expectsOutputToContain("Deleted merged branch 't9'")
        ->assertSuccessful();
    expect($this->branchDeleted)->toBeTrue();
});

test('without a state file the shared databases are not guessed', function () {
    $this->artisan('worktree:create', ['name' => 't9'])->assertSuccessful();
    File::delete(WorktreeState::path(($this->path)('t9')));

    $this->artisan('worktree:delete', ['name' => 't9', '--force' => true])
        ->expectsOutputToContain('has no state file')
        ->assertFailed();

    expect($this->server->databases)->toHaveKey('hp_t9');
});

test('the Sail strategy still tears down with its own compose stack', function () {
    config()->set('worktrees.bootstrap.strategy', 'sail');
    app()->forgetInstance(BootstrapStrategy::class);
    app()->forgetInstance(WorktreeManager::class);
    $this->artisan('worktree:create', ['name' => 's1', '--skip-db' => true])->assertSuccessful();

    $this->artisan('worktree:delete', ['name' => 's1', '--force' => true])->assertSuccessful();

    expect($this->atDown['folder'])->toBeTrue()
        ->and(is_dir(($this->path)('s1')))->toBeFalse();
});

test('a state file of another worktree drops nothing', function () {
    $this->artisan('worktree:create', ['name' => 't8'])->assertSuccessful();
    $this->artisan('worktree:create', ['name' => 't9'])->assertSuccessful();
    File::copy(WorktreeState::path(($this->path)('t8')), WorktreeState::path(($this->path)('t9')));

    $this->artisan('worktree:delete', ['name' => 't9', '--force' => true])
        ->expectsOutputToContain("belongs to worktree 't8'")
        ->assertFailed();

    expect($this->server->databases)->toHaveKeys(['hp_t8', 'hp_t9'])
        ->and($this->flushed)->toBe([]);
});

test('Redis numbers that another worktree also claims are not flushed', function () {
    $this->artisan('worktree:create', ['name' => 't8'])->assertSuccessful();
    $this->artisan('worktree:create', ['name' => 't9'])->assertSuccessful();
    $state = WorktreeState::load(($this->path)('t9'));
    $state->data['redis'] = WorktreeState::load(($this->path)('t8'))?->redis();
    $state->save(($this->path)('t9'));

    $this->artisan('worktree:delete', ['name' => 't9', '--force' => true])
        ->expectsOutputToContain('also uses Redis databases')
        ->assertFailed();

    expect($this->flushed)->toBe([])
        ->and($this->server->databases)->toHaveKey('hp_t9');
});
