<?php

namespace Woda\Worktrees\Contracts;

use Closure;

/**
 * A bootstrap strategy that gives each worktree its own databases on shared
 * services, in place of the DatabaseCloner.
 */
interface ProvisionsDatabases
{
    /**
     * @param  array{from_main?: bool, branch?: string|null, base?: string|null, fill_app?: (Closure(string): void)|null}  $options
     * @param  (Closure(string, string): void)|null  $output
     * @param  (Closure(string): void)|null  $onStep
     */
    public function provisionDatabases(string $name, string $worktreePath, array $options, ?Closure $output = null, ?Closure $onStep = null): void;

    /**
     * The checks of removeDatabases() without any change: throws when its
     * state file does not allow the removal.
     */
    public function checkRemoveDatabases(string $name, string $worktreePath): void;

    /**
     * Drop the worktree's own databases and user and empty its Redis numbers.
     *
     * @param  (Closure(string): void)|null  $onStep
     * @return list<string> what was removed
     */
    public function removeDatabases(string $name, string $worktreePath, ?Closure $onStep = null): array;
}
