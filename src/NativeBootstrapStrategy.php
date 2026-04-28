<?php

namespace Woda\Worktrees;

use Closure;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Woda\Worktrees\Contracts\BootstrapStrategy;

class NativeBootstrapStrategy implements BootstrapStrategy
{
    public function __construct(
        private readonly string $nodePackageManager,
    ) {}

    public function bringUp(string $worktreePath, ?Closure $output = null): void
    {
        // No-op for native. PHP-FPM/nginx/MariaDB live outside the worktree's lifecycle.
    }

    public function installComposerDependencies(string $worktreePath, ?Closure $output = null): void
    {
        $result = Process::path($worktreePath)->timeout(300)->run('composer install --no-interaction', $output);

        if (! $result->successful()) {
            throw new RuntimeException("Composer install failed: {$result->errorOutput()}");
        }
    }

    public function installNodeDependencies(string $worktreePath, ?Closure $output = null): void
    {
        $result = Process::path($worktreePath)->timeout(300)->run("{$this->nodePackageManager} install", $output);

        if (! $result->successful()) {
            throw new RuntimeException("Node dependency install failed: {$result->errorOutput()}");
        }
    }

    public function buildFrontend(string $worktreePath, ?Closure $output = null): void
    {
        $result = Process::path($worktreePath)->timeout(300)->run("{$this->nodePackageManager} run build", $output);

        if (! $result->successful()) {
            throw new RuntimeException("Frontend build failed: {$result->errorOutput()}");
        }
    }

    public function runMigrations(string $worktreePath, ?Closure $output = null): void
    {
        $result = Process::path($worktreePath)->timeout(120)->run('php artisan migrate --force', $output);

        if (! $result->successful()) {
            throw new RuntimeException("Migration failed: {$result->errorOutput()}");
        }
    }

    public function tearDown(string $worktreePath, ?Closure $output = null): void
    {
        // No-op for native.
    }
}
