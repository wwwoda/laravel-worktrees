<?php

namespace Woda\Worktrees\Contracts;

use Closure;

/**
 * A strategy whose worktrees can start from a ready-made database dump with
 * test data (see Fast\DatabaseDump).
 */
interface UsesDatabaseDump
{
    public const DUMP_RESTORED = 'restored';

    // No dump for the key: the setup seeds and saves one.
    public const DUMP_MISSING = 'missing';

    // The database has tables (a setup that ran before): kept as it is.
    public const DUMP_EXISTING = 'existing';

    public function databaseDumpEnabled(): bool;

    /**
     * Restore the dump of the worktree's key into its empty database.
     *
     * @param  (Closure(string, string): void)|null  $output
     * @param  (Closure(string): void)|null  $onStep
     * @return self::DUMP_* what happened
     */
    public function restoreDatabaseDump(string $worktreePath, ?Closure $output = null, ?Closure $onStep = null): string;

    /**
     * Run the seed command, then save the database as the dump of the key.
     *
     * @param  (Closure(string, string): void)|null  $output
     * @param  (Closure(string): void)|null  $onStep
     */
    public function seedAndSaveDatabaseDump(string $worktreePath, ?Closure $output = null, ?Closure $onStep = null): void;
}
