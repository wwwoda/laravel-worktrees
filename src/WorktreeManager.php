<?php

namespace Woda\Worktrees;

use Closure;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Woda\Worktrees\Contracts\BootstrapStrategy;
use Woda\Worktrees\Contracts\ConfiguresWorktreeEnvironment;
use Woda\Worktrees\Contracts\InstallsNodeBeforeBringUp;
use Woda\Worktrees\Contracts\ProvisionsDatabases;
use Woda\Worktrees\Contracts\UsesDatabaseDump;
use Woda\Worktrees\Fast\BuildCache;
use Woda\Worktrees\Shared\EnvFile;
use Woda\Worktrees\Shared\SailConverter;

class WorktreeManager
{
    /**
     * `git status` without the files this package writes into a worktree:
     * they are untracked in most projects and would make every bootstrapped
     * worktree look dirty to delete and cleanup.
     */
    private const STATUS_COMMAND = "git status --porcelain -- . ':(exclude)storage/worktree-bootstrap.json' ':(exclude)storage/worktree-bootstrap.lock' ':(exclude)storage/worktree-bootstrap.clone' ':(exclude)storage/app/worktree' ':(exclude)storage/logs/worktree-setup.log'";

    private const CLONE_MARKER = 'storage/worktree-bootstrap.clone';

