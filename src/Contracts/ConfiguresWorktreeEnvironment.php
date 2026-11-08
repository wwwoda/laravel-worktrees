<?php

namespace Woda\Worktrees\Contracts;

/**
 * A bootstrap strategy that writes its own values into a new worktree's
 * `.env` after the package's standard rewrites (config stage).
 */
interface ConfiguresWorktreeEnvironment
{
    /**
     * @param  array{horizon?: bool}  $options
     */
    public function configureEnvironment(string $worktreePath, array $options): void;
}
