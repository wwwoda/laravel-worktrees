<?php

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Woda\Worktrees\Contracts\BootstrapStrategy;
use Woda\Worktrees\NativeBootstrapStrategy;
use Woda\Worktrees\SailBootstrapStrategy;
use Woda\Worktrees\WorktreeManager;

/*
 * Findings of the measurements in issue 25: a new worktree leaves no tracked
 * file changed, delete removes the worktree's image and offers a branch that a
 * pull request merged, and the setup waits for healthchecks that still start.
 */
beforeEach(function () {
    $this->base = sys_get_temp_dir().'/worktrees-findings-'.uniqid();
    $this->common = $this->base.'/common.git';
    File::ensureDirectoryExists($this->common);
    $this->envBefore = File::exists(base_path('.env')) ? File::get(base_path('.env')) : null;
    File::put(base_path('.env'), "APP_URL=https://app.test\nCOMPOSE_PROJECT_NAME=app\n");
    // A folder of its own in the application: the test must not touch a real .claude folder.
    $this->config = '.lw-test-claude-'.uniqid();
    File::ensureDirectoryExists(base_path($this->config.'/skills/app'));
    File::put(base_path($this->config.'/skills/app/SKILL.md'), 'main working copy, with a local edit');
    File::put(base_path($this->config.'/settings.local.json'), '{"local": true}');
    config()->set('app.url', 'https://app.test');
    config()->set('worktrees.base_path', $this->base);
    config()->set('worktrees.copy_files', ['.env', './'.$this->config.'/']);
    config()->set('worktrees.database.strategy', 'none');
    config()->set('worktrees.bootstrap.strategy', 'sail');
    config()->set('worktrees.bootstrap.build_frontend', false);
    config()->set('worktrees.env_overrides', ['COMPOSE_PROJECT_NAME' => 'app-{name}']);
    $this->path = $this->base.'/'.basename(base_path()).'-t9';
    $this->listed = true;
    $this->psResults = [];
    $this->lsFiles = null;
    $this->fake = function (array $extra = []) {
        Process::fake($extra + [
            'git show-ref *' => Process::result(exitCode: 1),
            'git worktree add *' => function () {
                File::ensureDirectoryExists($this->path.'/'.$this->config.'/skills/app');
                File::put($this->path.'/'.$this->config.'/skills/app/SKILL.md', 'branch version');

                return Process::result();
            },
            'git worktree list --porcelain' => fn () => Process::result('worktree '.base_path()."\nHEAD 000\nbranch refs/heads/master\n\n"
                .($this->listed && is_dir($this->path) ? "worktree {$this->path}\nHEAD abc\nbranch refs/heads/t9\n\n" : '')),
            'git ls-files -z -- *' => fn () => $this->lsFiles ?? Process::result($this->config."/skills/app/SKILL.md\0"),
            'git rev-parse --path-format=absolute --git-common-dir' => fn () => Process::result($this->common."\n"),
            "docker compose exec -T 'laravel.test' sh -c *echo*" => Process::result('ready'),
            'docker compose ps --format json' => fn () => array_shift($this->psResults) ?? Process::result(),
            '*' => Process::result(),
        ]);
    };
});

afterEach(function () {
    File::deleteDirectory($this->base);
    File::deleteDirectory(base_path($this->config));
    $this->envBefore === null ? File::delete(base_path('.env')) : File::put(base_path('.env'), $this->envBefore);
});

it('copies config folders without overwriting the files git tracks in the worktree', function () {
    ($this->fake)();

    $this->artisan('worktree:create', ['name' => 't9', '--skip-deps' => true, '--skip-db' => true])->assertSuccessful();

    expect(File::get($this->path.'/'.$this->config.'/skills/app/SKILL.md'))->toBe('branch version')
        ->and(File::get($this->path.'/'.$this->config.'/settings.local.json'))->toBe('{"local": true}');
});

