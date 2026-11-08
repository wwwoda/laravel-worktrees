<?php

namespace Woda\Worktrees;

use Closure;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Woda\Worktrees\Contracts\ConfiguresWorktreeEnvironment;
use Woda\Worktrees\Contracts\ProvisionsDatabases;
use Woda\Worktrees\Shared\DatabaseProvisioner;
use Woda\Worktrees\Shared\EnvFile;
use Woda\Worktrees\Shared\MainCheckout;
use Woda\Worktrees\Shared\QueueList;
use Woda\Worktrees\Shared\WorktreeState;

/**
 * Shared services: one database server and one Redis per machine, used by
 * all worktrees, and one app container per worktree.
 *
 * Each worktree gets its own databases (copied from a template), its own
 * database user with rights on its exact database names only, and its own
 * Redis database numbers. Containers are handled like the Sail strategy:
 * `docker compose up -d` in the worktree, PHP commands via `compose exec`.
 *
 * Dependencies: `vendor` and the host `node_modules` are copied from the main
 * checkout with copy-on-write when the lock files are equal (APFS `cp -c` on
 * macOS, `cp --reflink=always` elsewhere); when that is not possible,
 * `composer install` runs in the container and the node install on the host.
 * The bundle is built inside the container, whose `node_modules` is the
 * project's own Docker volume (native packages install for one platform).
 */
class SharedServicesBootstrapStrategy extends SailBootstrapStrategy implements ConfiguresWorktreeEnvironment, ProvisionsDatabases
{
    private const NODE_LOCK_FILES = ['pnpm-lock.yaml', 'package-lock.json', 'yarn.lock', 'bun.lock', 'bun.lockb'];

    /**
     * @param  array{mode: string, queues: string}  $queueEnvKeys
     * @param  (Closure(string): bool)|null  $devServerAnswers
     */
    public function __construct(
        private readonly DatabaseProvisioner $provisioner,
        string $appService,
        private readonly string $nodePackageManager,
        private readonly bool $buildFrontend,
        private readonly ?string $composeFile = null,
        private readonly string $queueMode = 'listen',
        private readonly array $queueEnvKeys = ['mode' => 'WORKTREE_QUEUE_WORKER', 'queues' => 'WORKTREE_QUEUES'],
        private readonly bool $copyOnWrite = true,
        private readonly ?Closure $devServerAnswers = null,
        int $bringUpTimeout = 600,
    ) {
        parent::__construct($appService, $nodePackageManager, $buildFrontend, $bringUpTimeout);
    }

    public function configureEnvironment(string $worktreePath, array $options): void
    {
        $values = [
            $this->queueEnvKeys['mode'] => ! empty($options['horizon']) ? 'horizon' : $this->queueMode,
            $this->queueEnvKeys['queues'] => implode(',', QueueList::for($worktreePath)),
        ];
        if ($this->composeFile !== null && $this->composeFile !== '') {
            $values = ['COMPOSE_FILE' => $this->composeFile, ...$values];
        }
        EnvFile::upsert($worktreePath.'/.env', $values);
    }

    /**
     * The shared stack runs in the main checkout's compose project: a worktree
     * must not share it, also when artisan runs in a linked worktree.
     */
    protected function protectedCheckouts(): array
    {
        return [...parent::protectedCheckouts(), MainCheckout::path()];
    }

    public function provisionDatabases(string $name, string $worktreePath, array $options, ?Closure $output = null, ?Closure $onStep = null): void
    {
        $this->provisioner->provision(
            $name,
            $worktreePath,
            ['branch' => $options['branch'] ?? null, 'base' => $options['base'] ?? null],
            (bool) ($options['from_main'] ?? false),
            $output,
            $onStep,
        );
    }

    public function bringUp(string $worktreePath, ?Closure $output = null): void
    {
        parent::bringUp($worktreePath, $output);
        $this->removeStaleHotFile($worktreePath, $output);
    }

    public function installComposerDependencies(string $worktreePath, ?Closure $output = null): void
    {
        if ($this->copyFromMain($worktreePath, 'vendor', ['composer.lock'], $output)) {
            // The copied autoload files describe the main checkout's classes; the
            // branch may have moved some. Build them for this worktree.
            $result = $this->compose($worktreePath, 300)->run($this->execCommand('composer dump-autoload --no-interaction'), $output);
            if (! $result->successful()) {
                throw new RuntimeException("composer dump-autoload failed: {$result->errorOutput()}");
            }

            return;
        }
        parent::installComposerDependencies($worktreePath, $output);
    }

