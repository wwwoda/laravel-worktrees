<?php

namespace Woda\Worktrees\Shared;

use Closure;

/**
 * Admin access to the shared database server.
 *
 * Callers pass only names they checked; implementations still accept
 * identifiers of `[A-Za-z0-9_]` only, so no name can carry SQL.
 */
interface DatabaseServer
{
    public function driver(): string;

    public function ping(): bool;

    public function databaseExists(string $database): bool;

    /**
     * @return list<string>
     */
    public function databasesStartingWith(string $prefix): array;

    /**
     * Create the database when it does not exist. `$owner` is used by
     * servers that give databases an owner (PostgreSQL).
     */
    public function createDatabase(string $database, ?string $owner = null): void;

    /**
     * Copy `$source` into `$target`. `$target` must not exist yet.
     *
     * @param  (Closure(string, string): void)|null  $output
     */
    public function copyDatabase(string $source, string $target, ?string $owner = null, ?Closure $output = null): void;

    public function dropDatabase(string $database): void;

    /**
     * Create or update a user with rights on exactly these databases.
     *
     * @param  list<string>  $databases
     */
    public function grantUser(string $user, string $password, array $databases): void;

    public function userExists(string $user): bool;

    public function dropUser(string $user): void;

    /**
     * Run SQL as the given user (not as admin). For checks and tests.
     */
    public function runAs(string $user, string $password, string $sql, ?string $database = null): ServerResult;
}