it('replaces no existing file when git cannot list the tracked files', function () {
    $this->lsFiles = Process::result(errorOutput: 'fatal', exitCode: 128);
    ($this->fake)();

    $this->artisan('worktree:create', ['name' => 't9', '--skip-deps' => true, '--skip-db' => true])->assertSuccessful();

    expect(File::get($this->path.'/'.$this->config.'/skills/app/SKILL.md'))->toBe('branch version')
        ->and(File::get($this->path.'/'.$this->config.'/settings.local.json'))->toBe('{"local": true}');
});

it('lists the files the package writes in the repository\'s info/exclude, once', function () {
    ($this->fake)();

    $this->artisan('worktree:create', ['name' => 't9', '--skip-deps' => true, '--skip-db' => true])->assertSuccessful();
    $this->artisan('worktree:bootstrap', ['name' => 't9', '--resume' => true, '--skip-deps' => true, '--skip-db' => true])->assertSuccessful();

    $exclude = File::get($this->common.'/info/exclude');
    expect(substr_count($exclude, '/storage/worktree-bootstrap.json'))->toBe(1)
        ->and($exclude)->toContain('/storage/worktree-bootstrap.lock');
});

it('waits while a healthcheck still starts, and while compose cannot tell', function () {
    $this->psResults = [
        Process::result(errorOutput: 'daemon busy', exitCode: 1),
        Process::result(json_encode(['Service' => 'mariadb', 'Health' => 'starting'])."\n".json_encode(['Service' => 'redis', 'Health' => 'healthy'])),
        Process::result(json_encode(['Service' => 'mariadb', 'Health' => 'healthy'])),
    ];
    ($this->fake)();
    File::ensureDirectoryExists($this->path);

    (new SailBootstrapStrategy('laravel.test', 'pnpm', false))->bringUp($this->path);
    Process::assertRanTimes('docker compose ps --format json', 3);
});

it('removes the images the worktree built for itself on delete, and no shared image', function () {
    $config = fn (string $project, array $services) => Process::result(json_encode(['name' => $project, 'services' => $services]));
    ($this->fake)(['docker compose config --format json' => fn (PendingProcess $p) => $p->path === $this->path
        ? $config('app-t9', [
            'laravel.test' => ['build' => ['context' => '.'], 'image' => 'app-t9/app:latest'],
            'worker' => ['build' => ['context' => '.']],
            'shared' => ['build' => ['context' => '.'], 'image' => 'app-shared:abc'],
            'mariadb' => ['image' => 'mariadb:10.11'],
        ])
        : $config('app', ['laravel.test' => ['build' => ['context' => '.'], 'image' => 'app/app:latest']])]);
    File::ensureDirectoryExists($this->path.'/storage');

    $this->artisan('worktree:delete', ['name' => 't9', '--force' => true])
        ->expectsOutputToContain('Removed the image app-t9/app:latest.')
        ->assertSuccessful();

    Process::assertRan("docker image rm 'app-t9/app:latest'");
    Process::assertRan("docker image rm 'app-t9-worker'");
    Process::assertNotRan("docker image rm 'app-shared:abc'");
    Process::assertNotRan("docker image rm 'mariadb:10.11'");
    Process::assertNotRan("docker image rm 'app/app:latest'");
});

it('offers a branch that a pull request squashed, and only with the same head commit', function (string $prHead, bool $deleted) {
    ($this->fake)([
        "git show-ref --verify --quiet 'refs/heads/t9'" => Process::result(),
        'git merge-base --is-ancestor *' => Process::result(exitCode: 1),
        "git rev-parse 'refs/heads/t9'" => Process::result("abc123\n"),
        "gh pr list --head 't9' --base 'master' *" => Process::result(json_encode([['headRefOid' => $prHead]])),
    ]);
    File::ensureDirectoryExists($this->path.'/storage');
    app()->singleton(BootstrapStrategy::class, fn () => new NativeBootstrapStrategy('pnpm'));
    app()->forgetInstance(WorktreeManager::class);

    $this->artisan('worktree:delete', ['name' => 't9', '--force' => true, '--delete-branch' => true])->assertSuccessful();

    $deleted ? Process::assertRan("git branch -D 't9'") : Process::assertNotRan("git branch -D 't9'");
})->with([
    'same head' => ['abc123', true],
    'commits after the merge' => ['def456', false],
]);
