<?php

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Woda\Worktrees\Contracts\BootstrapStrategy;
use Woda\Worktrees\DatabaseCloner;
use Woda\Worktrees\WorktreeManager;

/*
 * worktree:create --branch=X (and --pr=N) for a branch that exists only on
 * the remote: the new local branch starts at origin/X and tracks it, also
 * when origin/X was not fetched before.
 */
beforeEach(function () {
    $this->base = sys_get_temp_dir().'/worktrees-remote-'.uniqid();
    File::ensureDirectoryExists($this->base);
    config()->set('worktrees.base_path', $this->base);
    $this->fetched = false;
    $this->fetch = fn () => tap(Process::result(), fn () => $this->fetched = true);
    $this->fake = function (array $extra = []) {
        Process::fake($extra + [
            "git show-ref --verify --quiet 'refs/remotes/origin/feature-x'" => fn () => Process::result(exitCode: $this->fetched ? 0 : 1),
            'git show-ref *' => Process::result(exitCode: 1),
            "git fetch origin '+refs/heads/feature-x:refs/remotes/origin/feature-x'" => fn () => ($this->fetch)(),
            '*' => Process::result(),
        ]);
    };
});

afterEach(function () {
    File::deleteDirectory($this->base);
});

it('bases a branch that exists only on the remote on its remote head, with tracking', function () {
    ($this->fake)();

    app(WorktreeManager::class)->create('x', 'feature-x');

    $path = app(WorktreeManager::class)->pathFor('x');
    Process::assertRan("git worktree add --no-track -b 'feature-x' '{$path}' 'origin/feature-x'");
    Process::assertRan("git config 'branch.feature-x.merge' 'refs/heads/feature-x'");
    Process::assertNotRan(fn (PendingProcess $p) => str_contains((string) $p->command, "'master'"));
});

it('makes a new branch from the base branch when the remote does not have it', function () {
    $this->fetch = fn () => Process::result(errorOutput: "fatal: couldn't find remote ref refs/heads/feature-x", exitCode: 128);
    ($this->fake)();

    app(WorktreeManager::class)->create('x', 'feature-x');

    $path = app(WorktreeManager::class)->pathFor('x');
    Process::assertRan("git worktree add -b 'feature-x' '{$path}' 'master'");
});

it('stops when the remote cannot be asked for a branch that was named', function () {
    $this->fetch = fn () => Process::result(errorOutput: 'fatal: unable to access: Could not resolve host', exitCode: 128);
    ($this->fake)();

    expect(fn () => app(WorktreeManager::class)->create('x', 'feature-x'))
        ->toThrow(RuntimeException::class, 'Cannot fetch the branch feature-x from origin');
    Process::assertNotRan(fn (PendingProcess $p) => str_contains((string) $p->command, 'worktree add'));
});

it('creates the worktree of a pull request on the remote head of its branch', function () {
    ($this->fake)(['gh pr view 7 *' => Process::result('{"headRefName":"feature-x","isCrossRepository":false}')]);

    $this->artisan('worktree:create', ['--pr' => 7, '--skip-deps' => true, '--skip-build' => true, '--skip-db' => true, '--no-interaction' => true]);

    Process::assertRan(fn (PendingProcess $p) => str_starts_with((string) $p->command, "git worktree add --no-track -b 'feature-x' ")
        && str_ends_with((string) $p->command, "'origin/feature-x'"));
});

it('with real git, checks out a branch that a single-branch clone never fetched, and tracks it', function () {
    $origin = $this->base.'/origin.git';
    $seed = $this->base.'/seed';
    $repo = $this->base.'/project';
    $run = function (string $dir, array $arguments): string {
        $result = Process::path($dir)->run(['git', '-c', 'user.name=T', '-c', 'user.email=t@example.invalid', '-c', 'commit.gpgsign=false', ...$arguments]);
        if (! $result->successful()) {
            throw new RuntimeException($result->errorOutput());
        }

        return trim($result->output());
    };
    File::ensureDirectoryExists($seed);
    $run($this->base, ['init', '--bare', '-b', 'master', $origin]);
    $run($seed, ['init', '-b', 'master']);
    $run($seed, ['commit', '--allow-empty', '-m', 'Base']);
    $run($seed, ['remote', 'add', 'origin', $origin]);
    $run($seed, ['push', 'origin', 'master']);
    // A clone that fetches only master never sees feature-x.
    $run($this->base, ['clone', '--single-branch', '-b', 'master', $origin, $repo]);
    $run($seed, ['switch', '-c', 'feature-x']);
    $run($seed, ['commit', '--allow-empty', '-m', 'Feature']);
    $head = $run($seed, ['rev-parse', 'HEAD']);
    $run($seed, ['push', 'origin', 'feature-x']);
    $oldBase = base_path();
    $this->app->setBasePath($repo);
    try {
        $manager = new WorktreeManager(
            basePath: $this->base, branchPrefix: '', baseBranch: 'master', copyFiles: [],
            databaseCloner: new DatabaseCloner('none', false, null, null, '127.0.0.1'),
            bootstrapStrategy: Mockery::mock(BootstrapStrategy::class),
            buildFrontend: false, runMigrations: false,
        );
        $path = $manager->create('x', 'feature-x');

        expect($run($path, ['rev-parse', 'HEAD']))->toBe($head)
            // `git pull` and `git push` in the worktree use origin's feature-x.
            ->and($run($path, ['config', 'branch.feature-x.remote']))->toBe('origin')
            ->and($run($path, ['config', 'branch.feature-x.merge']))->toBe('refs/heads/feature-x')
            ->and($run($repo, ['rev-parse', 'HEAD']))->not->toBe($head);

        // After a push from the worktree, origin/feature-x follows, so the
        // delete's check for unpushed commits finds none.
        $run($path, ['commit', '--allow-empty', '-m', 'Work']);
        $run($path, ['push']);
        expect($run($path, ['log', 'feature-x', '--not', '--remotes', '--oneline']))->toBe('');
    } finally {
        $this->app->setBasePath($oldBase);
    }
});
