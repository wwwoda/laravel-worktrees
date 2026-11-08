<?php

namespace Woda\Worktrees\Contracts;

use Closure;

/**
 * Fills a new, empty template database: migrations plus the seed command.
 * It connects as `$user`, which has rights on `$database` only.
 */
interface TemplateRecipe
{
    /**
     * @param  (Closure(string, string): void)|null  $output
     */
    public function build(string $database, string $user, string $password, string $seedCommand, ?Closure $output = null): void;
}
