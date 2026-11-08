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
}
