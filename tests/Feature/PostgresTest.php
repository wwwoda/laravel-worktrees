<?php

use Illuminate\Support\Facades\File;
use Woda\Worktrees\Shared\DatabaseProvisioner;
use Woda\Worktrees\Shared\DatabaseServer;
use Woda\Worktrees\Shared\PostgresServer;
use Woda\Worktrees\Shared\RecreateDatabasesGuard;
use Woda\Worktrees\Shared\WorktreeState;
use Woda\Worktrees\WorktreesServiceProvider;

test('--recreate-databases is refused with a clear message in a PostgreSQL worktree only', function () {
    $dir = sys_get_temp_dir().'/worktrees-recreate-'.uniqid();
    (new WorktreeState(['name' => 't9']))->save($dir);

    try {
        expect(fn () => RecreateDatabasesGuard::check(true, 'pgsql', $dir))->toThrow(RuntimeException::class, 'without --recreate-databases')
            ->and(fn () => RecreateDatabasesGuard::check(false, 'pgsql', $dir))->not->toThrow(RuntimeException::class)
            ->and(fn () => RecreateDatabasesGuard::check(true, 'mysql', $dir))->not->toThrow(RuntimeException::class)
            ->and(fn () => RecreateDatabasesGuard::check(true, 'pgsql', $dir.'/elsewhere'))->not->toThrow(RuntimeException::class);
    } finally {
        File::deleteDirectory($dir);
    }
});

test('the pgsql driver gives a PostgreSQL server with psql defaults', function () {
    config()->set('worktrees.shared.database.driver', 'pgsql');

    $server = app(DatabaseServer::class);

    expect($server)->toBeInstanceOf(PostgresServer::class)
        ->and($server->requiresDatabasesInAdvance())->toBeTrue();
});

test('the recreate guard runs at boot from the worker environment, before Laravel drops anything', function () {
    $dir = sys_get_temp_dir().'/worktrees-recreate-boot-'.uniqid();
    (new WorktreeState(['name' => 't9']))->save($dir);
    $base = base_path();
    app()->setBasePath($dir);
    config()->set('database.default', 'pgsql');
    config()->set('database.connections.pgsql.driver', 'pgsql');
    $_SERVER['LARAVEL_PARALLEL_TESTING_RECREATE_DATABASES'] = '1';

    try {
        expect(fn () => WorktreesServiceProvider::guardRecreateDatabases())->toThrow(RuntimeException::class, 'without --recreate-databases');
        $_SERVER['LARAVEL_PARALLEL_TESTING_RECREATE_DATABASES'] = false;
        expect(fn () => WorktreesServiceProvider::guardRecreateDatabases())->not->toThrow(RuntimeException::class);
    } finally {
        unset($_SERVER['LARAVEL_PARALLEL_TESTING_RECREATE_DATABASES']);
        app()->setBasePath($base);
        File::deleteDirectory($dir);
    }
});

test('PostgreSQL gets its own default ports and host unless config names them', function () {
    config()->set('worktrees.shared.database.driver', 'pgsql');
    config()->set('worktrees.shared.database.port', null);
    config()->set('worktrees.shared.database.app_host', null);
    config()->set('worktrees.shared.database.app_port', null);
    config()->set('worktrees.shared.database.main', 'app');

    $provisioner = app(DatabaseProvisioner::class);
    $read = fn (string $property) => (new ReflectionProperty($provisioner, $property))->getValue($provisioner);
    $server = app(DatabaseServer::class);

    expect($read('appHost'))->toBe('pgsql')
        ->and($read('appPort'))->toBe(5432)
        ->and((new ReflectionProperty($server, 'port'))->getValue($server))->toBe(5432);

    config()->set('worktrees.shared.database.app_port', '6543');
    $provisioner = app(DatabaseProvisioner::class);
    expect((new ReflectionProperty($provisioner, 'appPort'))->getValue($provisioner))->toBe(6543);
});
