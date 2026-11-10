<?php

namespace Woda\Worktrees;

use Closure;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Woda\Worktrees\Contracts\BootstrapStrategy;
use Woda\Worktrees\Contracts\InstallsNodeBeforeBringUp;
use Woda\Worktrees\Contracts\UsesDatabaseDump;
use Woda\Worktrees\Fast\FastSetup;
use Woda\Worktrees\Shared\EnvFile;
use Woda\Worktrees\Shared\MainCheckout;

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
class SailBootstrapStrategy implements BootstrapStrategy, InstallsNodeBeforeBringUp, UsesDatabaseDump
{
    public function __construct(
        private readonly string $appService,
        private readonly string $nodePackageManager,
        private readonly bool $buildFrontend,
        private readonly int $bringUpTimeout = 600,
        protected readonly FastSetup $fast = new FastSetup,
        private readonly ?Closure $onStep = null,
    ) {}

    public function bringUp(string $worktreePath, ?Closure $output = null): void
    {
        $this->useSharedImage($worktreePath, $output);
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
        $this->ensureVolumesMounted($worktreePath, $config, $output);
    }

    /**
     * `up -d` keeps a running app container as it is. When a host install replaced
     * a folder on which that container mounts a named volume (`node_modules`), the
     * mount is gone (Linux), and the container works on the host's files. A restart
     * mounts the volume again.
     *
     * @param  array<mixed>|null  $config
     * @param  (Closure(string, string): void)|null  $output
     */
    private function ensureVolumesMounted(string $worktreePath, ?array $config, ?Closure $output): void
    {
        $targets = $this->volumeTargets($config);
        if ($targets === []) {
            return;
        }
        $missing = $this->unmountedTargets($worktreePath, $targets);
        if ($missing === []) {
            return;
        }
        $output && $output('out', 'The app container lost its volume at '.implode(', ', $missing).": restarting it.\n");
        $result = $this->compose($worktreePath, $this->bringUpTimeout)->run(
            'docker compose restart '.escapeshellarg($this->appService),
            $output,
        );
        if (! $result->successful()) {
            throw new RuntimeException("Restarting the app container failed: {$result->errorOutput()}");
        }
        $this->waitForService($worktreePath, $output);
        $missing = $this->unmountedTargets($worktreePath, $targets);
        if ($missing !== []) {
            throw new RuntimeException('The app container has no volume at '.implode(', ', $missing).' after a restart. Run docker compose up -d --force-recreate in the worktree, then resume.');
        }
    }

    /**
     * The container paths where the app service mounts a named volume.
     *
     * @param  array<mixed>|null  $config
     * @return list<string>
     */
    private function volumeTargets(?array $config): array
    {
        $services = is_array($config['services'] ?? null) ? $config['services'] : [];
        $service = $services[$this->appService] ?? null;
        $volumes = is_array($service) && is_array($service['volumes'] ?? null) ? $service['volumes'] : [];
        $targets = [];
        foreach ($volumes as $volume) {
            if (is_array($volume) && ($volume['type'] ?? null) === 'volume' && is_string($volume['target'] ?? null) && $volume['target'] !== '') {
                $targets[] = rtrim($volume['target'], '/') ?: '/';
            }
        }

        return array_values(array_unique($targets));
    }

    /**
     * The targets that are no mount point in the app container. Empty when its
     * mount table cannot be read: the next step reports a container that does not run.
     *
     * @param  list<string>  $targets
     * @return list<string>
     */
    private function unmountedTargets(string $worktreePath, array $targets): array
    {
        $result = $this->compose($worktreePath, 30)->run($this->execCommand('cat /proc/self/mountinfo'));
        if (! $result->successful() || trim($result->output()) === '') {
            return [];
        }
        $mounted = [];
        foreach (preg_split('/\r?\n/', trim($result->output())) ?: [] as $line) {
            // Field 5 is the mount point, with space, tab, newline and backslash as octal escapes.
            $fields = explode(' ', $line);
            if (isset($fields[4])) {
                $mounted[] = (string) preg_replace_callback('/\\\\([0-7]{3})/', static fn (array $m): string => chr((int) octdec($m[1])), $fields[4]);
            }
        }

        return array_values(array_diff($targets, $mounted));
    }

