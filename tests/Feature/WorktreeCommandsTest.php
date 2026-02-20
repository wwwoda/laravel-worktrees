<?php

test('worktree:list shows empty when no worktrees', function () {
    $this->artisan('worktree:list')
        ->expectsOutputToContain('No agent worktrees found')
        ->assertExitCode(0);
});

test('worktree:list with --json outputs empty array when no worktrees', function () {
    $this->artisan('worktree:list --json')
        ->expectsOutput('[]')
        ->assertExitCode(0);
});

test('worktree:cleanup shows empty when no worktrees', function () {
    $this->artisan('worktree:cleanup --force')
        ->expectsOutputToContain('No agent worktrees found')
        ->assertExitCode(0);
});

test('worktree:cleanup --dry-run shows empty when no worktrees', function () {
    $this->artisan('worktree:cleanup --dry-run')
        ->expectsOutputToContain('No agent worktrees found')
        ->assertExitCode(0);
});
