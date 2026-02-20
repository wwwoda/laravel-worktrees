<?php

namespace Woda\Worktrees;

use Woda\Worktrees\Contracts\ProcessManager;

class NullProcessManager implements ProcessManager
{
    public function isRunning(string $worktreeName): bool
    {
        return false;
    }

    public function terminate(string $worktreeName): void
    {
        // No-op
    }

    public function runningLabel(string $worktreeName): ?string
    {
        return null;
    }
}