    public function installNodeDependencies(string $worktreePath, ?Closure $output = null): void
    {
        // The host copy serves the IDE and edit hooks; the container has its own.
        if ($this->copyFromMain($worktreePath, 'node_modules', self::NODE_LOCK_FILES, $output)) {
            return;
        }
        parent::installNodeDependencies($worktreePath, $output);
    }

    public function buildFrontend(string $worktreePath, ?Closure $output = null): void
    {
        if (! $this->buildFrontend) {
            return;
        }
        $pm = $this->nodePackageManager;
        // Install exactly the lock file: npm has its own command for that.
        $install = $pm === 'npm' ? 'npm ci' : "{$pm} install --frozen-lockfile";
        $result = $this->compose($worktreePath, 900)->run(
            $this->execCommand("{$install} && {$pm} run build"),
            $output,
        );
        if (! $result->successful()) {
            throw new RuntimeException("Frontend build in the container failed: {$result->errorOutput()}");
        }
    }

    /**
     * Migrations newer than the template run in each database that started
     * as a copy: the app, test and e2e databases.
     */
    public function runMigrations(string $worktreePath, ?Closure $output = null): void
    {
        $state = WorktreeState::load($worktreePath);
        $databases = $state?->data['databases'] ?? null;
        $targets = is_array($databases)
            ? array_values(array_filter([$databases['app'] ?? null, $databases['test'] ?? null, $databases['e2e'] ?? null], is_string(...)))
            : [];
        if ($targets === []) {
            parent::runMigrations($worktreePath, $output);

            return;
        }
        foreach ($targets as $database) {
            $result = $this->compose($worktreePath, 600)->run(
                // A config cache would ignore DB_DATABASE: read the config fresh.
                sprintf('docker compose exec -T -e %s -e %s %s sh -c %s',
                    escapeshellarg('DB_DATABASE='.$database),
                    escapeshellarg('APP_CONFIG_CACHE=/tmp/worktree-migrate-'.bin2hex(random_bytes(6)).'-config.php'),
                    escapeshellarg($this->appService()),
                    escapeshellarg('php artisan migrate --force'),
                ),
                $output,
            );
            if (! $result->successful()) {
                throw new RuntimeException("Migration of {$database} failed: {$result->errorOutput()}");
            }
        }
    }

    /**
     * Copy `$directory` from the main checkout with copy-on-write when the
     * lock files are equal. False when that is not possible.
     *
     * @param  list<string>  $lockFiles
     * @param  (Closure(string, string): void)|null  $output
     */
    private function copyFromMain(string $worktreePath, string $directory, array $lockFiles, ?Closure $output): bool
    {
        $main = MainCheckout::path();
        $source = $main.'/'.$directory;
        $target = $worktreePath.'/'.$directory;
        if (! $this->copyOnWrite || ! is_dir($source) || file_exists($target) || ! $this->locksEqual($main, $worktreePath, $lockFiles)) {
            return false;
        }
        $command = PHP_OS_FAMILY === 'Darwin'
            ? ['cp', '-c', '-R', $source, $target]
            : ['cp', '-R', '--reflink=always', $source, $target];
        $result = Process::timeout(600)->run($command, $output);
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

    /**
     * @param  (Closure(string, string): void)|null  $output
     */
    private function removeStaleHotFile(string $worktreePath, ?Closure $output): void
    {
        $hot = $worktreePath.'/public/hot';
        if (! is_file($hot)) {
            return;
        }
        $url = trim((string) File::get($hot));
        $answers = $this->devServerAnswers ?? static function (string $url): bool {
            if (! preg_match('#^https?://#', $url)) {
                return false;
            }
            $context = stream_context_create(['http' => ['timeout' => 2, 'ignore_errors' => true], 'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);

            return @file_get_contents(rtrim($url, '/').'/@vite/client', false, $context, 0, 1) !== false;
        };
        if (! $answers($url)) {
            File::delete($hot);
            $output && $output('out', "Removed public/hot: no dev server answers at {$url}.\n");
        }
    }
}
