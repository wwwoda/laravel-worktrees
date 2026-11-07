<?php

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Woda\Worktrees\Contracts\TemplateRecipe;
use Woda\Worktrees\Shared\DatabaseProvisioner;
use Woda\Worktrees\Shared\DatabaseServer;
use Woda\Worktrees\Shared\MainCheckout;
use Woda\Worktrees\Shared\TemplateManager;
use Woda\Worktrees\Shared\WorktreeState;
use Woda\Worktrees\Tests\Support\FakeDatabaseServer;
use Woda\Worktrees\Tests\Support\FakeTemplateRecipe;

/*
 * worktree:create with the shared-services strategy. Docker and git calls are
 * faked; the database server is an in-memory fake (the real server is covered
 * by tests/Integration).
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
    $this->commonDir = '';
    $this->fakeProcesses = function () {
        Process::fake([
            'git show-ref *' => Process::result(exitCode: 1),
            'git worktree add *' => function (PendingProcess $process) {
                preg_match("/worktree add -b '([^']+)' '([^']+)'/", (string) $process->command, $match);
                File::ensureDirectoryExists($match[2]);
                $this->worktrees[$match[1]] = $match[2];

                return Process::result();
            },
            'git worktree list --porcelain' => fn () => Process::result(implode('', array_map(
                fn (string $branch, string $path) => "worktree {$path}\nHEAD abc\nbranch refs/heads/{$branch}\n\n",
                array_keys(['master' => base_path(), ...$this->worktrees]),
                ['master' => base_path(), ...$this->worktrees],
            ))),
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

test('a new worktree gets template copies, its own user on exact names, state and env', function () {
    $this->artisan('worktree:create', ['name' => 't9'])->assertSuccessful();

    $template = app(TemplateManager::class)->name();
    $state = WorktreeState::load(($this->path)('t9'));
    $env = File::get(($this->path)('t9').'/.env');

    expect($this->recipe->built)->toBe([$template])
        ->and($this->server->databases['hp_t9'])->toBe(['migrated', 'seeded'])
        ->and($this->server->databases['hp_t9_test'])->toBe(['migrated', 'seeded'])
        ->and($this->server->databases['hp_t9_e2e'])->toBe(['migrated', 'seeded'])
        ->and($this->server->users['hp_t9']['databases'])->toBe(['hp_t9', 'hp_t9_test', 'hp_t9_e2e', 'hp_t9_test_test_1', 'hp_t9_test_test_2'])
        ->and($state?->string('user'))->toBe('hp_t9')
        ->and($state?->string('template'))->toBe($template)
        ->and($state?->string('branch'))->toBe('t9')
        ->and($state?->redis())->toBe([2, 3])
        ->and($env)->toContain('DB_DATABASE=hp_t9')
        ->and($env)->toContain('DB_USERNAME=hp_t9')
        ->and($env)->toContain('DB_PASSWORD='.$this->server->users['hp_t9']['password'])
        ->and($env)->toContain('DB_TEST_DATABASE=hp_t9_test')
        ->and($env)->toContain('DB_HOST=mariadb')
        ->and($env)->toContain('REDIS_DB=2')
        ->and($env)->toContain('REDIS_CACHE_DB=3')
        ->and($this->server->databases['hungryport'])->toBe(['production-like']);
});

test('migrations newer than the template run in the app, test and e2e databases', function () {
    $this->artisan('worktree:create', ['name' => 't9'])->assertSuccessful();

    foreach (['hp_t9', 'hp_t9_test', 'hp_t9_e2e'] as $database) {
        Process::assertRan(fn (PendingProcess $p) => str_contains((string) $p->command, "-e 'DB_DATABASE={$database}'")
            && str_contains((string) $p->command, 'php artisan migrate --force'));
    }
});

test('the databases exist before the app container starts', function () {
    $this->artisan('worktree:create', ['name' => 't9'])->assertSuccessful();

    $state = json_decode(File::get(($this->path)('t9').'/storage/worktree-bootstrap.json'), true);
    expect(array_search('database', $state['completed'], true))->toBeLessThan(array_search('environment', $state['completed'], true));
});

test('two worktrees get different Redis numbers and one template build', function () {
    $this->artisan('worktree:create', ['name' => 't9'])->assertSuccessful();
    $this->artisan('worktree:create', ['name' => 't9-1'])->assertSuccessful();

    expect(WorktreeState::load(($this->path)('t9'))?->redis())->toBe([2, 3])
        ->and(WorktreeState::load(($this->path)('t9-1'))?->redis())->toBe([4, 5])
        ->and($this->recipe->built)->toHaveCount(1)
        ->and($this->server->users['hp_t9_1']['databases'])->not->toContain('hp_t9');
});

test('a changed migration gives a new template; an unchanged tree reuses it', function () {
    $templates = app(TemplateManager::class);
    $first = $templates->ensure();
    expect($templates->ensure())->toBe($first);

    File::put(base_path($this->migrations.'/2026_01_01_000000_create_items.php'), '<?php // v2');
    $second = $templates->ensure();

    expect($second)->not->toBe($first)
        ->and($this->recipe->built)->toBe([$first, $second])
        ->and($templates->prune())->toBe([$first])
        ->and($this->server->databaseExists($second))->toBeTrue();
});

test('a second setup waits for a running template build and does not build twice', function () {
    $templates = app(TemplateManager::class);
    $name = $templates->name();
    $lock = base_path('storage/app/worktrees/template.lock');
    $marker = base_path("storage/app/worktrees/templates/{$name}.ready");
    File::ensureDirectoryExists(dirname($marker));
    // Another process holds the lock, "builds" for a moment, then marks the template ready.
    $builder = proc_open([PHP_BINARY, '-r', sprintf(
        '$h = fopen(%s, "c"); flock($h, LOCK_EX); file_put_contents(%s, "locked"); usleep(1500000); file_put_contents(%s, "ready"); flock($h, LOCK_UN);',
        var_export($lock, true), var_export($lock.'.held', true), var_export($marker, true),
    )], [], $pipes);
    while (! File::exists($lock.'.held')) {
        usleep(50_000);
    }
    $this->server->databases[$name] = ['built by the other process'];

    $waited = [];
    expect($templates->ensure(onStep: function (string $message) use (&$waited) {
        $waited[] = $message;
    }))->toBe($name)
        ->and($this->recipe->built)->toBe([])
        ->and($waited[0] ?? '')->toContain('waiting');
    proc_close($builder);
});

test('--from-main copies the main database where config allows it', function () {
    config()->set('worktrees.shared.database.allow_from_main', true);

    $this->artisan('worktree:create', ['name' => 't9', '--from-main' => true])->assertSuccessful();

    expect($this->server->databases['hp_t9'])->toBe(['production-like'])
        ->and($this->server->databases['hp_t9_e2e'])->toBe(['migrated', 'seeded'])
        ->and(WorktreeState::load(($this->path)('t9'))?->string('source'))->toBe('main');
});

test('--from-main is refused where config does not allow it', function () {
    config()->set('worktrees.shared.database.allow_from_main', false);

    $this->artisan('worktree:create', ['name' => 't9', '--from-main' => true])
        ->expectsOutputToContain('--from-main is not allowed on this machine')
        ->assertFailed();

    expect($this->server->databaseExists('hp_t9'))->toBeFalse();
});

test('names that could equal another worktree, a template or the main database are refused', function (string $name, string $config, string $message) {
    if ($config !== '') {
        config()->set('worktrees.shared.database.main', $config);
    }

    $this->artisan('worktree:create', ['name' => $name])
        ->expectsOutputToContain($message)
        ->assertFailed();

    expect($this->server->users)->toBe([]);
})->with([
    'test ending' => ['t9-test', '', 'is reserved'],
    'parallel ending' => ['t9-test-test-1', '', 'is reserved'],
    'e2e ending' => ['t9-e2e', '', 'is reserved'],
    'template prefix' => ['tpl-abc', '', 'is reserved'],
    'main database' => ['main', 'hp_main', 'main database'],
]);

test('a name another worktree already uses is refused', function () {
    $this->artisan('worktree:create', ['name' => 't9'])->assertSuccessful();
    $this->worktrees['other'] = $this->base.'/elsewhere/T9';
    File::ensureDirectoryExists($this->base.'/elsewhere/T9');

    expect(fn () => app(DatabaseProvisioner::class)->provision('T9', $this->base.'/elsewhere/T9'))
        ->toThrow(RuntimeException::class, 'already uses the database names');
});

test('a broken state file is rebuilt and keeps the password of .env', function () {
    $this->artisan('worktree:create', ['name' => 't9'])->assertSuccessful();
    $password = $this->server->users['hp_t9']['password'];
    File::put(WorktreeState::path(($this->path)('t9')), '{broken');

    $state = app(DatabaseProvisioner::class)->provision('t9', ($this->path)('t9'));

    expect($state->string('password'))->toBe($password)
        ->and($state->redis())->toBe([2, 3])
        ->and($this->server->log)->not->toContain('drop hp_t9');
});

test('worktree:template builds the template on demand', function () {
    $this->artisan('worktree:template')->assertSuccessful();

    expect($this->recipe->built)->toBe([app(TemplateManager::class)->name()]);
});

test('templates and locks live in the main checkout also when artisan runs in a linked worktree', function () {
    $main = $this->base.'/main';
    File::ensureDirectoryExists($main.'/.git');
    $this->commonDir = $main.'/.git';
    MainCheckout::forget();

    try {
        expect(MainCheckout::file('storage/app/worktrees/template.lock'))->toBe($main.'/storage/app/worktrees/template.lock');
    } finally {
        MainCheckout::forget();
    }
});
