<?php

namespace Woda\Worktrees\Shared;

use RuntimeException;

/**
 * `php artisan test --parallel --recreate-databases` drops and creates the
 * parallel test databases. A PostgreSQL worktree's role cannot create
 * databases, so it would fail half way with a permission error; stop it at
 * the start with a clear message instead.
 */
final class RecreateDatabasesGuard
{
    public static function check(bool $recreate, string $driver, string $basePath): void
    {
        if (! $recreate || $driver !== 'pgsql' || WorktreeState::load($basePath) === null) {
            return;
        }

        throw new RuntimeException("This worktree's PostgreSQL role cannot create databases; its parallel test databases exist already. Run the tests without --recreate-databases.");
    }
}
