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
