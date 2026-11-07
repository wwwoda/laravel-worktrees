<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Woda\Worktrees\Contracts\TemplateRecipe;
use Woda\Worktrees\Shared\DatabaseProvisioner;
use Woda\Worktrees\Shared\DatabaseServer;
use Woda\Worktrees\Shared\PostgresServer;
use Woda\Worktrees\Shared\TemplateManager;
use Woda\Worktrees\Shared\WorktreeState;

/*
 * The guard and the template copies against a real PostgreSQL 17. Set
 * LW_TEST_PGSQL_HOST, _PORT and _PASSWORD (postgres); see README.md here.
 */
beforeEach(function () {
    if (! getenv('LW_TEST_PGSQL_HOST')) {
        $this->markTestSkipped('LW_TEST_PGSQL_HOST is not set.');
    }
    $this->rootPassword = (string) getenv('LW_TEST_PGSQL_PASSWORD');
    $this->server = new PostgresServer(null, (string) getenv('LW_TEST_PGSQL_HOST'), (int) (getenv('LW_TEST_PGSQL_PORT') ?: 5432),
        'postgres', $this->rootPassword);
    $this->prefix = 'lw'.substr(md5(uniqid()), 0, 6);
    $this->base = sys_get_temp_dir().'/worktrees-pg-'.uniqid();
    File::ensureDirectoryExists($this->base);
    // The main database, owned by its own role, with a table.
    $this->server->grantUser($this->prefix.'_mainrole', str_repeat('m', 32), []);
    $this->server->createDatabase($this->prefix.'_main', $this->prefix.'_mainrole');
    $this->server->runAs('postgres', $this->rootPassword, 'CREATE TABLE items (id INT); INSERT INTO items VALUES (1); ALTER TABLE items OWNER TO "'.$this->prefix.'_mainrole"', $this->prefix.'_main');

    config()->set('worktrees.shared.database.prefix', $this->prefix);
    config()->set('worktrees.shared.database.main', $this->prefix.'_main');
    config()->set('worktrees.shared.database.parallel_workers', 2);
    config()->set('worktrees.shared.database.template.hash_paths', []);
    config()->set('worktrees.shared.database.template.seed_command', 'seed '.$this->prefix);
    app()->instance(DatabaseServer::class, $this->server);
    $server = $this->server;
    app()->instance(TemplateRecipe::class, new class($server) implements TemplateRecipe
    {
        public function __construct(private readonly PostgresServer $server) {}

        public function build(string $database, string $user, string $password, string $seedCommand, ?Closure $output = null): void
        {
            $result = $this->server->runAs($user, $password, "CREATE TABLE users (id SERIAL PRIMARY KEY, email TEXT); INSERT INTO users (email) VALUES ('test@example.com');", $database);
            if (! $result->successful) {
                throw new RuntimeException($result->error);
            }
        }
    });
    $this->paths = [];
    Process::fake(['git worktree list --porcelain' => fn () => Process::result(implode('', array_map(
        fn (string $path) => "worktree {$path}\nHEAD abc\nbranch refs/heads/x\n\n", $this->paths)))]);
    $this->provision = function (string $name, bool $fromMain = false): WorktreeState {
        $path = $this->base.'/'.$name;
        File::ensureDirectoryExists($path);
        $this->paths[] = $path;

        return app(DatabaseProvisioner::class)->provision($name, $path, fromMain: $fromMain);
    };
    $this->as = fn (WorktreeState $state, string $sql, ?string $database = null) => $this->server->runAs((string) $state->string('user'), (string) $state->string('password'), $sql, $database);
});

afterEach(function () {
    if (! isset($this->server)) {
        return;
    }
    foreach ($this->server->databasesStartingWith($this->prefix.'_') as $database) {
        $this->server->dropDatabase($database);
    }
    foreach (['t9', 't9_1', 'tpl_builder', 'mainrole'] as $user) {
        $this->server->dropUser($this->prefix.'_'.$user);
    }
    File::deleteDirectory($this->base);
    File::deleteDirectory(base_path('storage/app/worktrees'));
});

test('a worktree role reaches its own databases only', function () {
    $t9 = ($this->provision)('t9');
    $t91 = ($this->provision)('t9-1');
    $template = app(TemplateManager::class)->name();
    $p = $this->prefix;

    // Own databases: read and write the template copy, use the parallel test databases.
    expect(($this->as)($t9, 'SELECT email FROM users', "{$p}_t9")->output)->toContain('test@example.com')
        ->and(($this->as)($t9, "INSERT INTO users (email) VALUES ('mine')", "{$p}_t9")->successful)->toBeTrue()
        ->and(($this->as)($t9, 'DROP TABLE users; CREATE TABLE fresh (id INT)', "{$p}_t9_e2e")->successful)->toBeTrue()
        ->and(($this->as)($t9, 'CREATE TABLE t (id INT); DROP TABLE t', "{$p}_t9_test_test_2")->successful)->toBeTrue();

    // Everything else fails.
    expect(($this->as)($t9, "CREATE DATABASE {$p}_other")->successful)->toBeFalse()
        ->and(($this->as)($t9, 'SELECT * FROM items', "{$p}_main")->successful)->toBeFalse()
        ->and(($this->as)($t9, "INSERT INTO users (email) VALUES ('x')", $template)->successful)->toBeFalse()
        ->and(($this->as)($t9, 'SELECT 1', "{$p}_t9_1")->successful)->toBeFalse()
        ->and(($this->as)($t91, "DROP DATABASE {$p}_t9")->successful)->toBeFalse()
        ->and($this->server->databaseExists("{$p}_t9"))->toBeTrue();
});

test('--recreate-databases cannot work: the role cannot create databases, all exist in advance', function () {
    $t9 = ($this->provision)('t9');
    $p = $this->prefix;

    expect($this->server->databaseExists("{$p}_t9_test_test_1"))->toBeTrue()
        ->and($this->server->databaseExists("{$p}_t9_test_test_2"))->toBeTrue()
        ->and(($this->as)($t9, "DROP DATABASE {$p}_t9_test_test_1")->successful)->toBeTrue()
        ->and(($this->as)($t9, "CREATE DATABASE {$p}_t9_test_test_1")->successful)->toBeFalse();
});

test('the template builder can no longer log in, and a second provisioning keeps the data', function () {
    $t9 = ($this->provision)('t9');
    ($this->as)($t9, "INSERT INTO users (email) VALUES ('mine@example.com')", "{$this->prefix}_t9");

    $again = app(DatabaseProvisioner::class)->provision('t9', $this->base.'/t9');

    expect(($this->as)($again, 'SELECT email FROM users', "{$this->prefix}_t9")->output)->toContain('mine@example.com')
        ->and($this->server->runAs($this->prefix.'_tpl_builder', 'x', 'SELECT 1')->successful)->toBeFalse();
});

test('--from-main copies a main database that has open connections', function () {
    config()->set('worktrees.shared.database.allow_from_main', true);
    // Hold a connection to the main database while copying.
    $holder = proc_open(['psql', '-h', (string) getenv('LW_TEST_PGSQL_HOST'), '-U', 'postgres', '-d', $this->prefix.'_main', '-c', 'SELECT pg_sleep(6)'],
        [], $pipes, null, ['PGPASSWORD' => $this->rootPassword, 'PATH' => (string) getenv('PATH')]);
    usleep(800_000);

    $state = ($this->provision)('t9', true);
    proc_close($holder);

    expect(($this->as)($state, 'SELECT id FROM items', "{$this->prefix}_t9")->output)->toContain('1');
});
