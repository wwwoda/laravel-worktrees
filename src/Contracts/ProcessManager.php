<?php

namespace Woda\Worktrees\Contracts;

interface ProcessManager
{
    public function isRunning(string $worktreeName): bool;

    public function terminate(string $worktreeName): void;

    public function runningLabel(string $worktreeName): ?string;
}
