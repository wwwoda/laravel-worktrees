<?php

namespace Woda\Worktrees;

use Closure;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Woda\Worktrees\Agent\ComposeStack;
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
    // Written before a dump restore and removed after it: a restore that stopped half way.
    private const RESTORE_MARKER = 'storage/app/worktree/dump-restore.pending';

    // The output of the node install that runs while the containers start.
    public const NODE_INSTALL_LOG = 'storage/logs/worktree-node-install.log';

    protected readonly FastSetup $fast;

    /**
     * @param  FastSetup|null  $fast  Null: the container's FastSetup (the `fast` config
     *                                section), so a subclass that does not pass it keeps it.
     */
    public function __construct(
        private readonly string $appService,
        private readonly string $nodePackageManager,
        private readonly bool $buildFrontend,
        private readonly int $bringUpTimeout = 600,
        ?FastSetup $fast = null,
        private readonly ?Closure $onStep = null,
    ) {
        $this->fast = $fast ?? app(FastSetup::class);
    }

    public function bringUp(string $worktreePath, ?Closure $output = null): void
    {
        $config = $this->composeConfig($worktreePath);
        $this->assertOwnComposeProject($worktreePath, $config);
        $lent = $this->lendVendorPaths($worktreePath, $config);
        try {
            // The tag covers the lent build contexts (Sail's runtimes under vendor), and
            // goes into .env before compose runs, so compose resolves the shared image.
            $tag = $this->writeImageTag($worktreePath, $config);
            if ($tag !== null) {
                $this->ensureSharedImage($worktreePath, $tag, $output);
            }
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
        $this->waitForHealthy($worktreePath, $output);
    }

    /**
     * Wait while a service's healthcheck still says `starting`, so a test run
     * right after the setup does not meet a database that is still starting.
     * An unhealthy service is reported, not fatal: some healthchecks fail on a
     * working service.
     *
     * @param  (Closure(string, string): void)|null  $output
     */
    protected function waitForHealthy(string $worktreePath, ?Closure $output = null, int $seconds = 120): void
    {
        $deadline = time() + $seconds;
        do {
            $starting = [];
            $unhealthy = [];
            $result = $this->compose($worktreePath, 30)->run('docker compose ps --format json');
            if (! $result->successful()) {
                // Unknown is not ready: ask again until the deadline.
                $starting = ['(docker compose ps failed: '.trim($result->errorOutput()).')'];
                usleep(500_000);

                continue;
            }
            foreach (ComposeStack::parsePs($result->output()) as $container) {
                $service = $container['service'] !== '' ? $container['service'] : '?';
                if ($container['health'] === 'starting') {
                    $starting[] = $service;
                } elseif ($container['health'] === 'unhealthy') {
                    $unhealthy[] = $service;
                }
            }
            if ($starting === []) {
                break;
            }
            usleep(500_000);
        } while (time() < $deadline);
        if ($output && ($starting !== [] || $unhealthy !== [])) {
            $output('out', 'Not healthy yet: '.implode(', ', [...$starting, ...$unhealthy]).".\n");
        }
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
     * The project must not be the one of the main checkout or of any other
     * registered worktree (by their compose config), and no container of the
     * project may belong to another folder (by its owner label). A checkout
     * whose config compose cannot read (no `.env`) blocks only the projects
     * compose could give it. When the own project or the containers cannot
     * be read, the owner is unknown: refuse.
     *
     * @param  array<mixed>|null  $config
     */
    protected function assertOwnComposeProject(string $worktreePath, ?array $config): void
    {
        $ours = is_string($config['name'] ?? null) && $config['name'] !== '' ? $config['name'] : null;
        if ($ours === null) {
            throw new RuntimeException("Cannot read the compose project of {$worktreePath} (docker compose config failed or gave no project name), so it is not known which containers and volumes starting or stopping it would act on. Fix its compose setup, then run again.");
        }
        $self = realpath($worktreePath) ?: $worktreePath;
        $registered = $this->registeredCheckouts();
        foreach (array_unique([...$this->protectedCheckouts(), ...$registered]) as $checkout) {
            if ((realpath($checkout) ?: $checkout) === $self) {
                continue;
            }
            $theirs = $this->projectOf($checkout);
            if ($theirs === $ours) {
                throw new RuntimeException("The worktree has the same compose project as {$checkout} ('{$ours}'): starting or stopping it would act on that checkout's containers and volumes. Give worktrees their own COMPOSE_PROJECT_NAME (worktrees.env_overrides) and run again.");
            }
            if ($theirs === false && in_array($ours, $this->possibleProjectsOf($checkout), true)) {
                throw new RuntimeException("{$checkout} could have the same compose project ('{$ours}'): compose cannot read its config (docker compose config failed), and its .env, compose file or folder name give that project. Starting or stopping the worktree could act on that checkout's containers and volumes. Fix its compose setup (a missing .env?) or give worktrees their own COMPOSE_PROJECT_NAME (worktrees.env_overrides), then run again.");
            }
        }
        $this->assertContainersOwned($ours, $self, $registered);
    }

    /**
     * The compose project of another checkout; null when it has no compose file,
     * false when compose cannot read its config.
     */
    private function projectOf(string $checkout): string|false|null
    {
        if (! is_dir($checkout)) {
            throw new RuntimeException("Cannot read the compose project of {$checkout}: git registers it, but its folder is missing. Run git worktree prune, then run again.");
        }
        $name = $this->composeConfig($checkout)['name'] ?? null;
        if (is_string($name) && $name !== '') {
            return $name;
        }

        return $this->hasComposeFile($checkout) ? false : null;
    }

    /**
     * The project names compose could give a checkout whose config it cannot read:
     * COMPOSE_PROJECT_NAME of its `.env`, the top-level `name` of its compose files
     * (interpolated with its `.env`), and the folder names compose falls back to.
     *
     * @return list<string>
     */
    private function possibleProjectsOf(string $checkout): array
    {
        $env = $checkout.'/.env';
        $files = $this->composeFiles($checkout);
        $names = [EnvFile::resolved($env, 'COMPOSE_PROJECT_NAME'), basename($checkout)];
        if ($files !== []) {
            // The project folder is the folder of the first compose file.
            $names[] = basename(dirname($files[0]));
        }
        foreach ($files as $file) {
            $names[] = $this->declaredProjectName($file, $env);
        }
        $names = array_map(static fn (?string $name): string => ltrim((string) preg_replace('/[^a-z0-9_-]/', '', strtolower((string) $name)), '_-'), $names);

        return array_values(array_unique(array_filter($names, static fn (string $name): bool => $name !== '')));
    }

    /**
     * The compose files of a checkout that exist: those of COMPOSE_FILE in its
     * `.env`, else the default file and its override.
     *
     * @return list<string>
     */
    private function composeFiles(string $checkout): array
    {
        $list = EnvFile::resolved($checkout.'/.env', 'COMPOSE_FILE') ?? '';
        $separator = EnvFile::resolved($checkout.'/.env', 'COMPOSE_PATH_SEPARATOR') ?: (PHP_OS_FAMILY === 'Windows' ? ';' : ':');
        $files = array_values(array_filter(array_map(trim(...), explode($separator, $list)), static fn (string $file): bool => $file !== ''));
        if ($files === []) {
            $files = ['compose.yaml', 'compose.yml', 'docker-compose.yaml', 'docker-compose.yml', 'compose.override.yaml', 'compose.override.yml', 'docker-compose.override.yaml', 'docker-compose.override.yml'];
        }
        $paths = array_map(static fn (string $file): string => str_starts_with($file, '/') ? $file : $checkout.'/'.$file, $files);

        return array_values(array_filter($paths, is_file(...)));
    }

    /**
     * The top-level `name` of a compose file, with `${VAR}`, `${VAR:-default}`
     * and `${VAR-default}` taken from the checkout's `.env`; null when it has none.
     */
    private function declaredProjectName(string $file, string $env): ?string
    {
        if (! preg_match('/^name:[ \t]*(.*?)[ \t]*(?:[ \t]#.*)?$/m', (string) file_get_contents($file), $match)) {
            return null;
        }
        $value = $match[1];
        if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && str_ends_with($value, $value[0])) {
            $value = substr($value, 1, -1);
        }

        return (string) preg_replace_callback(
            '/\$\{(\w+)(?:(:?)-([^}]*))?\}|\$(\w+)/',
            static function (array $m) use ($env): string {
                $variable = ($m[4] ?? '') !== '' ? $m[4] : $m[1];
                $set = EnvFile::resolved($env, $variable);
                $default = $m[3] ?? '';
                if ($set === null || (($m[2] ?? '') === ':' && $set === '')) {
                    return $default;
                }

                return $set;
            },
            $value,
        );
    }

    private function hasComposeFile(string $checkout): bool
    {
        foreach (['compose.yaml', 'compose.yml', 'docker-compose.yaml', 'docker-compose.yml'] as $file) {
            if (is_file($checkout.'/'.$file)) {
                return true;
            }
        }
        $env = EnvFile::get($checkout.'/.env', 'COMPOSE_FILE');

        return $env !== null && $env !== '';
    }

    /**
     * Every container of the project must belong to this worktree: its
     * compose working folder (the folder of the compose file, also a
     * subfolder) lies in the worktree and in no other registered checkout
     * inside it.
     *
     * @param  list<string>  $registered
     */
    private function assertContainersOwned(string $project, string $self, array $registered): void
    {
        $result = Process::timeout(30)->run(sprintf(
            'docker ps -a --filter %s --format %s',
            escapeshellarg('label=com.docker.compose.project='.$project),
            escapeshellarg('{{.ID}} {{.Label "com.docker.compose.project.working_dir"}}'),
        ));
        if (! $result->successful()) {
            throw new RuntimeException("Cannot list the containers of the compose project '{$project}', so their owner is unknown: ".trim($result->errorOutput()));
        }
        $folders = [];
        // One line per container: its ID, a space, its owner label (empty when the label is missing).
        foreach (preg_split('/\r?\n/', $result->output()) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }
            [$id, $folder] = array_pad(explode(' ', $line, 2), 2, '');
            if (trim($folder) === '') {
                throw new RuntimeException("Container {$id} of the compose project '{$project}' has no owner label (com.docker.compose.project.working_dir), so its owner is unknown: starting or stopping the worktree could act on it. Remove the container or give worktrees their own COMPOSE_PROJECT_NAME (worktrees.env_overrides), then run again.");
            }
            $folders[] = trim($folder);
        }
        $checkouts = array_map(static fn (string $c): string => rtrim(realpath($c) ?: $c, '/'), [$self, ...$registered]);
        foreach (array_unique($folders) as $folder) {
            $real = rtrim(realpath($folder) ?: $folder, '/');
            $owner = null;
            foreach ($checkouts as $checkout) {
                if (($real === $checkout || str_starts_with($real, $checkout.'/')) && strlen($checkout) > strlen((string) $owner)) {
                    $owner = $checkout;
                }
            }
            if ($owner !== $self) {
                throw new RuntimeException("Containers of the compose project '{$project}' belong to {$folder}: starting or stopping the worktree would act on them. Give worktrees their own COMPOSE_PROJECT_NAME (worktrees.env_overrides) and run again.");
            }
        }
    }

    /**
     * The folders of all checkouts git registers for the repository (the main
     * checkout first).
     *
     * @return list<string>
     */
    private function registeredCheckouts(): array
    {
        $result = Process::path(base_path())->timeout(30)->run('git worktree list --porcelain');
        if (! $result->successful()) {
            throw new RuntimeException('Cannot list the worktrees of the repository, so the owners of compose projects are unknown: '.trim($result->errorOutput()));
        }
        preg_match_all('/^worktree (.+)$/m', $result->output(), $matches);

        return array_map(trim(...), $matches[1]);
    }

    /**
     * Checkouts whose compose project a worktree must not share, besides the
     * registered ones.
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
        // A subclass with its own node install: the parallel start would bypass it.
        if (! $this->fast->parallelNode || (new \ReflectionMethod($this, 'installNodeDependencies'))->getDeclaringClass()->getName() !== self::class) {
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
     * Start the node install in the background. Its output goes into a file,
     * not a pipe: nothing reads a pipe while the containers start, and an
     * install would block once the pipe holds 64 KiB.
     */
    public function startNodeInstall(string $worktreePath): InvokedProcess
    {
        File::ensureDirectoryExists(dirname($worktreePath.'/'.self::NODE_INSTALL_LOG));
        File::delete($worktreePath.'/'.self::NODE_INSTALL_LOG);

        return Process::path($worktreePath)->timeout(300)->quietly()
            ->start($this->nodeInstallCommand().' > '.escapeshellarg(self::NODE_INSTALL_LOG).' 2>&1');
    }

    /**
     * Wait for the node install of startNodeInstall(); its output goes to `$output` then.
     *
     * @param  (Closure(string, string): void)|null  $output
     */
    public function finishNodeInstall(InvokedProcess $process, string $worktreePath, ?Closure $output = null): void
    {
        $result = $process->wait();
        $log = is_file($worktreePath.'/'.self::NODE_INSTALL_LOG) ? File::get($worktreePath.'/'.self::NODE_INSTALL_LOG) : '';
        if ($output && $log !== '') {
            $output('out', $log);
        }
        if (! $result->successful()) {
            throw new RuntimeException('Node dependency install failed (see '.self::NODE_INSTALL_LOG.'): '.trim(substr($log, -2000)));
        }
    }

    public function buildFrontend(string $worktreePath, ?Closure $output = null): void
    {
        if (! $this->buildFrontend) {
            return;
        }

        // Without the variables of the checkout that runs artisan: Vite reads VITE_*
        // from the environment before .env, and would build with the main checkout's values.
        $result = $this->compose($worktreePath, 300)->run("{$this->nodePackageManager} run build", $output);

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
     * The shared app image's tag, written into `.env`; null when the shared image is off.
     */
    /**
     * @param  array<mixed>|null  $config
     */
    private function writeImageTag(string $worktreePath, ?array $config): ?string
    {
        $image = $this->fast->image;
        if (! $image->enabled()) {
            return null;
        }
        $services = is_array($config['services'] ?? null) ? $config['services'] : [];
        $service = $services[$image->service($this->appService)] ?? null;
        $build = is_array($service) && is_array($service['build'] ?? null) ? $service['build'] : null;
        $tag = $image->tag($worktreePath, $build);
        if (EnvFile::get($worktreePath.'/.env', $image->envKey()) !== $tag) {
            EnvFile::upsert($worktreePath.'/.env', [$image->envKey() => $tag]);
        }

        return $tag;
    }

    /**
     * Build the shared app image once when it is missing.
     *
     * @param  (Closure(string, string): void)|null  $output
     */
    private function ensureSharedImage(string $worktreePath, string $tag, ?Closure $output): void
    {
        $built = $this->fast->image->ensure($tag, $this->appService, fn (int $timeout) => $this->compose($worktreePath, $timeout), $output, $this->onStep);
        if (! $built && $output) {
            $output('out', "Using the app image {$tag}.\n");
        }
    }

    public function databaseDumpEnabled(): bool
    {
        return $this->fast->dump->enabled();
    }

    public function restoreDatabaseDump(string $worktreePath, ?Closure $output = null, ?Closure $onStep = null): string
    {
        $dump = $this->fast->dump;
        $pending = $worktreePath.'/'.self::RESTORE_MARKER;
        // A restore that stopped half way left some tables: restore again over them
        // (the dump drops each table before it creates it). Else only into an empty
        // database: a restore over existing tables would replace them.
        $interrupted = is_file($pending);
        if (! $interrupted && ! $this->databaseIsEmpty($worktreePath)) {
            $onStep && $onStep('The database has tables; it is kept, and neither restored nor seeded.');

            return self::DUMP_EXISTING;
        }
        // No migrations table does not prove an empty database: an adopted
        // database, or a first migrate that failed half way, has tables without it.
        if (! $interrupted && $this->databaseHasTables($worktreePath)) {
            $onStep && $onStep('The database has tables but no migrations table; it is kept, and neither restored nor seeded.');

            return self::DUMP_EXISTING;
        }
        $file = $dump->find($worktreePath, $onStep);
        if ($file === null && $interrupted) {
            throw new RuntimeException('A restore of the database dump stopped half way, and the dump is gone: empty the database (or delete the worktree), remove '.self::RESTORE_MARKER.' and run the setup again.');
        }
        if ($file === null) {
            $onStep && $onStep('No database dump for key '.$dump->key($worktreePath).' yet: this setup seeds and saves one.');

            return self::DUMP_MISSING;
        }
        $onStep && $onStep(($interrupted ? 'Restoring the database dump again (the last restore stopped half way): ' : 'Restoring the database dump ').basename($file).'...');
        File::ensureDirectoryExists(dirname($pending));
        File::put($pending, date(DATE_ATOM)."\n");
        $dump->restore($file, $worktreePath, fn (int $timeout) => $this->compose($worktreePath, $timeout), $output);
        File::delete($pending);

        return self::DUMP_RESTORED;
    }

    /**
     * How long the dump stage waits for the database service to answer. The
     * containers have just started, and a fast Composer leaves the database
     * no time to start up.
     */
    protected int $databaseWaitSeconds = 60;

    /**
     * True without a migrations table (then databaseHasTables() decides).
     * The message counts, not the exit code
     * alone. Another error (the database does not answer yet) is tried again
     * every second, for up to $databaseWaitSeconds.
     */
    private function databaseIsEmpty(string $worktreePath): bool
    {
        $deadline = time() + $this->databaseWaitSeconds;
        while (true) {
            $status = $this->compose($worktreePath, 120)->run($this->execCommand('php artisan migrate:status --no-ansi --no-interaction'));
            if (stripos($status->output().$status->errorOutput(), 'Migration table not found') !== false) {
                return true;
            }
            if ($status->successful()) {
                return false;
            }
            if (time() >= $deadline) {
                throw new RuntimeException('Cannot tell whether the database is empty: '.trim($status->errorOutput() ?: $status->output()));
            }
            sleep(1);
        }
    }

    /**
     * Whether the database has any table, as `php artisan db:show --json` in
     * the app container lists them. Throws when the list cannot be read: a
     * restore must not run on a database whose state is unknown.
     */
    private function databaseHasTables(string $worktreePath): bool
    {
        $show = $this->compose($worktreePath, 120)->run($this->execCommand('php artisan db:show --json --no-ansi --no-interaction'));
        $output = $show->output();
        $start = strpos($output, '{');
        $data = $start === false ? null : json_decode(substr($output, $start), true);
        if (! $show->successful() || ! is_array($data) || ! is_array($data['tables'] ?? null)) {
            throw new RuntimeException('Cannot tell whether the database has tables, so the dump is not restored (php artisan db:show --json): '.trim($show->errorOutput() ?: $output));
        }

        return $data['tables'] !== [];
    }

    public function seedDatabase(string $worktreePath, ?Closure $output = null): void
    {
        $result = $this->compose($worktreePath, 1800)->run($this->execCommand($this->fast->dump->seedCommand()), $output);
        if (! $result->successful()) {
            throw new RuntimeException("The seed command failed: {$result->errorOutput()}");
        }
    }

    public function saveDatabaseDump(string $worktreePath, ?Closure $onStep = null): void
    {
        $saved = $this->fast->dump->save($worktreePath, fn (int $timeout) => $this->compose($worktreePath, $timeout));
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
        $config = $this->composeConfig($worktreePath);
        $this->assertOwnComposeProject($worktreePath, $config);
        $result = $this->compose($worktreePath, 120)->run(
            'docker compose --profile "*" down'.($keepVolumes ? '' : ' -v'), $output,
        );
        if (! $result->successful()) {
            throw new RuntimeException('Worktree environment teardown failed; checkout retained.');
        }
        $this->removeOwnImages($config, $output);
    }

    /**
     * Remove the images that the worktree's compose project built for itself:
     * services with a `build:` whose image name holds the project name (the
     * per-worktree tag `{project}/app:latest`, or compose's default
     * `{project}-{service}`). Shared images (another name, the main checkout's
     * images) stay; an image that another container still uses stays too.
     * When the main checkout's compose config cannot be read, no image is removed.
     *
     * @param  array<mixed>|null  $config
     * @param  (Closure(string, string): void)|null  $output
     * @return list<string> the removed images
     */
    protected function removeOwnImages(?array $config, ?Closure $output = null): array
    {
        $project = is_string($config['name'] ?? null) ? $config['name'] : '';
        if ($project === '') {
            return [];
        }
        $main = $this->composeConfig(base_path());
        if ($main === null) {
            // Without the main checkout's images, a worktree image with the same name is not known: remove none.
            $output && $output('out', "Kept the images of {$project}: the compose config of the main checkout could not be read.\n");

            return [];
        }
        $keep = $this->imagesOf($main);
        $removed = [];
        foreach ($this->imagesOf($config, builtOnly: true) as $service => $image) {
            // Worker services often share the app service's image: remove it once.
            if (in_array($image, $keep, true) || in_array($image, $removed, true) || ! self::isProjectImage($image, $project, (string) $service)) {
                continue;
            }
            if (Process::timeout(120)->run('docker image rm '.escapeshellarg($image))->successful()) {
                $removed[] = $image;
                $output && $output('out', "Removed the image {$image}.\n");
            }
        }

        return $removed;
    }

    /**
     * An image of the compose project itself: `{project}/…` (the per-worktree
     * tag `{project}/app:latest`) or compose's default `{project}-{service}`,
     * with any tag. A name that only contains the project name is not one: a
     * worktree named `app` must not remove the shared `hungryport-app:{key}`.
     */
    private static function isProjectImage(string $image, string $project, string $service): bool
    {
        $slash = strrpos($image, '/');
        $colon = strrpos($image, ':');
        $name = $colon !== false && ($slash === false || $colon > $slash) ? substr($image, 0, $colon) : $image;

        return str_starts_with($name, $project.'/') || $name === $project.'-'.$service;
    }

    /**
     * The image of each service, by service name.
     *
     * @param  array<mixed>|null  $config
     * @return array<string, string>
     */
    private function imagesOf(?array $config, bool $builtOnly = false): array
    {
        $project = is_string($config['name'] ?? null) ? $config['name'] : '';
        $services = is_array($config['services'] ?? null) ? $config['services'] : [];
        $images = [];
        foreach ($services as $name => $service) {
            if (! is_array($service) || ($builtOnly && ! isset($service['build']))) {
                continue;
            }
            $image = is_string($service['image'] ?? null) ? $service['image'] : (isset($service['build']) && $project !== '' ? $project.'-'.$name : null);
            if ($image !== null) {
                $images[(string) $name] = $image;
            }
        }

        return $images;
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
