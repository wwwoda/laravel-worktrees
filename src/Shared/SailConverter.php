<?php

namespace Woda\Worktrees\Shared;

use Closure;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Woda\Worktrees\ComposeProcess;
use Woda\Worktrees\Contracts\BootstrapStrategy;
use Woda\Worktrees\Contracts\ConfiguresWorktreeEnvironment;
use Woda\Worktrees\Contracts\ProvisionsDatabases;
use Woda\Worktrees\WorktreeManager;

/**
 * Moves worktrees of the Sail layout (one database container and volume per
 * worktree) to shared services, keeping their data.
 *
 * For each worktree: remove its old containers (by compose project label, so
 * the old compose file is not needed), start a temporary database container
 * straight from its old volume, dump the old database, import the dump into
 * the worktree's new app database on the shared server, and set up the rest
 * like `worktree:setup`. The old volume is never removed here.
 */
class SailConverter
{
    /** Names the database a conversion imported into, so a retry knows it as its own. */
    private const IMPORT_MARKER = 'storage/app/worktree/convert-imported';

    public function __construct(
        private readonly WorktreeManager $manager,
        private readonly DatabaseProvisioner $provisioner,
        private readonly BootstrapStrategy $strategy,
        private readonly string $image,
        private readonly string $volumePattern,
        private readonly string $baseBranch,
        /** @var list<string> containers of the shared stack, never removed here */
        private readonly array $keepContainers = [],
    ) {}

