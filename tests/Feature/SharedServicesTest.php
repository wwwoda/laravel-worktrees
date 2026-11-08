<?php

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Woda\Worktrees\Contracts\TemplateRecipe;
use Woda\Worktrees\Shared\ArtisanTemplateRecipe;
use Woda\Worktrees\Shared\DatabaseProvisioner;
use Woda\Worktrees\Shared\DatabaseServer;
use Woda\Worktrees\Shared\EnvFile;
use Woda\Worktrees\Shared\MainCheckout;
use Woda\Worktrees\Shared\MariaDbServer;
use Woda\Worktrees\Shared\TemplateManager;
use Woda\Worktrees\Shared\WorktreeState;
use Woda\Worktrees\Tests\Support\FakeDatabaseServer;
use Woda\Worktrees\Tests\Support\FakeTemplateRecipe;
use Woda\Worktrees\WorktreeManager;

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
        Process::assertRan(fn (PendingProcess $p) => str_contains((string) $p->command, "-e 'DB_DATABASE={$database}' -e 'APP_CONFIG_CACHE=/tmp/worktree-migrate-")
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

test('each template keeps its own builder, and pruning a template drops its builder', function () {
    $templates = app(TemplateManager::class);
    $first = $templates->ensure();
    File::put(base_path($this->migrations.'/2026_01_01_000000_create_items.php'), '<?php // v2');
    $second = $templates->ensure();

    expect($this->server->users[$templates->builder($first)]['databases'])->toBe([$first])
        ->and($this->server->users[$templates->builder($second)]['databases'])->toBe([$second])
        ->and($templates->builder($first))->not->toBe($templates->builder($second));

    $templates->prune();

    expect($this->server->users)->not->toHaveKey($templates->builder($first))
        ->toHaveKey($templates->builder($second));
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

test('a template build that fails while it sets up its builder leaves no builder account', function () {
    $failing = new class extends FakeDatabaseServer
    {
        public function grantUser(string $user, string $password, array $databases): void
        {
            parent::grantUser($user, $password, $databases);

            throw new RuntimeException('grant failed');
        }
    };
    app()->instance(DatabaseServer::class, $failing);
    app()->instance(TemplateRecipe::class, new FakeTemplateRecipe($failing));
    $templates = app(TemplateManager::class);

    expect(fn () => $templates->ensure())->toThrow(RuntimeException::class, 'grant failed');

    expect($failing->users)->toBe([])
        ->and($failing->databaseExists($templates->name()))->toBeFalse();
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

test('on MySQL a name whose user would exceed 32 characters is refused before the server is touched', function () {
    $mysql = new class extends FakeDatabaseServer
    {
        public function driver(): string
        {
            return 'mysql';
        }
    };
    $mysql->databases = ['hungryport' => []];
    app()->instance(DatabaseServer::class, $mysql);
    app()->instance(TemplateRecipe::class, new FakeTemplateRecipe($mysql));
    $name = 'a-long-worktree-name-for-mysql';

    $this->artisan('worktree:create', ['name' => $name])
        ->expectsOutputToContain('more than 32 characters')
        ->assertFailed();

    expect($mysql->log)->toBe([]);
});

test('on MariaDB the same name is accepted', function () {
    $this->artisan('worktree:create', ['name' => 'a-long-worktree-name-for-mysql'])->assertSuccessful();

    expect($this->server->users)->toHaveKey('hp_a_long_worktree_name_for_mysql');
});

test('--skip-db is refused before the worktree has its own databases', function () {
    $this->artisan('worktree:create', ['name' => 't9', '--skip-db' => true])
        ->expectsOutputToContain('--skip-db')
        ->assertFailed();

    Process::assertNotRan(fn (PendingProcess $p) => str_contains((string) $p->command, 'docker compose up'));
    expect($this->server->users)->toBe([]);
});

test('--skip-db is accepted on a resume once the databases exist', function () {
    $this->artisan('worktree:create', ['name' => 't9'])->assertSuccessful();

    $this->artisan('worktree:bootstrap', ['name' => 't9', '--resume' => true, '--skip-db' => true])->assertSuccessful();
});

test('a name another worktree already uses is refused', function () {
    $this->artisan('worktree:create', ['name' => 't9'])->assertSuccessful();
    $this->worktrees['other'] = $this->base.'/elsewhere/T9';
    File::ensureDirectoryExists($this->base.'/elsewhere/T9');

    expect(fn () => app(DatabaseProvisioner::class)->provision('T9', $this->base.'/elsewhere/T9'))
        ->toThrow(RuntimeException::class, 'already uses the database names');
});

test('a name another worktree uses is refused also when that worktree lost its state file', function (string $stateContent) {
    $this->artisan('worktree:create', ['name' => 't9'])->assertSuccessful();
    $stateContent === '' ? File::delete(WorktreeState::path(($this->path)('t9'))) : File::put(WorktreeState::path(($this->path)('t9')), $stateContent);
    $this->worktrees['other'] = $this->base.'/elsewhere/T9';
    File::ensureDirectoryExists($this->base.'/elsewhere/T9');
    $password = $this->server->users['hp_t9']['password'];

    expect(fn () => app(DatabaseProvisioner::class)->provision('T9', $this->base.'/elsewhere/T9'))
        ->toThrow(RuntimeException::class, 'already uses the database names');
    expect($this->server->users['hp_t9']['password'])->toBe($password);
})->with(['missing' => [''], 'broken' => ['{broken']]);

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

test('the template build bypasses a config cache of the main checkout', function () {
    $recipe = new ArtisanTemplateRecipe('container', 'laravel.test', 'mariadb', 3306);

    $recipe->build('hp_tpl_abc', 'hp_tpl_builder', str_repeat('a', 32), 'php artisan db:seed --force');

    Process::assertRan(fn (PendingProcess $p) => str_contains((string) $p->command, "-e 'APP_CONFIG_CACHE=/tmp/worktree-template-")
        && str_contains((string) $p->command, "-e 'DB_DATABASE=hp_tpl_abc'")
        && str_contains((string) $p->command, 'php artisan migrate --force'));
});

test('the template build empties a connection URL of the main checkout', function (string $mode) {
    $recipe = new ArtisanTemplateRecipe($mode, 'laravel.test', 'mariadb', 3306);

    $recipe->build('hp_tpl_abc', 'hp_tpl_builder', str_repeat('a', 32), '');

    // Laravel gives a connection URL precedence over DB_DATABASE and the account.
    Process::assertRan(fn (PendingProcess $p) => str_contains((string) $p->command, 'php artisan migrate --force')
        && ($mode === 'host'
            ? ($p->environment['DB_URL'] ?? null) === '' && ($p->environment['DATABASE_URL'] ?? null) === ''
            : str_contains((string) $p->command, "-e 'DB_URL='") && str_contains((string) $p->command, "-e 'DATABASE_URL='")));
})->with(['container', 'host']);

test('a copy that stopped half way is dropped and copied again', function () {
    $this->artisan('worktree:create', ['name' => 't9'])->assertSuccessful();
    $path = ($this->path)('t9');
    $state = WorktreeState::load($path);
    $state->data['pending_copies'] = ['hp_t9'];
    $state->save($path);
    $this->server->databases['hp_t9'] = ['half'];

    $after = app(DatabaseProvisioner::class)->provision('t9', $path);

    expect($this->server->databases['hp_t9'])->toBe(['migrated', 'seeded'])
        ->and($after->data['pending_copies'])->toBe([]);
});

test('names are reserved in the state before the server is touched', function () {
    $this->server->databases = ['hungryport' => []];
    $failing = new class extends FakeDatabaseServer
    {
        public function copyDatabase(string $source, string $target, ?string $owner = null, ?Closure $output = null): void
        {
            throw new RuntimeException('copy failed');
        }
    };
    $failing->databases = ['hungryport' => []];
    app()->instance(DatabaseServer::class, $failing);
    app()->instance(TemplateRecipe::class, new FakeTemplateRecipe($failing));
    $first = $this->base.'/elsewhere/T9';
    $this->worktrees['T9'] = $first;
    File::ensureDirectoryExists($first);

    expect(fn () => app(DatabaseProvisioner::class)->provision('T9', $first))->toThrow(RuntimeException::class, 'copy failed');

    $second = $this->base.'/elsewhere/t9';
    $this->worktrees['t9'] = $second;
    File::ensureDirectoryExists($second);
    expect(fn () => app(DatabaseProvisioner::class)->provision('t9', $second))->toThrow(RuntimeException::class, 'already uses the database names');
});

test('a resumed --from-main setup keeps copying the main database', function () {
    config()->set('worktrees.shared.database.allow_from_main', true);
    $failing = new class extends FakeDatabaseServer
    {
        public bool $fail = true;

        public function copyDatabase(string $source, string $target, ?string $owner = null, ?Closure $output = null): void
        {
            if ($this->fail && $source === 'hungryport') {
                throw new RuntimeException('copy failed');
            }
            parent::copyDatabase($source, $target, $owner, $output);
        }
    };
    $failing->databases = ['hungryport' => ['production-like']];
    app()->instance(DatabaseServer::class, $failing);
    app()->instance(TemplateRecipe::class, new FakeTemplateRecipe($failing));

    $this->artisan('worktree:create', ['name' => 't9', '--from-main' => true])->assertFailed();
    $failing->fail = false;
    $this->artisan('worktree:bootstrap', ['name' => 't9', '--resume' => true])->assertSuccessful();

    expect($failing->databases['hp_t9'])->toBe(['production-like'])
        ->and(WorktreeState::load(($this->path)('t9'))?->data)->not->toHaveKey('from_main_requested');
});

test('without a configured main database, the main checkout\'s .env names it', function () {
    // The invoking checkout's .env says DB_DATABASE=app; the main checkout's says hp_main.
    config()->set('worktrees.shared.database.main', null);
    $main = $this->base.'/main';
    File::ensureDirectoryExists($main.'/.git');
    File::put($main.'/.env', "DB_DATABASE=hp_main\n");
    $this->commonDir = $main.'/.git';
    MainCheckout::forget();

    $this->artisan('worktree:create', ['name' => 'main'])
        ->expectsOutputToContain("would use the main database 'hp_main'")
        ->assertFailed();
});

test('the main checkout\'s .env is read as dotenv reads it', function (string $line) {
    config()->set('worktrees.shared.database.main', null);
    $main = $this->base.'/main';
    File::ensureDirectoryExists($main.'/.git');
    File::put($main.'/.env', "APP_NAME=App\n{$line}\n");
    $this->commonDir = $main.'/.git';
    MainCheckout::forget();

    $this->artisan('worktree:create', ['name' => 'main'])
        ->expectsOutputToContain("would use the main database 'hp_main'")
        ->assertFailed();
})->with([
    'inline comment' => ['DB_DATABASE=hp_main # main database'],
    'quoted with comment' => ['DB_DATABASE="hp_main" # main database'],
    'export prefix' => ['export DB_DATABASE=hp_main'],
    'spaces around =' => ['DB_DATABASE = hp_main'],
]);

test('a main checkout .env that dotenv cannot parse names no main database', function () {
    config()->set('worktrees.shared.database.main', null);
    $main = $this->base.'/main';
    File::ensureDirectoryExists($main.'/.git');
    File::put($main.'/.env', "DB_DATABASE=\"hp_main\n");
    $this->commonDir = $main.'/.git';
    MainCheckout::forget();

    expect(fn () => $this->artisan('worktree:create', ['name' => 't9'])->run())
        ->toThrow(InvalidArgumentException::class, 'Cannot tell the main database');
    expect($this->server->users)->toBe([]);
});

test('a worktree with the main checkout\'s compose project is not started, also when artisan runs elsewhere', function () {
    $main = $this->base.'/main';
    File::ensureDirectoryExists($main.'/.git');
    $this->commonDir = $main.'/.git';
    MainCheckout::forget();
    Process::fake(['docker compose config --format json' => fn (PendingProcess $p) => Process::result(json_encode(['name' => $p->path === base_path() ? 'elsewhere' : 'app']))]);

    $this->artisan('worktree:create', ['name' => 't9'])
        ->expectsOutputToContain('same compose project')
        ->assertFailed();

    Process::assertNotRan('docker compose up -d');
    MainCheckout::forget();
});

test('Redis numbers of a worktree that lost its state file stay taken', function () {
    $this->artisan('worktree:create', ['name' => 't9'])->assertSuccessful();
    File::delete(WorktreeState::path(($this->path)('t9')));

    $this->artisan('worktree:create', ['name' => 't10'])->assertSuccessful();

    expect(WorktreeState::load(($this->path)('t10'))?->redis())->toBe([4, 5]);
});

test('a worktree that lost its state file keeps its names and Redis numbers also when .env holds them inside URLs', function () {
    config()->set('worktrees.shared.env', [
        'DATABASE_URL' => 'mysql://{db_user}:{db_password}@{db_host}:{db_port}/{db_app}?charset=utf8mb4',
        'REDIS_URL' => 'redis://redis:6379/{redis_default}',
        'REDIS_CACHE_URL' => 'redis://redis:6379/{redis_cache}',
    ]);
    $this->artisan('worktree:create', ['name' => 't9'])->assertSuccessful();
    File::delete(WorktreeState::path(($this->path)('t9')));
    expect(File::get(($this->path)('t9').'/.env'))->toContain('REDIS_URL=redis://redis:6379/2');

    $this->worktrees['other'] = $this->base.'/elsewhere/T9';
    File::ensureDirectoryExists($this->base.'/elsewhere/T9');
    expect(fn () => app(DatabaseProvisioner::class)->provision('T9', $this->base.'/elsewhere/T9'))
        ->toThrow(RuntimeException::class, 'already uses the database names');

    $this->artisan('worktree:create', ['name' => 't10'])->assertSuccessful();
    expect(WorktreeState::load(($this->path)('t10'))?->redis())->toBe([4, 5]);
});

test('a worktree that lost its state file keeps its names and Redis numbers when another line of its .env is broken', function () {
    $this->artisan('worktree:create', ['name' => 't9'])->assertSuccessful();
    File::delete(WorktreeState::path(($this->path)('t9')));
    File::append(($this->path)('t9').'/.env', "BROKEN=\"never closed\n");

    $this->worktrees['other'] = $this->base.'/elsewhere/T9';
    File::ensureDirectoryExists($this->base.'/elsewhere/T9');
    expect(fn () => app(DatabaseProvisioner::class)->provision('T9', $this->base.'/elsewhere/T9'))
        ->toThrow(RuntimeException::class, 'already uses the database names');

    $this->artisan('worktree:create', ['name' => 't10'])->assertSuccessful();
    expect(WorktreeState::load(($this->path)('t10'))?->redis())->toBe([4, 5]);
});

test('an incomplete state file of another worktree does not hide its names', function () {
    $this->artisan('worktree:create', ['name' => 't9'])->assertSuccessful();
    File::put(WorktreeState::path(($this->path)('t9')), json_encode(['schema' => WorktreeState::SCHEMA]));
    $this->worktrees['other'] = $this->base.'/elsewhere/T9';
    File::ensureDirectoryExists($this->base.'/elsewhere/T9');

    expect(fn () => app(DatabaseProvisioner::class)->provision('T9', $this->base.'/elsewhere/T9'))
        ->toThrow(RuntimeException::class, 'already uses the database names');
});

test('--skip-db without --resume is refused also after the databases exist', function () {
    $this->artisan('worktree:create', ['name' => 't9'])->assertSuccessful();

    expect(fn () => app(WorktreeManager::class)->bootstrap('t9', ['skip_db' => true]))->toThrow(RuntimeException::class, '--skip-db');

    expect(File::get(($this->path)('t9').'/.env'))->toContain('DB_DATABASE=hp_t9');
});

test('the template build in the container keeps the password off the command line', function () {
    $recipe = new ArtisanTemplateRecipe('container', 'laravel.test', 'mariadb', 3306);

    $recipe->build('hp_tpl_abc', 'hp_tpl_builder', str_repeat('a', 32), '');

    Process::assertRan(fn (PendingProcess $p) => str_contains((string) $p->command, 'php artisan migrate --force')
        && ! str_contains((string) $p->command, str_repeat('a', 32))
        && str_contains((string) $p->command, ' -e DB_PASSWORD ')
        && ($p->environment['DB_PASSWORD'] ?? null) === str_repeat('a', 32));
});

test('the mysql driver picks the mysql clients when none are configured', function () {
    config()->set('worktrees.shared.database.driver', 'mysql');
    $config = require __DIR__.'/../../config/worktrees.php';
    config()->set('worktrees.shared.database.client', $config['shared']['database']['client']);
    config()->set('worktrees.shared.database.dump_client', $config['shared']['database']['dump_client']);
    app()->forgetInstance(DatabaseServer::class);

    $server = app(DatabaseServer::class);

    expect((new ReflectionProperty($server, 'client'))->getValue($server))->toBe('mysql')
        ->and((new ReflectionProperty($server, 'dumpClient'))->getValue($server))->toBe('mysqldump');
});

test('pruning waits for a running template build', function () {
    $templates = app(TemplateManager::class);
    $lock = base_path('storage/app/worktrees/template.lock');
    File::ensureDirectoryExists(dirname($lock));
    $builder = proc_open([PHP_BINARY, '-r', sprintf(
        '$h = fopen(%s, "c"); flock($h, LOCK_EX); file_put_contents(%s, "locked"); usleep(1500000); flock($h, LOCK_UN);',
        var_export($lock, true), var_export($lock.'.held', true),
    )], [], $pipes);
    while (! File::exists($lock.'.held')) {
        usleep(50_000);
    }
    $started = microtime(true);

    $templates->prune();

    expect(microtime(true) - $started)->toBeGreaterThan(1.0);
    proc_close($builder);
    File::delete($lock.'.held');
});

test('a run without --resume starts its stage record anew, so a failed provisioning is not skipped later', function () {
    $this->artisan('worktree:create', ['name' => 't9'])->assertSuccessful();
    $this->server->up = false;

    expect(fn () => app(WorktreeManager::class)->bootstrap('t9'))->toThrow(RuntimeException::class);

    $record = json_decode(File::get(($this->path)('t9').'/storage/worktree-bootstrap.json'), true);
    expect($record['completed'])->not->toContain('database');
});

test('a stale ready marker is removed before the template is built again', function () {
    $templates = app(TemplateManager::class);
    $name = $templates->name();
    $marker = base_path("storage/app/worktrees/templates/{$name}.ready");
    File::ensureDirectoryExists(dirname($marker));
    File::put($marker, 'old');
    $readyDuringBuild = null;
    app()->instance(TemplateRecipe::class, new class($this->server, $templates, $name, $readyDuringBuild) implements TemplateRecipe
    {
        public function __construct(private FakeDatabaseServer $server, private TemplateManager $templates, private string $name, private ?bool &$seen) {}

        public function build(string $database, string $user, string $password, string $seedCommand, ?Closure $output = null): void
        {
            $this->seen = $this->templates->isReady($this->name);
            $this->server->databases[$database] = ['migrated'];
        }
    });
    app()->forgetInstance(TemplateManager::class);

    app(TemplateManager::class)->ensure();

    expect($readyDuringBuild)->toBeFalse()
        ->and(File::exists($marker))->toBeTrue();
});

test('the host template runner connects through the admin host and port', function () {
    config()->set('worktrees.shared.database.template.runner', 'host');
    config()->set('worktrees.shared.database.host', '127.0.0.1');
    config()->set('worktrees.shared.database.port', 3307);
    app()->forgetInstance(TemplateRecipe::class);

    $recipe = app(TemplateRecipe::class);

    expect((new ReflectionProperty($recipe, 'databaseHost'))->getValue($recipe))->toBe('127.0.0.1')
        ->and((new ReflectionProperty($recipe, 'databasePort'))->getValue($recipe))->toBe(3307);
});

test('.env values with CRLF line endings are read without the carriage return', function () {
    $file = $this->base.'/crlf.env';
    File::put($file, "APP_NAME=App\r\nDB_DATABASE=wt_main\r\nDB_PASSWORD=\"secret\"\r\n");

    expect(EnvFile::get($file, 'DB_DATABASE'))->toBe('wt_main')
        ->and(EnvFile::get($file, 'DB_PASSWORD'))->toBe('secret');
});

test('a failed template build retires the builder also when dropping its database fails', function () {
    $failing = new class extends FakeDatabaseServer
    {
        public function dropDatabase(string $database): void
        {
            throw new RuntimeException('drop failed');
        }
    };
    app()->instance(DatabaseServer::class, $failing);
    app()->instance(TemplateRecipe::class, new class implements TemplateRecipe
    {
        public function build(string $database, string $user, string $password, string $seedCommand, ?Closure $output = null): void
        {
            throw new RuntimeException('migration failed');
        }
    });

    $templates = app(TemplateManager::class);

    expect(fn () => $templates->ensure())->toThrow(RuntimeException::class, 'migration failed')
        ->and($failing->log)->toContain('retire user '.$templates->builder($templates->name()));
});

test('a builder that cannot be retired after the build leaves no ready template', function () {
    $failing = new class extends FakeDatabaseServer
    {
        public int $finishCalls = 0;

        public function finishTemplateBuild(string $builder, string $template): void
        {
            if (++$this->finishCalls === 1) {
                throw new RuntimeException('admin connection lost');
            }
            parent::finishTemplateBuild($builder, $template);
        }
    };
    app()->instance(DatabaseServer::class, $failing);
    app()->instance(TemplateRecipe::class, new FakeTemplateRecipe($failing));
    $templates = app(TemplateManager::class);

    expect(fn () => $templates->ensure())->toThrow(RuntimeException::class, 'admin connection lost')
        ->and($templates->isReady($templates->name()))->toBeFalse()
        // The cleanup drops the builder with the database; it needs no second retire.
        ->and($failing->finishCalls)->toBe(1)
        ->and($failing->users)->toBe([]);
});

test('the copy keeps its dump files private', function () {
    Process::fake(['*' => Process::result()]);
    $server = new MariaDbServer('mariadb', null, '127.0.0.1', 3306, 'root', '');

    $server->copyDatabase('hp_tpl_abc', 'hp_t9', 'hp_t9');

    Process::assertRan(fn (PendingProcess $p) => str_contains((string) $p->command, 'mariadb-dump') && str_contains((string) $p->command, 'umask 077'));
});
