<?php

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Woda\Worktrees\SailBootstrapStrategy;
use Woda\Worktrees\WorktreeManager;

beforeEach(function () {
    $this->base = sys_get_temp_dir().'/worktrees-guard-'.uniqid();
    File::ensureDirectoryExists($this->base);
    config()->set('worktrees.base_path', $this->base);
});

afterEach(function () {
    File::deleteDirectory($this->base);
});

test('interactive issue with several linked branches stops before it creates anything', function () {
    Process::fake([
        'gh issue list *' => Process::result('[{"number":7,"title":"Add auth"}]'),
        'gh issue develop --list 7' => Process::result("7-add-auth\thttps://example.invalid/a\n7-add-auth-2\thttps://example.invalid/b\n"),
        '*' => Process::result(),
    ]);

    $this->artisan('worktree:create')
        ->expectsChoice('What should this worktree be based on?', 'issue', [
            'issue' => 'GitHub issue',
            'pr' => 'Pull request',
            'branch' => 'Existing branch',
            'fresh' => 'Fresh (new branch)',
        ])
        ->expectsSearch('Search for an issue', 7, '', [7 => '#7 Add auth'])
        ->expectsOutputToContain('multiple linked branches')
        ->assertFailed();

    Process::assertNotRan(fn (PendingProcess $process) => str_contains((string) $process->command, 'worktree add'));
});

test('teardown of an unregistered directory runs no command in it', function () {
    $path = $this->base.'/'.basename(base_path()).'-orphan';
    File::ensureDirectoryExists($path);
    Process::fake([
        'git worktree list --porcelain' => Process::result(),
        '*' => Process::result(),
    ]);

    expect(fn () => app(WorktreeManager::class)->tearDown('orphan'))
        ->toThrow(RuntimeException::class, 'not a registered worktree');

    Process::assertNotRan(fn (PendingProcess $process) => $process->path === $path);
});

test('Sail build contexts and bind-mount files under a missing vendor are lent from the running checkout while compose starts', function () {
    $worktree = $this->base.'/wt';
    $source = $this->base.'/main';
    File::ensureDirectoryExists($worktree);
    File::ensureDirectoryExists($source.'/vendor/laravel/sail/runtimes/8.4');
    File::put($source.'/vendor/laravel/sail/runtimes/8.4/Dockerfile', 'FROM php');
    File::ensureDirectoryExists($source.'/vendor/laravel/sail/database/mysql');
    File::put($source.'/vendor/laravel/sail/database/mysql/create-testing-database.sh', '#!/bin/sh');
    $config = json_encode(['services' => [
        'laravel.test' => ['build' => ['context' => $worktree.'/vendor/laravel/sail/runtimes/8.4']],
        'mysql' => ['image' => 'mysql:8.4', 'volumes' => [
            ['type' => 'bind', 'source' => $worktree.'/vendor/laravel/sail/database/mysql/create-testing-database.sh', 'target' => '/docker-entrypoint-initdb.d/10-create-testing-database.sh'],
            ['type' => 'volume', 'source' => 'sail-mysql', 'target' => '/var/lib/mysql'],
        ]],
    ]]);
    $seenAtUp = null;
    Process::fake([
        'docker compose config --format json' => Process::result($config),
        'docker compose up -d' => function () use ($worktree, &$seenAtUp) {
            $seenAtUp = is_file($worktree.'/vendor/laravel/sail/runtimes/8.4/Dockerfile')
                && is_file($worktree.'/vendor/laravel/sail/database/mysql/create-testing-database.sh');

            return Process::result();
        },
        "docker compose exec -T 'laravel.test' sh -c *echo*" => Process::result('ready'),
        '*' => Process::result(),
    ]);
    $strategy = new class('laravel.test', 'pnpm', false, $source) extends SailBootstrapStrategy
    {
        public function __construct(string $appService, string $pm, bool $build, private string $source)
        {
            parent::__construct($appService, $pm, $build);
        }

        protected function buildContextSource(): string
        {
            return $this->source;
        }
    };

    $strategy->bringUp($worktree);

    expect($seenAtUp)->toBeTrue()
        ->and(file_exists($worktree.'/vendor'))->toBeFalse();
});

