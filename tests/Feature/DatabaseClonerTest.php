<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Woda\Worktrees\DatabaseCloner;

test('resolveStrategy returns sqlite for auto strategy when DB is sqlite', function () {
    config()->set('database.default', 'sqlite');

    $cloner = new DatabaseCloner(
        strategy: 'auto',
        sqliteCopy: true,
        mysqlDockerContainer: null,
        pgsqlDockerContainer: null,
        dockerHost: '127.0.0.1',
    );

    expect($cloner->resolveStrategy())->toBe('sqlite');
});

test('resolveStrategy respects explicit none strategy', function () {
    $cloner = new DatabaseCloner(
        strategy: 'none',
        sqliteCopy: true,
        mysqlDockerContainer: null,
        pgsqlDockerContainer: null,
        dockerHost: '127.0.0.1',
    );

    expect($cloner->resolveStrategy())->toBe('none');
});

test('resolveStrategy respects explicit mysql strategy', function () {
    $cloner = new DatabaseCloner(
        strategy: 'mysql',
        sqliteCopy: true,
        mysqlDockerContainer: null,
        pgsqlDockerContainer: null,
        dockerHost: '127.0.0.1',
    );

    expect($cloner->resolveStrategy())->toBe('mysql');
});

test('resolveStrategy respects explicit sqlite strategy', function () {
    $cloner = new DatabaseCloner(
        strategy: 'sqlite',
        sqliteCopy: true,
        mysqlDockerContainer: null,
        pgsqlDockerContainer: null,
        dockerHost: '127.0.0.1',
    );

    expect($cloner->resolveStrategy())->toBe('sqlite');
});

test('listCloned returns empty for non-mysql strategies', function () {
    $cloner = new DatabaseCloner(
        strategy: 'sqlite',
        sqliteCopy: true,
        mysqlDockerContainer: null,
        pgsqlDockerContainer: null,
        dockerHost: '127.0.0.1',
    );

    expect($cloner->listCloned())->toBe([]);
});

test('clone does nothing for none strategy', function () {
    $cloner = new DatabaseCloner(
        strategy: 'none',
        sqliteCopy: true,
        mysqlDockerContainer: null,
        pgsqlDockerContainer: null,
        dockerHost: '127.0.0.1',
    );

    // Should not throw
    $cloner->clone('/tmp/nonexistent', 'test');
    expect(true)->toBeTrue();
});

test('drop does nothing for none strategy', function () {
    $cloner = new DatabaseCloner(
        strategy: 'none',
        sqliteCopy: true,
        mysqlDockerContainer: null,
        pgsqlDockerContainer: null,
        dockerHost: '127.0.0.1',
    );

    // Should not throw
    $cloner->drop('test');
    expect(true)->toBeTrue();
});

test('targetExists fails when the server cannot be asked, instead of reporting no database', function (string $strategy, string $default) {
    config()->set('database.default', $default);
    config()->set("database.connections.{$default}.database", 'app');
    Process::fake(['*' => Process::result(errorOutput: 'connection refused', exitCode: 1)]);
    $cloner = new DatabaseCloner(
        strategy: $strategy,
        sqliteCopy: true,
        mysqlDockerContainer: null,
        pgsqlDockerContainer: null,
        dockerHost: '127.0.0.1',
    );

    expect(fn () => $cloner->targetExists('/tmp/none', 'lane'))->toThrow(RuntimeException::class, 'connection refused')
        ->and($cloner->listCloned())->toBe([]);
})->with(['mysql' => ['mysql', 'mysql'], 'pgsql' => ['pgsql', 'pgsql']]);

test('a SQLite clone points .env at a path that works on the host and in a container', function () {
    $source = database_path('database.sqlite');
    $existed = file_exists($source);
    if (! $existed) {
        file_put_contents($source, '');
    }
    $worktree = sys_get_temp_dir().'/worktrees-sqlite-'.uniqid();
    mkdir($worktree);
    file_put_contents($worktree.'/.env', "DB_CONNECTION=sqlite\nDB_DATABASE=/old/host/path.sqlite\n");
    $cloner = new DatabaseCloner(strategy: 'sqlite', sqliteCopy: true, mysqlDockerContainer: null, pgsqlDockerContainer: null, dockerHost: '127.0.0.1');

    try {
        $cloner->clone($worktree, 'lane');
        expect(file_get_contents($worktree.'/.env'))->toContain("DB_DATABASE=database/database.sqlite\n")
            ->and(file_exists($worktree.'/database/database.sqlite'))->toBeTrue();
    } finally {
        if (! $existed) {
            unlink($source);
        }
        File::deleteDirectory($worktree);
    }
});
