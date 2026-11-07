<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Woda\Worktrees\Contracts\TemplateRecipe;
use Woda\Worktrees\Shared\DatabaseProvisioner;
use Woda\Worktrees\Shared\DatabaseServer;
use Woda\Worktrees\Shared\MariaDbServer;
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
            $result = $this->server->runAs($user, $password, 'CREATE TABLE users (email VARCHAR(100)); INSERT INTO users VALUES (\'test@example.com\');', $database);
            if (! $result->successful) {
                throw new RuntimeException($result->error);
            }
        }
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
    foreach (['t9', 't9_1', 'tpl_builder'] as $user) {
        $this->server->dropUser($this->prefix.'_'.$user);
    }
    File::deleteDirectory($this->base);
    File::deleteDirectory(base_path('storage/app/worktrees'));
});

test('provisioning copies the template and gives each worktree its own guarded user', function () {
    $t9 = ($this->provision)('t9');
    $t91 = ($this->provision)('t9-1');
    $template = app(TemplateManager::class)->name();
    $query = fn (WorktreeState $state, string $sql) => $this->server->runAs((string) $state->string('user'), (string) $state->string('password'), $sql);

    expect($query($t9, "SELECT email FROM `{$this->prefix}_t9`.users")->output)->toContain('test@example.com')
        ->and($query($t9, "SELECT email FROM `{$this->prefix}_t9_e2e`.users")->output)->toContain('test@example.com')
        ->and($query($t9, "SELECT email FROM `{$this->prefix}_t9_1`.users")->successful)->toBeFalse()
        ->and($query($t91, "INSERT INTO `{$template}`.users VALUES ('x')")->successful)->toBeFalse()
        ->and($query($t91, "SELECT 1 FROM `{$this->prefix}_main`.users")->successful)->toBeFalse()
        ->and($t9->redis())->not->toBe($t91->redis())
        ->and($this->server->userExists($this->prefix.'_tpl_builder'))->toBeFalse();
});

test('provisioning again keeps the data of an existing database', function () {
    $t9 = ($this->provision)('t9');
    $this->server->runAs((string) $t9->string('user'), (string) $t9->string('password'), "INSERT INTO `{$this->prefix}_t9`.users VALUES ('mine@example.com')");

    $again = app(DatabaseProvisioner::class)->provision('t9', $this->base.'/t9');

    expect($this->server->runAs((string) $again->string('user'), (string) $again->string('password'), "SELECT email FROM `{$this->prefix}_t9`.users")->output)
        ->toContain('mine@example.com');
});
