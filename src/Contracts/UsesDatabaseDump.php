<?php

namespace Woda\Worktrees\Contracts;

use Closure;

/**
 * A strategy whose worktrees can start from a ready-made database dump with
 * test data (see Fast\DatabaseDump).
 */
interface UsesDatabaseDump
{
    public function databaseDumpEnabled(): bool;

    /**
     * Restore the dump of the worktree's key into its empty database.
     * False when the database is not empty or no dump exists for the key.
     *
     * @param  (Closure(string, string): void)|null  $output
     * @param  (Closure(string): void)|null  $onStep
     */
    public function restoreDatabaseDump(string $worktreePath, ?Closure $output = null, ?Closure $onStep = null): bool;

    /**
     * Run the seed command, then save the database as the dump of the key.
     *
     * @param  (Closure(string, string): void)|null  $output
     * @param  (Closure(string): void)|null  $onStep
     */
    public function seedAndSaveDatabaseDump(string $worktreePath, ?Closure $output = null, ?Closure $onStep = null): void;
}
