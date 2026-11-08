<?php

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
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