    /**
     * The worktree's compose configuration as `docker compose config` resolves it; null when compose cannot read it.
     *
     * @return array<mixed>|null
     */
    protected function composeConfig(string $path): ?array
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
    protected function assertOwnComposeProject(string $worktreePath, ?array $config): void
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
        // With an equal composer.lock, a copy-on-write copy of the main checkout's
        // vendor makes the install below a check. The install still runs: the main
        // checkout may have been installed with --no-dev, and the autoloader must
        // describe this branch's classes.
        $this->copyFromMain($worktreePath, 'vendor', ['composer.lock'], $output, $this->fast->copyVendor);
        $host = $this->fast->composerRunner === 'host';
        $run = function (bool $offline) use ($worktreePath, $output, $host) {
            $env = $offline ? ['COMPOSER_DISABLE_NETWORK' => '1'] : [];
            if ($host) {
                return $this->compose($worktreePath, 600)->run(($offline ? 'COMPOSER_DISABLE_NETWORK=1 ' : '').$this->fast->composerHostCommand, $output);
            }
            if ($this->fast->composerCacheDir !== null && $this->fast->composerCacheDir !== '') {
                $env['COMPOSER_CACHE_DIR'] = $this->fast->composerCacheDir;
            }

            return $this->compose($worktreePath, 600)->run($this->execCommand('composer install --no-interaction --prefer-dist', $env), $output);
        };
        $result = $this->fast->composerOffline ? $run(true) : null;
        if ($result === null || ! $result->successful()) {
            // A cache miss: install with the network.
            $result = $run(false);
        }

