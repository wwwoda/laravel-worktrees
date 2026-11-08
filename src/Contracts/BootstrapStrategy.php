<?php

namespace Woda\Worktrees\Contracts;

use Closure;

/**
 * Encapsulates HOW bootstrap steps run for a worktree.
 *
 * The shape of the steps (composer install → node install → migrate → build)
 * stays the same across strategies; what differs is whether they execute
 * natively on the host or inside docker compose services.
 *
 * Selected by WorktreesServiceProvider from `config('worktrees.bootstrap.strategy')`:
 *   'native' (default) — runs commands directly via `Process::path($worktree)->run(...)`.
 *   'sail'             — `docker compose up -d` first, then runs PHP/composer/artisan
 *                         commands via `docker compose exec laravel.test ...`.
 *                         Vite + node-pm stay on host (faster HMR; sail mode
 *                         assumes Vite is host-native against a Sail-served app).
 */
interface BootstrapStrategy
{
    /**
     * Bring the per-worktree environment up before anything else (e.g. start
     * the docker stack). Native strategy is a no-op.
     *
     * @param  (Closure(string, string): void)|null  $output
     */
    public function bringUp(string $worktreePath, ?Closure $output = null): void;

    /**
     * @param  (Closure(string, string): void)|null  $output
     */
    public function installComposerDependencies(string $worktreePath, ?Closure $output = null): void;

    /**
     * @param  (Closure(string, string): void)|null  $output
     */
    public function installNodeDependencies(string $worktreePath, ?Closure $output = null): void;

    /**
     * @param  (Closure(string, string): void)|null  $output
     */
    public function buildFrontend(string $worktreePath, ?Closure $output = null): void;

    /**
     * @param  (Closure(string, string): void)|null  $output
     */
    public function runMigrations(string $worktreePath, ?Closure $output = null): void;

    /**
     * Tear down per-worktree environment on `worktree:delete`. Native is a no-op;
     * Sail runs `docker compose down -v` so the per-stack DB/redis volumes go.
     *
     * @param  (Closure(string, string): void)|null  $output
     */
    public function tearDown(string $worktreePath, ?Closure $output = null): void;
}