    public function __construct(
        private readonly string $basePath,
        private readonly string $branchPrefix,
        private readonly string $baseBranch,
        /** @var list<string> */
        private readonly array $copyFiles,
        private readonly DatabaseCloner $databaseCloner,
        private readonly BootstrapStrategy $bootstrapStrategy,
        private readonly bool $buildFrontend,
        private readonly bool $runMigrations,
        /** @var array<string, string|Closure(string $name, string $worktreePath): string> */
        private readonly array $envOverrides = [],
        private readonly ?BuildCache $buildCache = null,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function list(): array
    {
        $worktrees = $this->registered();

        // Filter out the main worktree and add derived name
        $mainPath = base_path();

        return array_values(array_filter(array_map(
            function (array $wt): ?array {
                $name = $this->nameFromPath(is_string($wt['path'] ?? null) ? $wt['path'] : '');
                if ($name === null) {
                    return null;
                }
                $wt['name'] = $name;

                return $wt;
            },
            array_filter(
                $worktrees,
                fn (array $wt): bool => ($wt['path'] ?? '') !== $mainPath,
            ),
        )));
    }

    /**
     * Every worktree git knows, wherever its folder is.
     *
     * @return list<array<string, mixed>>
     */
    public function registered(): array
    {
        $result = $this->git('worktree list --porcelain');

        if (! $result->successful()) {
            throw new RuntimeException("Failed to list worktrees: {$result->errorOutput()}");
        }

        $worktrees = [];
        $current = [];

        foreach (explode("\n", $result->output()) as $line) {
            $line = trim($line);

            if ($line === '') {
                if ($current !== []) {
                    $worktrees[] = $current;
                    $current = [];
                }

                continue;
            }

            if (str_starts_with($line, 'worktree ')) {
                $current['path'] = substr($line, 9);
            } elseif (str_starts_with($line, 'HEAD ')) {
                $current['head'] = substr($line, 5);
            } elseif (str_starts_with($line, 'branch ')) {
                $current['branch'] = str_replace('refs/heads/', '', substr($line, 7));
            } elseif ($line === 'bare') {
                $current['bare'] = true;
            }
        }

        if ($current !== []) {
            $worktrees[] = $current;
        }

        return $worktrees;
    }

    public function create(string $name, ?string $branch = null, ?string $baseBranch = null): string
    {
        $branch ??= $this->branchPrefix.$name;
        $baseBranch ??= $this->baseBranch;
        $path = $this->pathFor($name);

        if ($this->exists($name)) {
            throw new RuntimeException("Worktree '{$name}' already exists at {$path}");
        }

        $local = $this->git('show-ref --verify --quiet '.escapeshellarg('refs/heads/'.$branch));
        $remote = $this->git('show-ref --verify --quiet '.escapeshellarg('refs/remotes/origin/'.$branch));

        $command = $local->successful()
            ? sprintf('worktree add %s %s', escapeshellarg($path), escapeshellarg($branch))
            : sprintf('worktree add -b %s %s %s', escapeshellarg($branch), escapeshellarg($path),
                escapeshellarg($remote->successful() ? 'origin/'.$branch : $baseBranch));
        $result = $this->git($command);

        if (! $result->successful()) {
            throw new RuntimeException("Failed to create worktree: {$result->errorOutput()}");
        }

        return $path;
    }

    public function remove(string $name): void
    {
        $path = $this->pathFor($name);

        if (! $this->exists($name)) {
            throw new RuntimeException("Worktree '{$name}' does not exist.");
        }
        // Only registered worktrees are deleted; a look-alike directory is not ours to remove.
        $this->describe($name);

        // Always delete directory manually + prune. git worktree remove fails
        // when untracked/gitignored files exist (e.g. .env, .claude, node_modules).
        File::deleteDirectory($path);
        clearstatcache();
        if (is_dir($path)) {
            throw new RuntimeException("The folder {$path} could not be removed completely (files owned by another user, such as the container's?). Remove it by hand, then run: git worktree prune");
        }
        $this->git('worktree prune');
        foreach ($this->list() as $worktree) {
            $registered = is_string($worktree['path'] ?? null) ? $worktree['path'] : '';
            if ((realpath($registered) ?: $registered) === $path) {
                throw new RuntimeException("The folder is gone, but git still lists the worktree {$path}. Run: git worktree prune");
            }
        }
    }

    /**
     * Drop the worktree's own databases on shared services (no-op for other
     * strategies, whose databases the DatabaseCloner drops).
     *
     * @param  (Closure(string): void)|null  $onStep
     * @return list<string>
     */
    public function removeSharedDatabases(string $name, ?Closure $onStep = null): array
    {
        if (! $this->bootstrapStrategy instanceof ProvisionsDatabases) {
            return [];
        }

        return $this->bootstrapStrategy->removeDatabases($name, $this->pathFor($name), $onStep);
    }

    /**
     * Throw when the state file does not allow removeSharedDatabases(); changes nothing.
     */
    public function checkSharedDatabases(string $name): void
    {
        if ($this->bootstrapStrategy instanceof ProvisionsDatabases) {
            $this->bootstrapStrategy->checkRemoveDatabases($name, $this->pathFor($name));
        }
    }

    public function usesSharedDatabases(): bool
    {
        return $this->bootstrapStrategy instanceof ProvisionsDatabases;
    }

    /**
     * After a delete: is the local branch still there, and is it merged into the base branch?
     *
     * @return array{exists: bool, merged: bool}
     */
    public function branchStatus(string $branch): array
    {
        $exists = $this->git('show-ref --verify --quiet '.escapeshellarg('refs/heads/'.$branch))->successful();

        return [
            'exists' => $exists,
            'merged' => $exists && $this->git('merge-base --is-ancestor '.escapeshellarg($branch).' '.escapeshellarg($this->baseBranch))->successful(),
        ];
    }

    public function deleteMergedBranch(string $branch): bool
    {
        return $this->git('branch -d '.escapeshellarg($branch))->successful();
    }

    public function exists(string $name): bool
    {
        $path = $this->pathFor($name);

        return is_dir($path);
    }

    public function pathFor(string $name): string
    {
        if (! preg_match('/^[a-zA-Z0-9-]+$/', $name)) {
            throw new RuntimeException('Invalid worktree name.');
        }

        $projectName = basename(base_path());

        $basePath = realpath($this->basePath) ?: $this->basePath;

        return $basePath.'/'.$projectName.'-'.$name;
    }

    /**
     * @return array{clean: bool, unpushed: bool}
     */
    public function safetyCheck(string $name): array
    {
        $path = $this->pathFor($name);

        // Check for uncommitted changes
        $statusResult = Process::path($path)->timeout(10)->run(self::STATUS_COMMAND);
        if (! $statusResult->successful()) {
            throw new RuntimeException('Cannot verify worktree changes.');
        }
        $clean = trim($statusResult->output()) === '';

        // Check for unpushed commits
        $branchResult = Process::path($path)->timeout(10)->run('git rev-parse --abbrev-ref HEAD');
        if (! $branchResult->successful()) {
            throw new RuntimeException('Cannot verify worktree HEAD.');
        }
        $branch = trim($branchResult->output());

        $logResult = Process::path($path)->timeout(10)->run(
            sprintf('git log %s --not --remotes --oneline', escapeshellarg($branch)),
        );
        if (! $logResult->successful()) {
            throw new RuntimeException('Cannot verify unpushed work.');
        }
        $unpushed = trim($logResult->output()) !== '';

        return [
            'clean' => $clean,
            'unpushed' => $unpushed,
        ];
    }

    /**
     * @param  array{skip_deps?: bool, skip_build?: bool, skip_db?: bool, resume?: bool, from_main?: bool, base?: string|null, horizon?: bool}  $options
     * @param  (Closure(string): void)|null  $onStep
     * @param  (Closure(string, string): void)|null  $processOutput
     * @param  (Closure(string, float): void)|null  $onStageDone  stage id and seconds
     */
    public function bootstrap(
        string $name,
        array $options = [],
        ?Closure $onStep = null,
        ?Closure $processOutput = null,
        ?Closure $onStageDone = null,
    ): void {
        $this->bootstrapPath($name, $this->pathFor($name), $options, $onStep, $processOutput, $onStageDone);
    }

    /**
     * Bootstrap a registered worktree at any path (frontier makes its own
     * folders). With `setup`, existing config files are kept and a `.env`
     * without a bootstrap record is completed instead of refused.
     *
     * @param  array{skip_deps?: bool, skip_build?: bool, skip_db?: bool, resume?: bool, from_main?: bool, base?: string|null, horizon?: bool, setup?: bool, fill_app?: (Closure(string): void)|null}  $options
     * @param  (Closure(string): void)|null  $onStep
     * @param  (Closure(string, string): void)|null  $processOutput
     * @param  (Closure(string, float): void)|null  $onStageDone  stage id and seconds
     */
    public function bootstrapPath(
        string $name,
        string $path,
        array $options = [],
        ?Closure $onStep = null,
        ?Closure $processOutput = null,
        ?Closure $onStageDone = null,
    ): void {
        if (! preg_match('/^[a-zA-Z0-9-]+$/', $name)) {
            throw new RuntimeException('Invalid worktree name.');
        }
        $step = $onStep ?? static fn () => null;
        $path = rtrim(realpath($path) ?: $path, '/');

        $identity = $this->describePath($path);
        if ($this->isMainCheckout($path)) {
            throw new RuntimeException('This is the main checkout; a worktree setup does not change it.');
        }
        $this->assertNameFree($name, $path);
        File::ensureDirectoryExists($path.'/storage');
        $handle = fopen($path.'/storage/worktree-bootstrap.lock', 'c');
        if ($handle === false) {
            throw new RuntimeException('Cannot open the worktree bootstrap lock.');
        }
        if (! flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            throw new RuntimeException('Worktree bootstrap is already running.');
        }

        $parallelNode = null;
        try {
            $statePath = $path.'/storage/worktree-bootstrap.json';
            $decoded = File::exists($statePath)
                ? json_decode(File::get($statePath), true, flags: JSON_THROW_ON_ERROR)
                : ['completed' => []];
            if (! $this->isBootstrapRecord($decoded)) {
                throw new RuntimeException('storage/worktree-bootstrap.json does not have the shape of a bootstrap record. Review or remove it, then resume.');
            }
            /** @var array{completed: list<string>, stage?: string, status?: string, branch?: string|null, initial_head?: string, name?: string} $state */
            $state = $decoded;
            if (isset($state['name']) && $state['name'] !== $name) {
                throw new RuntimeException("This worktree was set up with the name '{$state['name']}'; continue with that name.");
            }
            $state['name'] = $name;
            if (array_key_exists('branch', $state) && $state['branch'] !== $identity['branch']) {
                throw new RuntimeException('Worktree branch changed since bootstrap.');
            }
            $state['branch'] = $identity['branch'];
            $state['initial_head'] ??= $identity['head'];
            $resume = ! empty($options['resume']);
            $setup = ! empty($options['setup']);
            if ($resume && ! $setup && ! in_array('config', $state['completed'], true) && File::exists($path.'/.env')) {
                throw new RuntimeException('Configuration exists without a completed bootstrap record. Review it before resuming.');
            }
            // A run without resume does every stage again (the config stage rewrites .env):
            // stages of an earlier run must not count, or a later resume would skip them.
            if (! $resume) {
                $state['completed'] = [];
            }
            $provisions = $this->bootstrapStrategy instanceof ProvisionsDatabases;
            // Without its own databases, the copied .env still names the main database and its account.
            // Without --resume the config stage copies the main .env again, and only provisioning would restore the worktree's values.
            if ($provisions && ! empty($options['skip_db']) && (! $resume || ! in_array('database', $state['completed'], true))) {
                throw new RuntimeException('--skip-db works with shared services only on a resume, once the worktree has its own databases: otherwise its .env names the main database. Run without --skip-db.');
            }
            $run = function (string $id, Closure $action, bool $always = false, ?float $since = null) use (&$state, $statePath, $step, $resume, $onStageDone): void {
                if ($resume && ! $always && in_array($id, $state['completed'], true)) {
                    return;
                }
                $state['stage'] = $id;
                $state['status'] = 'running';
                File::replace($statePath, json_encode($state, JSON_THROW_ON_ERROR));
                $step($id);
                // A stage that ran in parallel counts from its start.
                $started = $since ?? microtime(true);
                $action();
                $onStageDone && $onStageDone($id, microtime(true) - $started);
                $state['completed'] = array_values(array_unique([...$state['completed'], $id]));
                File::replace($statePath, json_encode($state, JSON_THROW_ON_ERROR));
            };
            $run('config', function () use ($path, $name, $options, $setup, $provisions): void {
                if ($setup && $provisions) {
                    SailConverter::rememberOldNames($path);
                }
                $this->copyConfigFiles($path, keepExisting: $setup);
                $this->applyEnvReplacements($path, $name);
                if ($this->bootstrapStrategy instanceof ConfiguresWorktreeEnvironment) {
                    $this->bootstrapStrategy->configureEnvironment($path, ['horizon' => ! empty($options['horizon'])]);
                }
            });
            // Shared services: the databases exist and `.env` names them before the
            // app container starts, so its worker and scheduler start with them.
            if ($this->bootstrapStrategy instanceof ProvisionsDatabases && empty($options['skip_db'])) {
                $run('database', fn () => $this->bootstrapStrategy->provisionDatabases($name, $path, [
                    'from_main' => ! empty($options['from_main']),
                    'fill_app' => $options['fill_app'] ?? null,
                    'branch' => $identity['branch'],
                    'base' => $options['base'] ?? $this->baseBranch,
                ], $processOutput, $step));
            }
            $nodeNeeded = ! is_dir($path.'/node_modules');
            $node = fn () => $run('node', fn () => $this->bootstrapStrategy->installNodeDependencies($path, $processOutput), $nodeNeeded);
            // Container strategies install node on the host before the containers start (see the contract).
            $nodeFirst = $this->bootstrapStrategy instanceof InstallsNodeBeforeBringUp;
            if ($nodeFirst && empty($options['skip_deps'])) {
                $pending = ! $resume || $nodeNeeded || ! in_array('node', $state['completed'], true);
                if ($pending && $this->bootstrapStrategy instanceof SailBootstrapStrategy && $this->bootstrapStrategy->canInstallNodeInParallel($path)) {
                    // No container mounts node_modules: install while the containers start.
                    $step('node (in parallel)');
                    $parallelNode = [$this->bootstrapStrategy, $this->bootstrapStrategy->startNodeInstall($path), microtime(true)];
                } else {
                    $node();
                }
            }
            $finishNode = function () use (&$parallelNode, $run, $path, $processOutput): void {
                if ($parallelNode === null) {
                    return;
                }
                [$strategy, $process, $since] = $parallelNode;
                $parallelNode = null;
                $run('node', fn () => $strategy->finishNodeInstall($process, $path, $processOutput), true, $since);
            };
            $run('environment', fn () => $this->bootstrapStrategy->bringUp($path, $processOutput), true);
            if (empty($options['skip_deps'])) {
                $run('composer', fn () => $this->bootstrapStrategy->installComposerDependencies($path, $processOutput), ! is_file($path.'/vendor/autoload.php'));
                if (! $nodeFirst) {
                    $node();
                }
            }
            if (! $provisions && empty($options['skip_db'])) {
                $run('database', function () use ($path, $name, $processOutput, $step): void {
                    $suffix = $this->sanitizeSuffix($name);
                    // The marker is written only after the target was found absent, right
                    // before the clone: a target with the marker is a clone of ours that
                    // stopped half way; a target without it existed before and is kept.
                    $marker = $path.'/'.self::CLONE_MARKER;
                    if (File::exists($marker)) {
                        $this->databaseCloner->drop($suffix);
                    } elseif ($this->databaseCloner->targetExists($path, $suffix)) {
                        $step('The worktree database exists; kept as it is.');
                        $this->databaseCloner->pointEnvAtWorktreeDatabase($path);

                        return;
                    }
                    File::put($marker, date(DATE_ATOM)."\n");
                    $this->databaseCloner->clone($path, $suffix, $processOutput);
                    File::delete($marker);
                });
            }
            // A ready-made database with test data: restored now, or seeded and saved after the migrations.
            $dumper = $this->bootstrapStrategy instanceof UsesDatabaseDump && $this->bootstrapStrategy->databaseDumpEnabled()
                && empty($options['skip_db']) && empty($options['skip_deps']) && $this->runMigrations
                ? $this->bootstrapStrategy : null;
            if ($dumper !== null) {
                $run('dump', function () use (&$state, $dumper, $path, $processOutput, $step): void {
                    $state['dump'] = $dumper->restoreDatabaseDump($path, $processOutput, $step);
                });
            }
            if (empty($options['skip_build']) && $this->buildFrontend) {
                $run('build', function () use ($path, $processOutput, $step, $finishNode): void {
                    $source = $this->buildCache?->restore($path);
                    if ($source !== null) {
                        $step("Copied the frontend build from the {$source}: its inputs are the same.");

                        return;
                    }
                    $finishNode();
                    $this->bootstrapStrategy->buildFrontend($path, $processOutput);
                    $this->buildCache?->store($path);
                }, ! is_file($path.'/public/build/manifest.json'));
            }
            if (empty($options['skip_deps']) && $this->runMigrations) {
                $run('migrations', fn () => $this->bootstrapStrategy->runMigrations($path, $processOutput), true);
            }
            // Only a database that had no dump is seeded; a restored or existing one is kept.
            if ($dumper !== null && ($state['dump'] ?? null) === UsesDatabaseDump::DUMP_MISSING) {
                // Two stages: a resume after a failed save does not seed the database again.
                $run('seed', fn () => $dumper->seedDatabase($path, $processOutput));
                $run('dump-save', fn () => $dumper->saveDatabaseDump($path, $step));
            }
            $finishNode();
            $state['status'] = 'ready';
            File::replace($statePath, json_encode($state, JSON_THROW_ON_ERROR));
        } catch (\Throwable $error) {
            if (isset($state)) {
                $state['status'] = 'failed';
                File::replace($statePath, json_encode($state, JSON_THROW_ON_ERROR));
            }
            throw $error;
        } finally {
            // A failed setup does not leave the node install running.
            if ($parallelNode !== null && $parallelNode[1]->running()) {
                $parallelNode[1]->signal(15);
            }
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * A decoded bootstrap record that bootstrap() can work with: completed stages as
     * a list of strings, and text (or null for the branch) in the other known keys.
     */
    private function isBootstrapRecord(mixed $record): bool
    {
        if (! is_array($record) || ! is_array($record['completed'] ?? null) || ! array_is_list($record['completed'])) {
            return false;
        }
        foreach ($record['completed'] as $stage) {
            if (! is_string($stage)) {
                return false;
            }
        }
        foreach (['stage', 'status', 'initial_head', 'name'] as $key) {
            if (array_key_exists($key, $record) && ! is_string($record[$key])) {
                return false;
            }
        }

        return ! array_key_exists('branch', $record) || $record['branch'] === null || is_string($record['branch']);
    }

    /** @return array{path: string, branch: string|null, head: string, bootstrap: mixed} */
    public function describe(string $name): array
    {
        return $this->describePath($this->pathFor($name));
    }

    /**
     * The name decides the worktree's database, ports and compose project: two
     * registered worktrees with the same name would share them. Another worktree
     * holds a name by its folder (`<project>-<name>` in the base path) or by its
     * bootstrap record.
     */
    public function assertNameFree(string $name, string $path): void
    {
        $self = realpath($path) ?: $path;
        foreach ($this->registered() as $index => $worktree) {
            $other = is_string($worktree['path'] ?? null) ? $worktree['path'] : '';
            if ($index === 0 || $other === '' || (realpath($other) ?: $other) === $self) {
                continue;
            }
            $record = $other.'/storage/worktree-bootstrap.json';
            $decoded = File::exists($record) ? json_decode(File::get($record), true) : null;
            $names = array_filter([$this->nameFromPath($other), is_array($decoded) && is_string($decoded['name'] ?? null) ? $decoded['name'] : null]);
            foreach ($names as $taken) {
                if (strtolower($this->sanitizeSuffix($taken)) === strtolower($this->sanitizeSuffix($name))) {
                    throw new RuntimeException("The worktree at {$other} already uses the name '{$taken}'. Choose another name.");
                }
            }
        }
    }

    /**
     * True for the repository's main checkout (the first entry of `git worktree list`).
     */
    public function isMainCheckout(string $path): bool
    {
        $registered = $this->registered();
        $mainPath = is_string($registered[0]['path'] ?? null) ? $registered[0]['path'] : '';

        return $mainPath !== '' && (realpath($mainPath) ?: $mainPath) === (realpath($path) ?: $path);
    }

    /**
     * A registered worktree at any path, also outside the base path.
     *
     * @return array{path: string, branch: string|null, head: string, bootstrap: mixed}
     */
    public function describePath(string $path): array
    {
        $worktree = $this->registeredEntry($path);
        $statePath = $path.'/storage/worktree-bootstrap.json';

        return [
            'path' => $path,
            'branch' => is_string($worktree['branch'] ?? null) ? $worktree['branch'] : null,
            'head' => is_string($worktree['head'] ?? null) ? $worktree['head'] : '',
            'bootstrap' => File::exists($statePath)
                ? json_decode(File::get($statePath), true, flags: JSON_THROW_ON_ERROR) : null,
        ];
    }

    /**
     * The `git worktree list` entry of a path, also outside the base path; fails
     * when Git does not know the path as a worktree.
     *
     * @return array<string, mixed>
     */
    private function registeredEntry(string $path): array
    {
        foreach ($this->registered() as $worktree) {
            $registeredPath = is_string($worktree['path'] ?? null) ? $worktree['path'] : '';
            if ((realpath($registeredPath) ?: $registeredPath) === (realpath($path) ?: $path)) {
                return $worktree;
            }
        }

        throw new RuntimeException('Path is not a registered worktree of this repository.');
    }

    /**
     * Tear down per-worktree environment (e.g. `docker compose down -v`).
     * Called from WorktreeDeleteCommand before `git worktree remove`.
     *
     * @param  (Closure(string, string): void)|null  $output
     */
    public function tearDown(string $name, ?Closure $output = null, bool $keepDatabase = false): void
    {
        $path = $this->pathFor($name);

        if (! $this->exists($name)) {
            return;
        }
        // Only a registered worktree's stack is torn down; a look-alike directory may hold another project.
        $this->registeredEntry($path);

        if ($keepDatabase && $this->bootstrapStrategy instanceof SailBootstrapStrategy) {
            $this->bootstrapStrategy->tearDownKeepingVolumes($path, $output);

            return;
        }
        $this->bootstrapStrategy->tearDown($path, $output);
    }

    /**
     * Check for dirty (uncommitted) changes in a worktree.
     */
    public function isDirty(string $name): bool
    {
        $path = $this->pathFor($name);

        $result = Process::path($path)->timeout(10)->run(self::STATUS_COMMAND);

        if (! $result->successful()) {
            throw new RuntimeException('Cannot verify worktree changes.');
        }

        return trim($result->output()) !== '';
    }

    public function nameFromPath(string $path): ?string
    {
        $prefix = basename(base_path()).'-';
        $basename = basename($path);

        // Only checkouts under the configured base path are ours; a sibling
        // repository that happens to share the prefix (CI checkout dirs) is not.
        $parent = realpath(dirname($path)) ?: dirname($path);
        if (! str_starts_with($basename, $prefix) || $parent !== (realpath($this->basePath) ?: $this->basePath)) {
            return null;
        }

        return substr($basename, strlen($prefix));
    }

    private function copyConfigFiles(string $targetPath, bool $keepExisting = false): void
    {
        $sourcePath = base_path();

        foreach ($this->copyFiles as $file) {
            $source = $sourcePath.'/'.$file;
            $target = $targetPath.'/'.$file;

            if (! File::exists($source) || ($keepExisting && File::exists($target))) {
                continue;
            }

            if (File::isDirectory($source)) {
                File::copyDirectory($source, $target);
            } else {
                File::ensureDirectoryExists(dirname($target));
                File::copy($source, $target);
            }
        }
    }

    private function applyEnvReplacements(string $path, string $name): void
    {
        $envPath = $path.'/.env';

        if (! File::exists($envPath)) {
            return;
        }

        $content = File::get($envPath);

        /** @var string $appName */
        $appName = config('app.name', 'Laravel');

        $content = $this->replaceEnvLine($content, 'APP_NAME', 'APP_NAME="'.$appName.' ('.$name.')"');

        // Update APP_URL: woda-starter.laravel.test → woda-starter-foo-bar.laravel.test
        /** @var string $appUrl */
        $appUrl = config('app.url', '');
        if ($appUrl !== '' && preg_match('#^(https?://)([^.]+)(.*)$#', $appUrl, $m)) {
            $worktreeUrl = $m[1].$m[2].'-'.$name.$m[3];
            $content = (string) preg_replace(
                '/^APP_URL=.*/m',
                'APP_URL='.$worktreeUrl,
                $content,
            );
        }

        // Update database name for MySQL/PostgreSQL
        $dbStrategy = $this->databaseCloner->resolveStrategy();
        if ($dbStrategy === 'mysql' || $dbStrategy === 'pgsql') {
            $suffix = $this->sanitizeSuffix($name);
            $sourceDb = $this->databaseCloner->sourceDatabase();
            $content = (string) preg_replace(
                '/^DB_DATABASE=.*/m',
                'DB_DATABASE='.$sourceDb.'_'.$suffix,
                $content,
            );
        }

        // Update ports for Sail isolation
        $portOffset = $this->portOffset($name);

        if (preg_match('/^APP_PORT=/m', $content)) {
            /** @var int $appBase */
            $appBase = config('worktrees.ports.app_base', 8100);
            $content = (string) preg_replace(
                '/^APP_PORT=.*/m',
                'APP_PORT='.($appBase + $portOffset),
                $content,
            );
        }

        if (preg_match('/^VITE_PORT=/m', $content)) {
            /** @var int $viteBase */
            $viteBase = config('worktrees.ports.vite_base', 5200);
            $content = (string) preg_replace(
                '/^VITE_PORT=.*/m',
                'VITE_PORT='.($viteBase + $portOffset),
                $content,
            );
        }

        // User-defined env overrides — last so they win.
        // Each override key is upserted: existing line replaced, otherwise appended.
        // Values can be Closures (called with $name, $path) or strings with
        // {name} / {path} placeholders. Prefer strings — Closures cannot
        // survive `php artisan config:cache` (var_export bombs on Closure).
        foreach ($this->envOverrides as $key => $value) {
            if ($value instanceof Closure) {
                $resolved = $value($name, $path);
            } else {
                $resolved = strtr((string) $value, ['{name}' => $name, '{path}' => $path]);
            }

            $line = $key.'='.$resolved;
            $pattern = '/^'.preg_quote($key, '/').'=.*/m';

            if (preg_match($pattern, $content)) {
                $content = $this->replaceEnvLine($content, $key, $line);
            } else {
                $content = rtrim($content, "\n")."\n".$line."\n";
            }
        }

        File::put($envPath, $content);
    }

    /**
     * Replace a `KEY=...` line with a literal line. A callback keeps `$1` or
     * `\0` in values (passwords, `${VAR}` references) from being read as
     * regex back-references.
     */
    private function replaceEnvLine(string $content, string $key, string $line): string
    {
        return EnvFile::upsertLine($content, $key, $line);
    }

    public function portOffset(string $name): int
    {
        return ((int) sprintf('%u', crc32($name))) % 900;
    }

    private function sanitizeSuffix(string $name): string
    {
        return str_replace('-', '_', $name);
    }

    private function git(string $command): ProcessResult
    {
        return Process::path(base_path())->timeout(30)->run("git {$command}");
    }
}
