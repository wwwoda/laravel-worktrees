<?php

use Woda\Worktrees\Shared\MariaDbServer;
use Woda\Worktrees\Shared\WorktreeNames;

/*
 * Runs against a real MariaDB. Set LW_TEST_MARIADB_HOST, _PORT and
 * _PASSWORD (root); see tests/Integration/README.md.
 */
beforeEach(function () {
    if (! getenv('LW_TEST_MARIADB_HOST')) {
        $this->markTestSkipped('LW_TEST_MARIADB_HOST is not set.');
    }
    $this->server = new MariaDbServer(
        driver: 'mariadb',
        container: null,
        host: (string) getenv('LW_TEST_MARIADB_HOST'),
        port: (int) (getenv('LW_TEST_MARIADB_PORT') ?: 3306),
        adminUser: 'root',
        adminPassword: (string) getenv('LW_TEST_MARIADB_PASSWORD'),
        client: (string) (getenv('LW_TEST_MARIADB_CLIENT') ?: 'mariadb'),
        dumpClient: (string) (getenv('LW_TEST_MARIADB_DUMP') ?: 'mariadb-dump'),
    );
    $this->prefix = 'lw'.substr(md5(uniqid()), 0, 6);
    $this->main = $this->prefix.'_main';
    $this->template = $this->prefix.'_tpl_abc123';
    foreach ([$this->main, $this->template] as $database) {
        $this->server->createDatabase($database);
        $admin = (new ReflectionMethod($this->server, 'admin'));
        $admin->invoke($this->server, "CREATE TABLE `{$database}`.`items` (id INT PRIMARY KEY); INSERT INTO `{$database}`.`items` VALUES (1);");
    }
    $this->worktree = function (string $name): array {
        $names = WorktreeNames::for($name, $this->prefix, 2);
        $names->assertSafe($this->main, $this->prefix.'_tpl_');
        $password = bin2hex(random_bytes(16));
        $this->server->grantUser($names->user, $password, $names->databases());
        $this->server->copyDatabase($this->template, $names->app);

        return [$names, $password];
    };
    $this->as = fn (array $worktree, string $sql) => $this->server->runAs($worktree[0]->user, $worktree[1], $sql);
});

afterEach(function () {
    if (! isset($this->server)) {
        return;
    }
    foreach ($this->server->databasesStartingWith($this->prefix.'_') as $database) {
        $this->server->dropDatabase($database);
    }
    foreach (['t9', 't9-1'] as $name) {
        $this->server->dropUser(WorktreeNames::for($name, $this->prefix, 2)->user);
    }
});

test('a worktree user reaches its own databases only', function () {
    $t9 = ($this->worktree)('t9');
    $t91 = ($this->worktree)('t9-1');
    [$names] = $t9;

    // Own databases: read, write, and create its own test and parallel test databases.
    expect(($this->as)($t9, "SELECT id FROM `{$names->app}`.`items`")->output)->toContain('1')
        ->and(($this->as)($t9, "INSERT INTO `{$names->app}`.`items` VALUES (2)")->successful)->toBeTrue()
        ->and(($this->as)($t9, "CREATE DATABASE `{$names->test}`")->successful)->toBeTrue()
        ->and(($this->as)($t9, "CREATE DATABASE `{$names->parallel[0]}`")->successful)->toBeTrue()
        ->and(($this->as)($t9, "CREATE TABLE `{$names->parallel[0]}`.`t` (id INT)")->successful)->toBeTrue();

    // Everything else fails.
    expect(($this->as)($t9, "CREATE DATABASE `{$this->prefix}_other`")->successful)->toBeFalse()
        ->and(($this->as)($t9, "SELECT id FROM `{$this->main}`.`items`")->successful)->toBeFalse()
        ->and(($this->as)($t9, "INSERT INTO `{$this->template}`.`items` VALUES (3)")->successful)->toBeFalse()
        ->and(($this->as)($t9, "DROP DATABASE `{$t91[0]->app}`")->successful)->toBeFalse()
        // `_` is a wildcard in GRANT: a look-alike name must not be covered.
        ->and(($this->as)($t9, 'CREATE DATABASE `'.str_replace('_t9', 'Xt9', $names->app).'`')->successful)->toBeFalse();
});

test('worktrees t9 and t9-1 cannot reach each other', function () {
    $t9 = ($this->worktree)('t9');
    $t91 = ($this->worktree)('t9-1');

    expect(($this->as)($t9, "SELECT id FROM `{$t91[0]->app}`.`items`")->successful)->toBeFalse()
        ->and(($this->as)($t91, "SELECT id FROM `{$t9[0]->app}`.`items`")->successful)->toBeFalse()
        ->and(($this->as)($t9, "CREATE DATABASE `{$t91[0]->test}`")->successful)->toBeFalse()
        ->and(($this->as)($t91, "DROP DATABASE `{$t9[0]->app}`")->successful)->toBeFalse()
        ->and($this->server->databaseExists($t9[0]->app))->toBeTrue();
});

