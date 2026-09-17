<?php

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Woda\Worktrees\Contracts\BootstrapStrategy;
use Woda\Worktrees\DatabaseCloner;
use Woda\Worktrees\SailBootstrapStrategy;
use Woda\Worktrees\WorktreeManager;

function lifecycleManager(string $directory, ?BootstrapStrategy $strategy = null): WorktreeManager
{
    return new WorktreeManager(
        basePath: $directory, branchPrefix: '', baseBranch: 'master', copyFiles: [],
        databaseCloner: new DatabaseCloner('none', false, null, null, '127.0.0.1'),
        bootstrapStrategy: $strategy ?? Mockery::mock(BootstrapStrategy::class),
        buildFrontend: false, runMigrations: false,
    );
}

test('a remote ticket branch takes precedence over the default base', function () {
    $manager = lifecycleManager(sys_get_temp_dir().'/missing-'.uniqid());
    Process::fake([
        "git show-ref --verify --quiet 'refs/heads/agent/ticket'" => Process::result(exitCode: 1),
        '*' => Process::result(),
    ]);
    $manager->create('ticket', 'agent/ticket');
    Process::assertRan(fn (PendingProcess $process) => str_contains($process->command, "'origin/agent/ticket'"));
    Process::assertNotRan(fn (PendingProcess $process) => str_contains($process->command, "'master'"));
});

test('bootstrap resumes after a failed stage without rewriting lane configuration', function () {
    $directory = sys_get_temp_dir().'/worktrees-lifecycle-'.uniqid();
    $strategy = Mockery::mock(BootstrapStrategy::class);
    $strategy->shouldReceive('bringUp')->twice();
    $strategy->shouldReceive('installComposerDependencies')->once()->andThrow(new RuntimeException('install failed'));
    $strategy->shouldReceive('installComposerDependencies')->once();
    $strategy->shouldReceive('installNodeDependencies')->once();
    $manager = lifecycleManager($directory, $strategy);
    $path = $manager->pathFor('ticket');
    File::ensureDirectoryExists($path);
    Process::fake(['git worktree list --porcelain' => Process::result("worktree {$path}\nHEAD abc\nbranch refs/heads/ticket\n\n")]);
    try {
        expect(fn () => $manager->bootstrap('ticket'))->toThrow(RuntimeException::class, 'install failed');
        File::put($path.'/.env', "LANE_SETTING=preserve\n");
        File::put($path.'/partial.txt', 'unfinished work');
        $manager->bootstrap('ticket', ['resume' => true]);
        expect(File::get($path.'/.env'))->toBe("LANE_SETTING=preserve\n")
            ->and(File::get($path.'/partial.txt'))->toBe('unfinished work')
            ->and($manager->describe('ticket')['bootstrap']['status'])->toBe('ready');
    } finally {
        File::deleteDirectory($directory);
    }
});

test('remove refuses a directory that git does not register', function () {
    $directory = sys_get_temp_dir().'/worktrees-orphan-'.uniqid();
    $manager = lifecycleManager($directory);
    $path = $manager->pathFor('orphan');
    File::ensureDirectoryExists($path);
    Process::fake(['git worktree list --porcelain' => Process::result()]);
    try {
        expect(fn () => $manager->remove('orphan'))->toThrow(RuntimeException::class, 'not a registered worktree')
            ->and(is_dir($path))->toBeTrue();
        Process::assertNotRan('git worktree prune');
    } finally {
        File::deleteDirectory($directory);
    }
});

test('bootstrap refuses an unregistered directory', function () {
    Process::fake(['git worktree list --porcelain' => Process::result()]);
    $manager = lifecycleManager(sys_get_temp_dir());
    expect(fn () => $manager->bootstrap('unknown', ['resume' => true]))->toThrow(RuntimeException::class, 'not a registered worktree');
});

test('teardown includes profiles and clears parent variables without printing credentials', function () {
    $directory = sys_get_temp_dir().'/worktrees-compose-'.uniqid();
    File::ensureDirectoryExists($directory);
    File::put($directory.'/.env', "COMPOSE_PROJECT_NAME=child\nAPP_PORT=9999\nFAKE_SECRET=synthetic\n");
    Process::fake();
    try {
        (new SailBootstrapStrategy('laravel.test', 'pnpm', false))->tearDown($directory);
        Process::assertRan(fn (PendingProcess $process) => $process->command === 'docker compose --profile "*" down -v'
            && $process->path === $directory
            && $process->environment['COMPOSE_PROJECT_NAME'] === false
            && $process->environment['APP_PORT'] === false
            && $process->environment['FAKE_SECRET'] === false);
    } finally {
        File::deleteDirectory($directory);
    }
});

test('retaining the database keeps compose volumes', function () {
    Process::fake();
    (new SailBootstrapStrategy('app', 'pnpm', false))->tearDownKeepingVolumes('/tmp/nonexistent');
    Process::assertRan('docker compose --profile "*" down');
    Process::assertNotRan('docker compose --profile "*" down -v');
});

test('failed teardown does not report success', function () {
    Process::fake(['*' => Process::result(exitCode: 1)]);
    expect(fn () => (new SailBootstrapStrategy('app', 'pnpm', false))->tearDown('/tmp/nonexistent'))
        ->toThrow(RuntimeException::class, 'checkout retained');
});

test('failed git status cannot be treated as a clean worktree', function () {
    Process::fake(['*' => Process::result(exitCode: 128)]);
    expect(fn () => lifecycleManager('/tmp')->safetyCheck('ticket'))->toThrow(RuntimeException::class);
});

test('real git attaches the remote ticket commit without moving the main checkout', function () {
    $directory = sys_get_temp_dir().'/worktrees-git-'.uniqid();
    $repo = $directory.'/project';
    File::ensureDirectoryExists($repo);
    $oldBase = base_path();
    $git = function (array $arguments) use ($repo): string {
        $result = Process::path($repo)->run(['git', ...$arguments]);
        if (! $result->successful()) {
            throw new RuntimeException($result->errorOutput());
        }

        return trim($result->output());
    };
    try {
        $git(['init', '-b', 'master']);
        $git(['config', 'user.name', 'Lifecycle Test']);
        $git(['config', 'user.email', 'test@example.invalid']);
        $git(['config', 'commit.gpgsign', 'false']);
        $git(['commit', '--allow-empty', '-m', 'Base fixture']);
        $base = $git(['rev-parse', 'HEAD']);
        $git(['switch', '-c', 'fixture']);
        File::put($repo.'/ticket.txt', 'remote ticket content');
        $git(['add', 'ticket.txt']);
        $git(['commit', '-m', 'Ticket fixture']);
        $head = $git(['rev-parse', 'HEAD']);
        $git(['update-ref', 'refs/remotes/origin/ticket', $head]);
        $git(['switch', 'master']);
        $this->app->setBasePath($repo);
        $manager = lifecycleManager($directory);
        $created = $manager->create('lane', 'ticket');
        expect(File::get($created.'/ticket.txt'))->toBe('remote ticket content')
            ->and($manager->describe('lane')['head'])->toBe($head)
            ->and($git(['rev-parse', 'HEAD']))->toBe($base)
            ->and($git(['branch', '--show-current']))->toBe('master');
    } finally {
        $this->app->setBasePath($oldBase);
        File::deleteDirectory($directory);
    }
});
