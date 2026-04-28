<?php

namespace Woda\Worktrees;

use Closure;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Woda\Worktrees\Contracts\BootstrapStrategy;

/**
 * Sail / docker-compose bootstrap.
 *
 * - bringUp: `docker compose up -d` from the worktree dir. Subsequent steps
 *   exec into the configured app service (default 'laravel.test').
 * - PHP/composer/artisan: `docker compose exec -T <appService> <cmd>`.
 * - Node + Vite: stay on host. Vite-in-container has 200-1000ms HMR lag on
 *   macOS via VirtioFS; running pnpm/npm against a Sail-served app is the
 *   recommended pattern (Aaron Saray, Patrick Riemer 2025-26).
 * - tearDown: `docker compose down -v` to wipe per-stack volumes.
 *
 * Inherited-env trap: when bootstrap is invoked from a Laravel command, the
 * PHP process has already loaded the *parent* worktree's `.env` via
 * phpdotenv. That includes `COMPOSE_PROJECT_NAME`, `WORKTREE_HOST`,
 * `DB_DATABASE`, etc. When we exec `docker compose ...` as a subprocess,
 * compose reads its env BEFORE its YAML interpolation, so the inherited
 * vars override the new worktree's `.env` — the new stack ends up named
 * after the parent (and shares its volumes). We unset the offenders before
 * every compose invocation; compose then falls back to the new worktree's
 * own `.env` for project name + everything else.
 */
class SailBootstrapStrategy implements BootstrapStrategy
{
    /**
     * Vars that are typically set per-worktree in `.env` and would otherwise
     * leak from the parent process and override the new worktree's values.
     */
    private const INHERITED_VARS_TO_CLEAR = [
        'COMPOSE_PROJECT_NAME',
        'COMPOSE_FILE',
        'COMPOSE_PROFILES',
        'WORKTREE_HOST',
        'APP_NAME',
        'APP_URL',
        'APP_KEY',
        'DB_DATABASE',
        'DB_HOST',
        'DB_PORT',
        'DB_USERNAME',
        'DB_PASSWORD',
        'REDIS_HOST',
        'REDIS_PORT',
        'MAIL_HOST',
        'MAIL_PORT',
    ];

    public function __construct(
        private readonly string $appService,
        private readonly string $nodePackageManager,
        private readonly bool $buildFrontend,
        private readonly int $bringUpTimeout = 600,
    ) {}

    public function bringUp(string $worktreePath, ?Closure $output = null): void
    {
        $result = $this->compose($worktreePath, $this->bringUpTimeout)->run(
            'docker compose up -d',
            $output,
        );

        if (! $result->successful()) {
            throw new RuntimeException("docker compose up -d failed: {$result->errorOutput()}");
        }

        // Give the app service a moment to pass its DB-wait entrypoint before
        // the first `exec` lands.
        $this->waitForService($worktreePath, $output);
    }

    public function installComposerDependencies(string $worktreePath, ?Closure $output = null): void
    {
        $result = $this->compose($worktreePath, 600)->run(
            $this->execCommand('composer install --no-interaction --prefer-dist'),
            $output,
        );

        if (! $result->successful()) {
            throw new RuntimeException("Composer install failed: {$result->errorOutput()}");
        }
    }

    public function installNodeDependencies(string $worktreePath, ?Closure $output = null): void
    {
        // Host-native — Vite + HMR are host concerns.
        $result = Process::path($worktreePath)->timeout(300)->run("{$this->nodePackageManager} install", $output);

        if (! $result->successful()) {
            throw new RuntimeException("Node dependency install failed: {$result->errorOutput()}");
        }
    }

    public function buildFrontend(string $worktreePath, ?Closure $output = null): void
    {
        if (! $this->buildFrontend) {
            return;
        }

        $result = Process::path($worktreePath)->timeout(300)->run("{$this->nodePackageManager} run build", $output);

        if (! $result->successful()) {
            throw new RuntimeException("Frontend build failed: {$result->errorOutput()}");
        }
    }

    public function runMigrations(string $worktreePath, ?Closure $output = null): void
    {
        $result = $this->compose($worktreePath, 300)->run(
            $this->execCommand('php artisan migrate --force'),
            $output,
        );

        if (! $result->successful()) {
            throw new RuntimeException("Migration failed: {$result->errorOutput()}");
        }
    }

    public function tearDown(string $worktreePath, ?Closure $output = null): void
    {
        $this->compose($worktreePath, 120)->run('docker compose down -v', $output);
    }

    /**
     * A PendingProcess scoped to the worktree dir with parent-shell vars
     * that would interfere with compose's project-name / .env resolution
     * cleared. See class docblock.
     */
    private function compose(string $worktreePath, int $timeout): PendingProcess
    {
        $clearedEnv = array_fill_keys(self::INHERITED_VARS_TO_CLEAR, false);

        return Process::path($worktreePath)->timeout($timeout)->env($clearedEnv);
    }

    private function execCommand(string $cmd): string
    {
        return sprintf(
            'docker compose exec -T %s sh -c %s',
            escapeshellarg($this->appService),
            escapeshellarg($cmd),
        );
    }

    /**
     * Poll the app service until its DB-wait entrypoint hands off. Bounded by
     * 60s — if the stack isn't healthy by then, callers will see an exec
     * failure on the next step anyway.
     *
     * @param  (Closure(string, string): void)|null  $output
     */
    private function waitForService(string $worktreePath, ?Closure $output = null): void
    {
        $deadline = time() + 60;

        while (time() < $deadline) {
            $check = $this->compose($worktreePath, 10)->run(
                $this->execCommand('php -r "echo \"ready\";"'),
            );

            if ($check->successful() && trim($check->output()) === 'ready') {
                return;
            }

            usleep(1_000_000);
        }
    }
}