test('the template copy carries the data and refuses to overwrite', function () {
    [$names] = ($this->worktree)('t9');

    expect($this->server->runAs('root', (string) getenv('LW_TEST_MARIADB_PASSWORD'), "SELECT COUNT(*) FROM `{$names->app}`.`items`")->output)->toContain('1')
        ->and(fn () => $this->server->copyDatabase($this->template, $names->app))->toThrow(RuntimeException::class, 'exists already');
});

test('a failed copy leaves no partial target behind', function () {
    expect(fn () => $this->server->copyDatabase($this->prefix.'_missing', $this->prefix.'_target'))->toThrow(RuntimeException::class)
        ->and($this->server->databaseExists($this->prefix.'_target'))->toBeFalse();
});

test('granting an existing user again removes rights it had before', function () {
    [$names, $password] = ($this->worktree)('t9');
    $admin = new ReflectionMethod($this->server, 'admin');
    $admin->invoke($this->server, "GRANT SELECT ON `{$this->main}`.* TO '{$names->user}'@'%'");

    $this->server->grantUser($names->user, $password, $names->databases());

    expect($this->server->runAs($names->user, $password, "SELECT id FROM `{$this->main}`.`items`")->successful)->toBeFalse()
        ->and($this->server->runAs($names->user, $password, "SELECT id FROM `{$names->app}`.`items`")->successful)->toBeTrue();
});

test('a copy gives views of a retired definer to the new owner', function () {
    $builder = $this->prefix.'_vb';
    $this->server->grantUser($builder, str_repeat('b', 32), [$this->template]);
    expect($this->server->runAs($builder, str_repeat('b', 32), 'CREATE VIEW item_ids AS SELECT id FROM items', $this->template)->successful)->toBeTrue();
    $this->server->retireUser($builder);
    $names = WorktreeNames::for('t9', $this->prefix, 2);
    $password = bin2hex(random_bytes(16));
    $this->server->grantUser($names->user, $password, $names->databases());

    $this->server->copyDatabase($this->template, $names->app, $names->user);

    expect($this->server->runAs($names->user, $password, "SELECT id FROM `{$names->app}`.`item_ids`")->output)->toContain('1')
        ->and($this->server->runAs($builder, str_repeat('b', 32), 'SELECT 1')->successful)->toBeFalse();
    $this->server->dropUser($builder);
});

test('a copy gives every object of a retired definer to the new owner and keeps text values', function () {
    $builder = $this->prefix.'_vb';
    $this->server->grantUser($builder, str_repeat('b', 32), [$this->template]);
    $clause = "DEFINER=`{$builder}`@`%`";
    $values = ["'{$clause}'", "'/*!50013 {$clause} SQL SECURITY DEFINER */'", "'/*!50003 CREATE*/ /*!50017 {$clause}*/'"];
    foreach ([
        'CREATE TABLE snippets (id INT PRIMARY KEY, body TEXT)',
        'INSERT INTO snippets VALUES (1, '.$values[0].'), (2, '.$values[1].'), (3, '.$values[2].')',
        'CREATE VIEW snippet_bodies AS SELECT body FROM snippets',
        'CREATE TRIGGER snippets_keep BEFORE UPDATE ON snippets FOR EACH ROW SET NEW.body = NEW.body',
        "CREATE PROCEDURE snippet_clause() SELECT '{$clause}'",
        'CREATE EVENT snippets_tick ON SCHEDULE EVERY 1 DAY DO SELECT 1',
    ] as $sql) {
        expect($this->server->runAs($builder, str_repeat('b', 32), $sql, $this->template)->successful)->toBeTrue();
    }
    $this->server->retireUser($builder);
    $names = WorktreeNames::for('t9', $this->prefix, 2);
    $password = bin2hex(random_bytes(16));
    $this->server->grantUser($names->user, $password, $names->databases());

    $this->server->copyDatabase($this->template, $names->app, $names->user);

    $root = fn (string $sql) => $this->server->runAs('root', (string) getenv('LW_TEST_MARIADB_PASSWORD'), $sql)->output;
    $schema = "'{$names->app}'";
    expect($root("SELECT body FROM `{$names->app}`.`snippets` ORDER BY id"))
        ->toContain($clause)->toContain("/*!50013 {$clause} SQL SECURITY DEFINER */")->toContain("/*!50003 CREATE*/ /*!50017 {$clause}*/")
        ->and($root("SELECT DEFINER FROM information_schema.VIEWS WHERE TABLE_SCHEMA = {$schema}"
            ." UNION ALL SELECT DEFINER FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = {$schema}"
            ." UNION ALL SELECT DEFINER FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = {$schema}"
            ." UNION ALL SELECT DEFINER FROM information_schema.EVENTS WHERE EVENT_SCHEMA = {$schema}"))
        ->toBe(str_repeat("{$names->user}@%\n", 4))
        ->and($this->server->runAs($names->user, $password, 'CALL snippet_clause()', $names->app)->output)->toContain($clause);
    $this->server->dropUser($builder);
});
