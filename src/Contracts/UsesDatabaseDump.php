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
     * Run the seed command on the database that had no dump.
     *
     * @param  (Closure(string, string): void)|null  $output
     */
    public function seedDatabase(string $worktreePath, ?Closure $output = null): void;

    /**
     * Save the seeded database as the dump of the key. A separate stage, so a
     * resume after a failed save does not seed again.
     *
     * @param  (Closure(string): void)|null  $onStep
     */
    public function saveDatabaseDump(string $worktreePath, ?Closure $onStep = null): void;
}
