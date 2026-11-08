<?php

use Dotenv\Dotenv;
use Illuminate\Support\ConfigurationUrlParser;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Woda\Worktrees\Contracts\TemplateRecipe;
use Woda\Worktrees\Shared\DatabaseProvisioner;
use Woda\Worktrees\Shared\DatabaseServer;
use Woda\Worktrees\Shared\MariaDbServer;
use Woda\Worktrees\Shared\RedisServer;
use Woda\Worktrees\Shared\TemplateManager;
use Woda\Worktrees\Shared\WorktreeState;

/*
 * The provisioner against a real MariaDB: template build as the builder user,
 * dump-and-restore copies, exact grants. Git is faked.
 */
beforeEach(function () {
    if (! getenv('LW_TEST_MARIADB_HOST')) {
        $this->markTestSkipped('LW_TEST_MARIADB_HOST is not set.');
    }
    $this->rootPassword = (string) getenv('LW_TEST_MARIADB_PASSWORD');
    $this->server = new MariaDbServer('mariadb', null, (string) getenv('LW_TEST_MARIADB_HOST'),
        (int) (getenv('LW_TEST_MARIADB_PORT') ?: 3306), 'root', $this->rootPassword,
        (string) (getenv('LW_TEST_MARIADB_CLIENT') ?: 'mariadb'), (string) (getenv('LW_TEST_MARIADB_DUMP') ?: 'mariadb-dump'));
    $this->prefix = 'lw'.substr(md5(uniqid()), 0, 6);
    $this->base = sys_get_temp_dir().'/worktrees-int-'.uniqid();
    File::ensureDirectoryExists($this->base);
    $this->server->createDatabase($this->prefix.'_main');

    config()->set('worktrees.shared.database.prefix', $this->prefix);
    config()->set('worktrees.shared.database.main', $this->prefix.'_main');
    config()->set('worktrees.shared.database.parallel_workers', 2);
    config()->set('worktrees.shared.database.template.hash_paths', []);
    config()->set('worktrees.shared.database.template.seed_command', 'seed '.$this->prefix);
    app()->instance(DatabaseServer::class, $this->server);
    $server = $this->server;
    app()->instance(TemplateRecipe::class, new class($server) implements TemplateRecipe
    {
        public function __construct(private readonly MariaDbServer $server) {}

        public function build(string $database, string $user, string $password, string $seedCommand, ?Closure $output = null): void
        {
            $result = $this->server->runAs($user, $password, 'CREATE TABLE users (email VARCHAR(100)); INSERT INTO users VALUES (\'test@example.com\'); CREATE VIEW user_emails AS SELECT email FROM users;', $database);
            if (! $result->successful) {
                throw new RuntimeException($result->error);
            }
        }
    });

    // No Redis here: emptying its numbers is covered by the feature tests.
    app()->instance(RedisServer::class, new class(null, '', 0) extends RedisServer
    {
        public function ping(): void {}

        public function flush(int $database): void {}
    });
    $this->paths = [];
    Process::fake(['git worktree list --porcelain' => fn () => Process::result(implode('', array_map(
        fn (string $path) => "worktree {$path}\nHEAD abc\nbranch refs/heads/x\n\n", $this->paths)))]);
    $this->provision = function (string $name): WorktreeState {
        $path = $this->base.'/'.$name;
        File::ensureDirectoryExists($path);
        $this->paths[] = $path;

        return app(DatabaseProvisioner::class)->provision($name, $path);
    };
});

afterEach(function () {
    if (! isset($this->server)) {
        return;
    }
    foreach ($this->server->databasesStartingWith($this->prefix.'_') as $database) {
        $this->server->dropDatabase($database);
    }
    foreach (['t9', 't9_1', 't8'] as $user) {
        $this->server->dropUser($this->prefix.'_'.$user);
    }
    foreach ($this->templates ?? [] as $template) {
        $this->server->dropUser(app(TemplateManager::class)->builder($template));
    }
    File::deleteDirectory($this->base);
    File::deleteDirectory(base_path('storage/app/worktrees'));
});

