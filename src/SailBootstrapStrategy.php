<?php

namespace Woda\Worktrees;

use Closure;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
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
        $config = $this->composeConfig($worktreePath);
        $this->assertOwnComposeProject($worktreePath, $config);
        $lent = $this->lendVendorPaths($worktreePath, $config);
        try {
            $result = $this->compose($worktreePath, $this->bringUpTimeout)->run(
                'docker compose up -d',
                $output,
            );
        } finally {
            foreach ($lent as $created) {
                is_dir($created) ? File::deleteDirectory($created) : File::delete($created);
            }
        }

        if (! $result->successful()) {
            throw new RuntimeException("docker compose up -d failed: {$result->errorOutput()}");
        }

        // Give the app service a moment to pass its DB-wait entrypoint before
        // the first `exec` lands.
        $this->waitForService($worktreePath, $output);
    }

    /**
     * The worktree's compose configuration as `docker compose config` resolves it; null when compose cannot read it.
     *
     * @return array<mixed>|null
     */
    private function composeConfig(string $path): ?array
    {
        $result = $this->compose($path, 60)->run('docker compose config --format json');
        $config = $result->successful() ? json_decode($result->output(), true) : null;

        return is_array($config) ? $config : null;
    }

    /**
     * `up` and `down -v` act on the worktree's compose project. A `.env` copied with
     * an unchanged COMPOSE_PROJECT_NAME gives it the project of the checkout it came
     * from; then they would act on that checkout's containers and volumes.
     *
     * @param  array<mixed>|null  $config
     */
    private function assertOwnComposeProject(string $worktreePath, ?array $config): void
    {
        $ours = is_string($config['name'] ?? null) && $config['name'] !== '' ? $config['name'] : null;
        if ($ours === null) {
            return;
        }
        $self = realpath($worktreePath) ?: $worktreePath;
        foreach (array_unique($this->protectedCheckouts()) as $checkout) {
            if ((realpath($checkout) ?: $checkout) === $self) {
                continue;
            }
            $theirs = $this->composeConfig($checkout)['name'] ?? null;
            if ($theirs === $ours) {
                throw new RuntimeException("The worktree has the same compose project as {$checkout} ('{$ours}'): starting or stopping it would act on that checkout's containers and volumes. Give worktrees their own COMPOSE_PROJECT_NAME (worktrees.env_overrides) and run again.");
            }
        }
    }

    /**
     * Checkouts whose compose project a worktree must not share.
     *
     * @return list<string>
     */
    protected function protectedCheckouts(): array
    {
        return [base_path()];
    }

    /**
     * Sail's compose file builds the app image from `vendor/laravel/sail/runtimes/...`
     * and bind-mounts files from `vendor` (the MySQL testing-database script), but a
     * fresh worktree has no `vendor` before Composer runs in that container. Copy
     * each missing build context and bind source under `vendor` from the checkout
     * that runs artisan (it has `vendor`); the caller removes the copies after
     * `compose up` (a bind mount keeps the copied file), so the Composer stage still
     * sees the worktree as it was.
     *
     * @param  array<mixed>|null  $config
     * @return list<string> the files and directories created here
     */
    private function lendVendorPaths(string $worktreePath, ?array $config): array
    {
        $services = is_array($config['services'] ?? null) ? $config['services'] : [];
        $paths = [];
        foreach ($services as $service) {
            if (! is_array($service)) {
                continue;
            }
            if (is_array($service['build'] ?? null) && is_string($service['build']['context'] ?? null)) {
                $paths[] = $service['build']['context'];
            }
            foreach (is_array($service['volumes'] ?? null) ? $service['volumes'] : [] as $volume) {
                if (is_array($volume) && ($volume['type'] ?? null) === 'bind' && is_string($volume['source'] ?? null)) {
                    $paths[] = $volume['source'];
                }
            }
        }
        $lent = [];
        foreach ($paths as $path) {
            // Compose prints the path as given or resolved; accept both spellings of the worktree.
            $root = null;
            foreach (array_unique([rtrim($worktreePath, '/'), realpath($worktreePath) ?: $worktreePath]) as $candidate) {
                if (str_starts_with($path, $candidate.'/vendor/')) {
                    $root = $candidate;
                }
            }
            if ($root === null || file_exists($path)) {
                continue;
            }
            $relative = substr($path, strlen($root) + 1);
            $source = $this->buildContextSource().'/'.$relative;
            if (! file_exists($source)) {
                continue;
            }
            // Remember the top-most directory created here; that is what goes again.
            $created = $root;
            foreach (explode('/', $relative) as $part) {
                $created .= '/'.$part;
                if (! file_exists($created)) {
                    break;
                }
            }
            if (is_dir($source)) {
                File::copyDirectory($source, $path);
            } else {
                File::ensureDirectoryExists(dirname($path));
                File::copy($source, $path);
            }
            $lent[] = $created;
        }

        return array_values(array_unique($lent));
    }

    /**
     * Where missing build contexts and bind sources are copied from: the checkout that runs artisan.
     */
    protected function buildContextSource(): string
    {
        return base_path();
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
        $this->down($worktreePath, false, $output);
    }

    public function tearDownKeepingVolumes(string $worktreePath, ?Closure $output = null): void
    {
        $this->down($worktreePath, true, $output);
    }

    private function down(string $worktreePath, bool $keepVolumes, ?Closure $output): void
    {
        $this->assertOwnComposeProject($worktreePath, $this->composeConfig($worktreePath));
        $result = $this->compose($worktreePath, 120)->run(
            'docker compose --profile "*" down'.($keepVolumes ? '' : ' -v'), $output,
        );
        if (! $result->successful()) {
            throw new RuntimeException('Worktree environment teardown failed; checkout retained.');
        }
    }

    /**
     * A PendingProcess scoped to the worktree dir with parent-shell vars
     * that would interfere with compose's project-name / .env resolution
     * cleared. See class docblock.
     */
    private function compose(string $worktreePath, int $timeout): PendingProcess
    {
        $keys = self::INHERITED_VARS_TO_CLEAR;
        if (File::exists($worktreePath.'/.env')) {
            preg_match_all('/^(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=/m', File::get($worktreePath.'/.env'), $matches);
            $keys = [...$keys, ...$matches[1]];
        }
        // Every inherited Compose control variable (COMPOSE_ENV_FILES, COMPOSE_DISABLE_ENV_FILE, ...)
        // could point compose at another configuration.
        foreach ([...array_keys(getenv()), ...array_keys($_ENV), ...array_keys($_SERVER)] as $key) {
            if (is_string($key) && str_starts_with($key, 'COMPOSE_')) {
                $keys[] = $key;
            }
        }
        $clearedEnv = array_fill_keys($keys, false);

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

        throw new RuntimeException('Worktree app service did not become ready.');
    }
}
