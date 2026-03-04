<?php

use Illuminate\Support\Facades\File;
use Woda\Worktrees\DatabaseCloner;
use Woda\Worktrees\WorktreeManager;

function makeManager(): WorktreeManager
{
    $cloner = new DatabaseCloner(
        strategy: 'none',
        sqliteCopy: true,
        mysqlDockerContainer: null,
        pgsqlDockerContainer: null,
        dockerHost: '127.0.0.1',
    );

    return new WorktreeManager(
        basePath: sys_get_temp_dir(),
        branchPrefix: '',
        baseBranch: 'master',
        copyFiles: ['.env'],
        databaseCloner: $cloner,
        nodePackageManager: 'pnpm',
        buildFrontend: false,
        runMigrations: false,
    );
}

test('portOffset returns consistent results for same name', function () {
    $manager = makeManager();

    $a = $manager->portOffset('my-feature');
    $b = $manager->portOffset('my-feature');

    expect($a)->toBe($b);
});

test('portOffset returns different results for different names', function () {
    $manager = makeManager();

    $a = $manager->portOffset('feature-one');
    $b = $manager->portOffset('feature-two');

    expect($a)->not->toBe($b);
});

test('portOffset stays within 0-899 range', function () {
    $manager = makeManager();

    $names = ['a', 'foo', 'my-long-worktree-name', 'x123', 'z'];

    foreach ($names as $name) {
        $offset = $manager->portOffset($name);
        expect($offset)->toBeGreaterThanOrEqual(0)->toBeLessThan(900);
    }
});

test('env replacement includes APP_PORT when key exists', function () {
    $dir = sys_get_temp_dir().'/worktree-port-test-'.uniqid();
    mkdir($dir, 0755, true);
    file_put_contents($dir.'/.env', implode("\n", [
        'APP_NAME=TestApp',
        'APP_PORT=80',
        'VITE_PORT=5173',
    ]));

    config()->set('app.name', 'TestApp');
    config()->set('app.url', '');
    config()->set('worktrees.ports.app_base', 8100);
    config()->set('worktrees.ports.vite_base', 5200);

    // Use reflection to call applyEnvReplacements
    $manager = makeManager();
    $method = new ReflectionMethod($manager, 'applyEnvReplacements');

    $method->invoke($manager, $dir, 'my-feature');

    $content = file_get_contents($dir.'/.env');
    $offset = $manager->portOffset('my-feature');

    expect($content)->toContain('APP_PORT='.(8100 + $offset));
    expect($content)->toContain('VITE_PORT='.(5200 + $offset));

    // Cleanup
    File::deleteDirectory($dir);
});

test('env replacement skips APP_PORT when key missing from source', function () {
    $dir = sys_get_temp_dir().'/worktree-port-test-'.uniqid();
    mkdir($dir, 0755, true);
    file_put_contents($dir.'/.env', implode("\n", [
        'APP_NAME=TestApp',
        'DB_DATABASE=mydb',
    ]));

    config()->set('app.name', 'TestApp');
    config()->set('app.url', '');

    $manager = makeManager();
    $method = new ReflectionMethod($manager, 'applyEnvReplacements');

    $method->invoke($manager, $dir, 'my-feature');

    $content = file_get_contents($dir.'/.env');

    expect($content)->not->toContain('APP_PORT');
    expect($content)->not->toContain('VITE_PORT');

    File::deleteDirectory($dir);
});