test('provisioning copies the template and gives each worktree its own guarded user', function () {
    $t9 = ($this->provision)('t9');
    $t91 = ($this->provision)('t9-1');
    $template = app(TemplateManager::class)->name();
    $this->templates = [$template];
    $query = fn (WorktreeState $state, string $sql) => $this->server->runAs((string) $state->string('user'), (string) $state->string('password'), $sql);

    expect($query($t9, "SELECT email FROM `{$this->prefix}_t9`.users")->output)->toContain('test@example.com')
        ->and($query($t9, "SELECT email FROM `{$this->prefix}_t9_e2e`.users")->output)->toContain('test@example.com')
        ->and($query($t9, "SELECT email FROM `{$this->prefix}_t9_1`.users")->successful)->toBeFalse()
        ->and($query($t91, "INSERT INTO `{$template}`.users VALUES ('x')")->successful)->toBeFalse()
        ->and($query($t91, "SELECT 1 FROM `{$this->prefix}_main`.users")->successful)->toBeFalse()
        ->and($t9->redis())->not->toBe($t91->redis())
        // The builder stays as the definer of template views, but cannot log in.
        ->and($this->server->runAs('root', $this->rootPassword, "SHOW CREATE USER '".app(TemplateManager::class)->builder($template)."'@'%'")->output)->toContain('ACCOUNT LOCK');
});

test('an older template still copies after a newer one was built', function () {
    $templates = app(TemplateManager::class);
    ($this->provision)('t9');
    $first = $templates->name();
    config()->set('worktrees.shared.database.template.seed_command', 'seed '.$this->prefix.' v2');
    ($this->provision)('t9-1');
    $second = app(TemplateManager::class)->name();
    $this->templates = [$first, $second];
    config()->set('worktrees.shared.database.template.seed_command', 'seed '.$this->prefix);

    $t8 = ($this->provision)('t8');

    expect($second)->not->toBe($first)
        ->and(app(TemplateManager::class)->name())->toBe($first)
        ->and($this->server->runAs((string) $t8->string('user'), (string) $t8->string('password'), "SELECT email FROM `{$this->prefix}_t8`.user_emails")->output)
        ->toContain('test@example.com');
});

test('provisioning again keeps the data of an existing database', function () {
    $t9 = ($this->provision)('t9');
    $this->server->runAs((string) $t9->string('user'), (string) $t9->string('password'), "INSERT INTO `{$this->prefix}_t9`.users VALUES ('mine@example.com')");

    $again = app(DatabaseProvisioner::class)->provision('t9', $this->base.'/t9');

    expect($this->server->runAs((string) $again->string('user'), (string) $again->string('password'), "SELECT email FROM `{$this->prefix}_t9`.users")->output)
        ->toContain('mine@example.com');
});

test('deleting worktree t9 leaves t9-1, the main database and the templates untouched', function () {
    $t9 = ($this->provision)('t9');
    $t91 = ($this->provision)('t9-1');
    $template = app(TemplateManager::class)->name();

    $removed = app(DatabaseProvisioner::class)->deprovision('t9', $this->base.'/t9');

    expect($removed)->toContain("database {$this->prefix}_t9", "user {$this->prefix}_t9")
        ->and($this->server->databaseExists($this->prefix.'_t9'))->toBeFalse()
        ->and($this->server->databaseExists($this->prefix.'_t9_e2e'))->toBeFalse()
        ->and($this->server->userExists($this->prefix.'_t9'))->toBeFalse()
        ->and($this->server->databaseExists($this->prefix.'_t9_1'))->toBeTrue()
        ->and($this->server->databaseExists($this->prefix.'_t9_1_e2e'))->toBeTrue()
        ->and($this->server->databaseExists($this->prefix.'_main'))->toBeTrue()
        ->and($this->server->databaseExists($template))->toBeTrue()
        ->and($this->server->runAs((string) $t91->string('user'), (string) $t91->string('password'), "SELECT email FROM `{$this->prefix}_t9_1`.users")->output)->toContain('test@example.com');
});

test('a worktree whose .env took over the main checkout\'s DB_URL connects to its own database', function () {
    $path = $this->base.'/t9';
    File::ensureDirectoryExists($path);
    $mainUrl = 'mysql://root:'.rawurlencode($this->rootPassword).'@'.getenv('LW_TEST_MARIADB_HOST').'/'.$this->prefix.'_main';
    File::put($path.'/.env', "DB_URL={$mainUrl}\nDB_DATABASE={$this->prefix}_main\nDB_USERNAME=root\n");

    ($this->provision)('t9');

    // The connection as Laravel's default mariadb connection resolves it: a URL wins over the separate values.
    $env = Dotenv::parse(File::get($path.'/.env'));
    $config = (new ConfigurationUrlParser)->parseConfiguration([
        'url' => $env['DB_URL'] ?? null,
        'database' => $env['DB_DATABASE'],
        'username' => $env['DB_USERNAME'],
        'password' => $env['DB_PASSWORD'],
    ]);
    $result = $this->server->runAs($config['username'], $config['password'], 'SELECT DATABASE()', $config['database']);

    expect($env['DB_URL'])->toBe('')
        ->and($config['username'])->toBe($this->prefix.'_t9')
        ->and(trim($result->output))->toBe($this->prefix.'_t9');
});