test('a failed fetch or a branch that is nowhere stops create before it makes a branch', function (array $options, Closure $fakes) {
    Process::fake([...$fakes(), '*' => Process::result()]);

    $this->artisan('worktree:create', $options)->assertFailed();

    Process::assertNotRan(fn (PendingProcess $process) => str_contains((string) $process->command, 'worktree add'));
})->with([
    'issue, fetch fails' => [['--issue' => '7'], fn () => [
        'gh issue develop --list 7' => Process::result("7-add-auth\thttps://example.invalid/a\n"),
        'git fetch origin' => Process::result(errorOutput: 'network down', exitCode: 128),
    ]],
    'issue, branch not on origin' => [['--issue' => '7'], fn () => [
        'gh issue develop --list 7' => Process::result("7-add-auth\thttps://example.invalid/a\n"),
        'git show-ref *' => Process::result(exitCode: 1),
    ]],
    'pr, fetch fails' => [['--pr' => '5'], fn () => [
        'gh pr view 5 *' => Process::result('{"headRefName":"feature","isCrossRepository":false}'),
        'git fetch origin' => Process::result(errorOutput: 'network down', exitCode: 128),
    ]],
]);

test('a pull request from a fork is checked out from its pull ref', function () {
    $path = $this->base.'/'.basename(base_path()).'-pr-5';
    Process::fake([
        'gh pr view 5 *' => Process::result('{"headRefName":"master","isCrossRepository":true}'),
        'git show-ref *' => Process::result(),
        'git worktree add *' => function () use ($path) {
            File::ensureDirectoryExists($path);

            return Process::result();
        },
        '*' => Process::result(),
    ]);

    $this->artisan('worktree:create', ['--pr' => '5', '--skip-deps' => true, '--skip-build' => true, '--skip-db' => true]);

    Process::assertRan(fn (PendingProcess $process) => $process->command === "git fetch origin 'pull/5/head:refs/heads/pr-5'");
    Process::assertRan(fn (PendingProcess $process) => str_contains((string) $process->command, "worktree add '{$path}' 'pr-5'"));
});

test('compose runs without any inherited COMPOSE_ variable', function () {
    $worktree = $this->base.'/wt';
    File::ensureDirectoryExists($worktree);
    putenv('COMPOSE_DISABLE_ENV_FILE=1');
    $_ENV['COMPOSE_ENV_FILES'] = '/parent/.env';
    Process::fake(['*' => Process::result()]);

    try {
        (new SailBootstrapStrategy('laravel.test', 'pnpm', false))->tearDown($worktree);
    } finally {
        putenv('COMPOSE_DISABLE_ENV_FILE');
        unset($_ENV['COMPOSE_ENV_FILES']);
    }

    Process::assertRan(fn (PendingProcess $process) => str_contains((string) $process->command, 'down')
        && $process->environment['COMPOSE_DISABLE_ENV_FILE'] === false
        && $process->environment['COMPOSE_ENV_FILES'] === false);
});

test('a worktree with the compose project of the checkout it came from is neither started nor torn down', function () {
    $worktree = $this->base.'/wt';
    File::ensureDirectoryExists($worktree);
    Process::fake([
        'docker compose config --format json' => Process::result(json_encode(['name' => 'app', 'services' => []])),
        '*' => Process::result(),
    ]);
    $strategy = new SailBootstrapStrategy('laravel.test', 'pnpm', false);

    expect(fn () => $strategy->bringUp($worktree))->toThrow(RuntimeException::class, 'same compose project')
        ->and(fn () => $strategy->tearDown($worktree))->toThrow(RuntimeException::class, 'same compose project')
        ->and(fn () => $strategy->tearDownKeepingVolumes($worktree))->toThrow(RuntimeException::class, 'same compose project');
    Process::assertNotRan(fn (PendingProcess $process) => str_contains((string) $process->command, ' up ')
        || str_contains((string) $process->command, ' down'));
});

test('a worktree with its own compose project is started', function () {
    $worktree = $this->base.'/wt';
    File::ensureDirectoryExists($worktree);
    Process::fake([
        'docker compose config --format json' => fn (PendingProcess $process) => Process::result(json_encode(['name' => $process->path === $worktree ? 'app-wt' : 'app', 'services' => []])),
        "docker compose exec -T 'laravel.test' sh -c *echo*" => Process::result('ready'),
        '*' => Process::result(),
    ]);

    (new SailBootstrapStrategy('laravel.test', 'pnpm', false))->bringUp($worktree);

    Process::assertRan('docker compose up -d');
});