        if (! $result->successful()) {
            throw new RuntimeException("Composer install failed: {$result->errorOutput()}");
        }
    }

    public function installNodeDependencies(string $worktreePath, ?Closure $output = null): void
    {
        // Host-native — Vite + HMR are host concerns.
        $result = Process::path($worktreePath)->timeout(300)->run($this->nodeInstallCommand(), $output);

        if (! $result->successful()) {
            throw new RuntimeException("Node dependency install failed: {$result->errorOutput()}");
        }
    }

    /**
     * pnpm and npm install from their store or cache without asking the
     * registry for every package; a missing package still comes from the network.
     */
    protected function nodeInstallCommand(): string
    {
        return in_array($this->nodePackageManager, ['pnpm', 'npm'], true)
            ? "{$this->nodePackageManager} install --prefer-offline"
            : "{$this->nodePackageManager} install";
    }

    /**
     * The node install can run while the containers start when no service
     * mounts a named volume at `node_modules`: a host install replaces that
     * folder, which would detach such a volume (see InstallsNodeBeforeBringUp).
     */
    public function canInstallNodeInParallel(string $worktreePath): bool
    {
        if (! $this->fast->parallelNode) {
            return false;
        }
        $config = $this->composeConfig($worktreePath);
        if ($config === null) {
            return false;
        }
        $services = is_array($config['services'] ?? null) ? $config['services'] : [];
        foreach ($services as $service) {
            $volumes = is_array($service) && is_array($service['volumes'] ?? null) ? $service['volumes'] : [];
            foreach ($volumes as $volume) {
                if (is_array($volume) && ($volume['type'] ?? null) === 'volume' && is_string($volume['target'] ?? null) && str_ends_with(rtrim($volume['target'], '/'), '/node_modules')) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @param  (Closure(string, string): void)|null  $output
     */
    public function startNodeInstall(string $worktreePath, ?Closure $output = null): InvokedProcess
    {
        return Process::path($worktreePath)->timeout(300)->start($this->nodeInstallCommand(), $output);
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

    /**
     * The shared app image: write its tag into `.env` and build it once when it is missing.
     *
     * @param  (Closure(string, string): void)|null  $output
     */
    private function useSharedImage(string $worktreePath, ?Closure $output): void
    {
        $image = $this->fast->image;
        if (! $image->enabled()) {
            return;
        }
        $tag = $image->tag($worktreePath);
        if (EnvFile::get($worktreePath.'/.env', $image->envKey()) !== $tag) {
            EnvFile::upsert($worktreePath.'/.env', [$image->envKey() => $tag]);
        }
        $built = $image->ensure($tag, $this->appService, fn (int $timeout) => $this->compose($worktreePath, $timeout), $output, $this->onStep);
        if (! $built && $output) {
            $output('out', "Using the app image {$tag}.\n");
        }
    }

    public function databaseDumpEnabled(): bool
    {
        return $this->fast->dump->enabled();
    }

    public function restoreDatabaseDump(string $worktreePath, ?Closure $output = null, ?Closure $onStep = null): bool
    {
        $dump = $this->fast->dump;
        // Only into an empty database: a restore over existing tables would replace them.
        $status = $this->compose($worktreePath, 120)->run($this->execCommand('php artisan migrate:status --no-ansi --no-interaction'));
        if ($status->successful()) {
            $onStep && $onStep('The database has tables; the dump is not restored.');

            return false;
        }
        if (stripos($status->output().$status->errorOutput(), 'Migration table not found') === false) {
            throw new RuntimeException('Cannot tell whether the database is empty: '.trim($status->errorOutput() ?: $status->output()));
        }
        $file = $dump->find($worktreePath, $onStep);
        if ($file === null) {
            $onStep && $onStep('No database dump for key '.$dump->key($worktreePath).' yet: this setup seeds and saves one.');

            return false;
        }
        $onStep && $onStep('Restoring the database dump '.basename($file).'...');
        $dump->restore($file, $worktreePath, fn (int $timeout) => $this->compose($worktreePath, $timeout), $output);

        return true;
    }

    public function seedAndSaveDatabaseDump(string $worktreePath, ?Closure $output = null, ?Closure $onStep = null): void
    {
        $dump = $this->fast->dump;
        $result = $this->compose($worktreePath, 1800)->run($this->execCommand($dump->seedCommand()), $output);
        if (! $result->successful()) {
            throw new RuntimeException("The seed command failed: {$result->errorOutput()}");
        }
        $saved = $dump->save($worktreePath, fn (int $timeout) => $this->compose($worktreePath, $timeout));
        $onStep && $onStep('Saved the database dump '.basename($saved).' for the next worktrees.');
    }

    /**
     * Copy `$directory` from the main checkout with copy-on-write when the
     * lock files are equal. False when that is not possible.
     *
     * @param  list<string>  $lockFiles
     * @param  (Closure(string, string): void)|null  $output
     */
    protected function copyFromMain(string $worktreePath, string $directory, array $lockFiles, ?Closure $output, bool $enabled = true): bool
    {
        $main = MainCheckout::path();
        $source = $main.'/'.$directory;
        $target = $worktreePath.'/'.$directory;
        if (! $enabled || ! is_dir($source) || file_exists($target) || ! $this->locksEqual($main, $worktreePath, $lockFiles)) {
            return false;
        }
        $flags = PHP_OS_FAMILY === 'Darwin' ? ['-c'] : ['--reflink=always'];
        // One file first: without copy-on-write (ext4), cp fails for each file of the tree.
        $probe = $worktreePath.'/.worktree-cow-probe';
        $lock = current(array_filter($lockFiles, static fn (string $file): bool => is_file($main.'/'.$file)));
        $supported = $lock !== false && Process::timeout(30)->run(['cp', ...$flags, $main.'/'.$lock, $probe])->successful();
        File::delete($probe);
        if (! $supported) {
            $output && $output('out', "No copy-on-write on this file system: {$directory} is installed.\n");

            return false;
        }
        $result = Process::timeout(600)->run(['cp', ...$flags, '-R', $source, $target]);
        if (! $result->successful()) {
            // No copy-on-write on this filesystem: install normally.
            File::deleteDirectory($target);

            return false;
        }

        return true;
    }

    /**
     * @param  list<string>  $lockFiles
     */
    private function locksEqual(string $main, string $worktreePath, array $lockFiles): bool
    {
        $compared = false;
        foreach ($lockFiles as $file) {
            $a = $main.'/'.$file;
            $b = $worktreePath.'/'.$file;
            if (! is_file($a) && ! is_file($b)) {
                continue;
            }
            if (! is_file($a) || ! is_file($b) || hash_file('sha256', $a) !== hash_file('sha256', $b)) {
                return false;
            }
            $compared = true;
        }

        return $compared;
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
    protected function compose(string $worktreePath, int $timeout): PendingProcess
    {
        return ComposeProcess::in($worktreePath, $timeout);
    }

    protected function appService(): string
    {
        return $this->appService;
    }

    /**
     * @param  array<string, string>  $env
     */
    protected function execCommand(string $cmd, array $env = []): string
    {
        $options = '';
        foreach ($env as $key => $value) {
            $options .= ' -e '.escapeshellarg($key.'='.$value);
        }

        return sprintf(
            'docker compose exec -T%s %s sh -c %s',
            $options,
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