    /**
     * Every worktree and the main checkout, the main checkout first.
     *
     * @return list<array{name: string, path: string, branch: string|null, last_commit: string, merged: bool, converted: bool, main: bool}>
     */
    public function candidates(): array
    {
        $main = realpath(MainCheckout::path()) ?: MainCheckout::path();
        $list = [];
        foreach ($this->manager->registered() as $worktree) {
            $path = is_string($worktree['path'] ?? null) ? $worktree['path'] : '';
            if ($path === '' || ! is_dir($path)) {
                continue;
            }
            $isMain = (realpath($path) ?: $path) === $main;
            $branch = is_string($worktree['branch'] ?? null) ? $worktree['branch'] : null;
            $list[] = [
                'name' => $isMain ? 'main' : ($this->manager->nameFromPath($path) ?? trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower(basename($path))), '-')),
                'path' => $path,
                'branch' => $branch,
                'last_commit' => trim(Process::path($path)->timeout(10)->run('git log -1 --format=%cs')->output()),
                'merged' => ! $isMain && $branch !== null && $branch !== $this->baseBranch
                    && Process::path($main)->timeout(10)->run(['git', 'merge-base', '--is-ancestor', $branch, $this->baseBranch])->successful(),
                'converted' => $this->converted($path),
                'main' => $isMain,
            ];
        }
        usort($list, static fn (array $a, array $b): int => $b['main'] <=> $a['main']);

        return $list;
    }

    /**
     * True when `worktree:delete <name>` would act on exactly this folder.
     */
    public function deletableByName(string $name, string $path): bool
    {
        try {
            $managed = $this->manager->pathFor($name);
        } catch (RuntimeException) {
            return false;
        }

        return (realpath($managed) ?: $managed) === (realpath($path) ?: $path);
    }

    /**
     * Old database names need not follow the rules for new shared names
     * (`my-app` is fine); they are quoted in every command that uses them.
     */
    private static function validOldDatabase(string $database): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_$-]{1,64}$/', $database);
    }

    /**
     * True when a conversion of this folder finished (`converted_at`).
     */
    public static function isConverted(string $path): bool
    {
        $state = WorktreeState::load($path);

        return $state !== null && empty($state->data['converting']) && $state->string('converted_at') !== null;
    }

    /**
     * Converted, or set up with shared services and without an old Sail volume
     * whose data would still have to move. A worktree that was set up with shared
     * services while its old volume exists still converts.
     */
    private function converted(string $path): bool
    {
        if (self::isConverted($path)) {
            return true;
        }
        $state = WorktreeState::load($path);
        if ($state === null || ! empty($state->data['converting']) || $state->string('source') === null) {
            return false;
        }
        try {
            // A recorded old project wins: a shared setup may have rewritten .env.
            $volume = $this->volumeName($state->string('old_project') ?? $this->oldComposeProject($path));
        } catch (RuntimeException) {
            return false;
        }

        return ! $this->volumeExists($volume);
    }

    /**
     * Only Docker's "no such volume" means absent; any other failure (daemon down,
     * permissions) stops, so a worktree never looks converted by mistake.
     */
    private function volumeExists(string $volume): bool
    {
        $result = Process::timeout(30)->run(['docker', 'volume', 'inspect', $volume]);
        if ($result->successful()) {
            return true;
        }
        if (str_contains(strtolower($result->errorOutput()), 'no such volume')) {
            return false;
        }

        throw new RuntimeException("Cannot inspect Docker volume '{$volume}': {$result->errorOutput()}");
    }

    /**
     * @param  array{name: string, path: string, main: bool}  $candidate
     * @param  (Closure(string): void)|null  $onStep
     * @param  (Closure(string, string): void)|null  $output
     */
    public function convert(array $candidate, ?Closure $onStep = null, ?Closure $output = null): void
    {
        $step = $onStep ?? static fn (string $message) => null;
        $path = $candidate['path'];
        if (! $this->strategy instanceof ProvisionsDatabases) {
            throw new RuntimeException('worktree:convert needs the shared bootstrap strategy (worktrees.bootstrap.strategy = shared); nothing was changed.');
        }
        // Before any change: a name another worktree holds would share its databases.
        if (! $candidate['main']) {
            $this->manager->assertNameFree($candidate['name'], $path);
        }
        // The first run records the old names: a later run finds `.env` rewritten.
        [$project, $oldDatabase] = $this->markConverting($path, $candidate['main'] ? null : $this->provisioner->appDatabase($candidate['name']));
        $volumeExists = $this->volumeExists($this->volumeName($project));
        if ($volumeExists && ($oldDatabase === null || ! self::validOldDatabase($oldDatabase))) {
            throw new RuntimeException(sprintf("The old volume '%s' exists, but the old .env names %s. Fix DB_DATABASE (or old_database in %s) and run the conversion again; nothing was changed.",
                $this->volumeName($project), $oldDatabase === null ? 'no database' : "an unsupported database name '{$oldDatabase}'", WorktreeState::RELATIVE_PATH));
        }
        $step("Removing the old containers of compose project '{$project}'...");
        $this->removeOldContainers($project, $output);

        $dump = $this->dumpOldVolume($project, $oldDatabase, $step, $output);
        // Provisioning keeps an existing database and fills only a new one. When the
        // target exists without a recorded import, the old data would be dropped
        // silently and the template (or older) data would stay in use.
        // A target that this conversion made is fine: a recorded import, or an
        // unfinished copy that provisioning drops and imports again.
        $target = $this->provisioner->appDatabase($candidate['main'] ? null : $candidate['name']);
        $marker = $path.'/'.self::IMPORT_MARKER;
        if ($dump !== null) {
            $this->provisioner->ensureServer($step, $output);
            $state = WorktreeState::load($path);
            $pending = $state !== null && is_array($state->data['pending_copies'] ?? null) ? $state->data['pending_copies'] : [];
            $ours = $state?->string('source') === 'converted'
                || (File::exists($marker) && trim(File::get($marker)) === $target)
                || in_array($target, $pending, true);
            if (! $ours && $this->provisioner->server()->databaseExists($target)) {
                File::delete($dump);

                throw new RuntimeException("The shared database '{$target}' exists already, and the old volume holds data that would not be imported. Drop '{$target}' if it holds nothing you need, then run the conversion again.");
            }
        }
        $fill = $dump === null ? null : function (string $database) use ($dump, $output, $step, $marker): void {
            $step("Importing the old data into {$database}...");
            // The account of a worktree (and of the main checkout) has the name of its app database.
            $this->provisioner->server()->importFile($database, $dump, $output, owner: $database);
            File::ensureDirectoryExists(dirname($marker));
            File::put($marker, $database);
        };

        try {
            if ($candidate['main']) {
                // The shared layout first (COMPOSE_FILE, queue worker), then the new app
                // container: the template build runs in it. After the databases are set
                // up, recreate it so it starts with the new database settings.
                if ($this->strategy instanceof ConfiguresWorktreeEnvironment) {
                    $this->strategy->configureEnvironment($path, []);
                }
                $step('Starting the main checkout\'s app container...');
                $this->compose($path, 'docker compose up -d', $output);
                $this->provisioner->provisionMain($path, $fill, $output, $step);
                $this->compose($path, 'docker compose up -d --force-recreate', $output);
            } else {
                // The old bootstrap record says "done" for the Sail stages: start over.
                $record = $path.'/storage/worktree-bootstrap.json';
                if (File::exists($record) && ! File::exists($path.'/storage/worktree-bootstrap.sail.json')) {
                    File::move($record, $path.'/storage/worktree-bootstrap.sail.json');
                }
                $this->manager->bootstrapPath($candidate['name'], $path, [
                    'resume' => true,
                    'setup' => true,
                    'fill_app' => $fill,
                ], $step, $output);
            }
        } finally {
            if ($dump !== null) {
                File::delete($dump);
            }
        }

        $state = WorktreeState::load($path) ?? new WorktreeState([]);
        unset($state->data['converting']);
        $state->data['converted_at'] = date(DATE_ATOM);
        $state->data['old_volume'] = $this->volumeName($project);
        $state->save($path);
    }

    /**
     * @param  (Closure(string, string): void)|null  $output
     */
    private function compose(string $path, string $command, ?Closure $output): void
    {
        $result = ComposeProcess::in($path, 600)->run($command, $output);
        if (! $result->successful()) {
            throw new RuntimeException("'{$command}' in the main checkout failed: {$result->errorOutput()}");
        }
    }

    /**
     * Old volumes that match the backup pattern, with their size.
     *
     * @return list<array{name: string, size: string}>
     */
    public function backupVolumes(string $pattern): array
    {
        $result = Process::timeout(120)->run('docker system df -v --format json');
        if (! $result->successful()) {
            throw new RuntimeException("Cannot list Docker volumes: {$result->errorOutput()}");
        }
        $data = json_decode($result->output(), true);
        $volumes = is_array($data) && is_array($data['Volumes'] ?? null) ? $data['Volumes'] : [];
        $list = [];
        foreach ($volumes as $volume) {
            $name = is_array($volume) && is_string($volume['Name'] ?? null) ? $volume['Name'] : '';
            if ($name !== '' && preg_match($pattern, $name)) {
                $list[] = ['name' => $name, 'size' => is_string($volume['Size'] ?? null) ? $volume['Size'] : '?'];
            }
        }
        usort($list, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $list;
    }

    public function volumeName(string $project): string
    {
        return strtr($this->volumePattern, ['{compose_project}' => $project]);
    }

    private function oldComposeProject(string $path): string
    {
        $project = EnvFile::get($path.'/.env', 'COMPOSE_PROJECT_NAME');
        if ($project === null || $project === '' || str_contains($project, '$')) {
            // Compose's default: the folder name, lower case, letters, digits, dash and underscore.
            $project = (string) preg_replace('/[^a-z0-9_-]/', '', strtolower(basename($path)));
        }
        if (! preg_match('/^[a-z0-9][a-z0-9_-]*$/', $project)) {
            throw new RuntimeException("Unexpected compose project name '{$project}'.");
        }

        return $project;
    }

    /**
     * @return array{string, string|null}
     */
    private function markConverting(string $path, ?string $sharedDatabase): array
    {
        $state = WorktreeState::load($path) ?? new WorktreeState([]);
        // A shared setup before the conversion rewrote .env: its DB_DATABASE is the new
        // name, and the old one cannot be read from it any more.
        $envDatabase = EnvFile::get($path.'/.env', 'DB_DATABASE');
        if (! isset($state->data['old_database']) && $sharedDatabase !== null && $envDatabase !== null && strtolower($envDatabase) === strtolower($sharedDatabase)) {
            throw new RuntimeException("The .env already names the shared database '{$sharedDatabase}': the old Sail database name is unknown. Write it as old_database (and the old compose project as old_project) into ".WorktreeState::RELATIVE_PATH.', then run the conversion again; nothing was changed.');
        }
        $state->data['converting'] = true;
        $state->data['old_project'] ??= $this->oldComposeProject($path);
        $state->data['old_database'] ??= EnvFile::get($path.'/.env', 'DB_DATABASE');
        $state->save($path);

        return [(string) $state->string('old_project'), $state->string('old_database')];
    }

    /**
     * @param  (Closure(string, string): void)|null  $output
     */
    private function removeOldContainers(string $project, ?Closure $output): void
    {
        $ids = Process::timeout(30)->run(['docker', 'ps', '-a', '--filter', 'label=com.docker.compose.project='.$project, '--format', '{{.ID}} {{.Names}}']);
        if (! $ids->successful()) {
            throw new RuntimeException("Cannot list the old containers: {$ids->errorOutput()}");
        }
        // The shared stack may run in the same compose project as the main checkout: keep it.
        $list = [];
        foreach (array_filter(array_map(trim(...), explode("\n", $ids->output()))) as $line) {
            [$id, $containerName] = array_pad(explode(' ', $line, 2), 2, '');
            if (! in_array($containerName, $this->keepContainers, true)) {
                $list[] = $id;
            }
        }
        if ($list === []) {
            return;
        }
        $result = Process::timeout(120)->run(['docker', 'rm', '-f', ...$list], $output);
        if (! $result->successful()) {
            throw new RuntimeException("Removing the old containers failed: {$result->errorOutput()}");
        }
    }

    /**
     * Dump the old database from its volume into a temporary file; null when
     * the volume does not exist or holds no tables.
     *
     * @param  Closure(string): void  $step
     * @param  (Closure(string, string): void)|null  $output
     */
    private function dumpOldVolume(string $project, ?string $database, Closure $step, ?Closure $output): ?string
    {
        $volume = $this->volumeName($project);
        if (! $this->volumeExists($volume)) {
            $step("No old volume '{$volume}': the worktree starts from the template.");

            return null;
        }
        if ($database === null || ! self::validOldDatabase($database)) {
            throw new RuntimeException('The old .env names no usable database.');
        }
        $container = 'worktree-convert-'.substr(md5($volume), 0, 10);
        $step("Starting a temporary database from volume '{$volume}'...");
        // No network: with --skip-grant-tables the server takes any login, so only
        // docker exec (the local socket) may reach it.
        $started = Process::timeout(120)->run(['docker', 'run', '-d', '--rm', '--network', 'none', '--name', $container, '-v', $volume.':/var/lib/mysql', $this->image, '--skip-grant-tables'], $output);
        if (! $started->successful()) {
            throw new RuntimeException("Starting a database from volume '{$volume}' failed: {$started->errorOutput()}");
        }
        try {
            $deadline = time() + 90;
            while (! Process::timeout(10)->run(['docker', 'exec', $container, 'mariadb', '-N', '-e', 'SELECT 1'])->successful()) {
                if (time() >= $deadline) {
                    throw new RuntimeException("The database from volume '{$volume}' did not start.");
                }
                usleep(1_000_000);
            }
            $count = Process::timeout(30)->run(['docker', 'exec', $container, 'mariadb', '-N', '-e',
                "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '{$database}'"]);
            if (! $count->successful()) {
                // Not proof of an empty volume: stop, so a later run can retry.
                throw new RuntimeException("Reading the old database from volume '{$volume}' failed: {$count->errorOutput()}");
            }
            if ((int) trim($count->output()) === 0) {
                $step("The old volume '{$volume}' holds no data: the worktree starts from the template.");

                return null;
            }
            $file = (string) tempnam(sys_get_temp_dir(), 'worktree-convert-');
            $step("Dumping the old database '{$database}'...");
            $dump = Process::timeout(1800)->run(sprintf('docker exec %s mariadb-dump --single-transaction --routines --triggers --events %s > %s',
                escapeshellarg($container), escapeshellarg($database), escapeshellarg($file)), $output);
            if (! $dump->successful()) {
                File::delete($file);

                throw new RuntimeException("Dumping the old database failed: {$dump->errorOutput()}");
            }

            return $file;
        } catch (\Throwable $error) {
            throw $error;
        } finally {
            // A temporary server left on the volume would conflict with a later
            // conversion or volume removal. Its own error wins over a read error only
            // when nothing else failed.
            $removed = Process::timeout(60)->run(['docker', 'rm', '-f', $container]);
            if (! isset($error) && ! $removed->successful()
                && Process::timeout(30)->run(['docker', 'container', 'inspect', $container])->successful()) {
                if (isset($file)) {
                    File::delete($file);
                }

                throw new RuntimeException("Removing the temporary database '{$container}' failed: {$removed->errorOutput()}");
            }
        }
    }
}
