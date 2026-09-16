<?php

namespace Woda\Worktrees;

use Closure;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Woda\Worktrees\Contracts\BootstrapStrategy;

class WorktreeManager
{
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
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function list(): array
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

        // Filter out the main worktree and add derived name
        $mainPath = base_path();

        return array_values(array_filter(array_map(
            function (array $wt): ?array {
                $name = $this->nameFromPath($wt['path'] ?? '');
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
        if (! File::deleteDirectory($path)) {
            throw new RuntimeException('Failed to remove worktree directory.');
        }
        $this->git('worktree prune');
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
        $statusResult = Process::path($path)->timeout(10)->run('git status --porcelain');
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
     * @param  array{skip_deps?: bool, skip_build?: bool, skip_db?: bool, resume?: bool}  $options
     * @param  (Closure(string): void)|null  $onStep
     * @param  (Closure(string, string): void)|null  $processOutput
     */
    public function bootstrap(
        string $name,
        array $options = [],
        ?Closure $onStep = null,
        ?Closure $processOutput = null,
    ): void {
        $step = $onStep ?? static fn () => null;
        $path = $this->pathFor($name);

        $identity = $this->describe($name);
        File::ensureDirectoryExists($path.'/storage');
        $handle = fopen($path.'/storage/worktree-bootstrap.lock', 'c');
        if ($handle === false || ! flock($handle, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('Worktree bootstrap is already running.');
        }

        try {
            $statePath = $path.'/storage/worktree-bootstrap.json';
            /** @var array{completed: list<string>, stage?: string, status?: string, branch?: string|null, initial_head?: string} $state */
            $state = File::exists($statePath)
                ? json_decode(File::get($statePath), true, flags: JSON_THROW_ON_ERROR)
                : ['completed' => []];
            if (array_key_exists('branch', $state) && $state['branch'] !== $identity['branch']) {
                throw new RuntimeException('Worktree branch changed since bootstrap.');
            }
            $state['branch'] = $identity['branch'];
            $state['initial_head'] ??= $identity['head'];
            $resume = ! empty($options['resume']);
            if ($resume && ! in_array('config', $state['completed'], true) && File::exists($path.'/.env')) {
                throw new RuntimeException('Configuration exists without a completed bootstrap record. Review it before resuming.');
            }
            $run = function (string $id, Closure $action, bool $always = false) use (&$state, $statePath, $step, $resume): void {
                if ($resume && ! $always && in_array($id, $state['completed'], true)) {
                    return;
                }
                $state['stage'] = $id;
                $state['status'] = 'running';
                File::replace($statePath, json_encode($state, JSON_THROW_ON_ERROR));
                $step($id);
                $action();
                $state['completed'] = array_values(array_unique([...$state['completed'], $id]));
                File::replace($statePath, json_encode($state, JSON_THROW_ON_ERROR));
            };
            $run('config', function () use ($path, $name): void {
                $this->copyConfigFiles($path);
                $this->applyEnvReplacements($path, $name);
            });
            $run('environment', fn () => $this->bootstrapStrategy->bringUp($path, $processOutput), true);
            if (empty($options['skip_deps'])) {
                $run('composer', fn () => $this->bootstrapStrategy->installComposerDependencies($path, $processOutput), ! is_file($path.'/vendor/autoload.php'));
                $run('node', fn () => $this->bootstrapStrategy->installNodeDependencies($path, $processOutput), ! is_dir($path.'/node_modules'));
            }
            if (empty($options['skip_db'])) {
                $run('database', fn () => $this->databaseCloner->clone($path, $this->sanitizeSuffix($name), $processOutput));
            }
            if (empty($options['skip_build']) && $this->buildFrontend) {
                $run('build', fn () => $this->bootstrapStrategy->buildFrontend($path, $processOutput), ! is_file($path.'/public/build/manifest.json'));
            }
            if (empty($options['skip_deps']) && $this->runMigrations) {
                $run('migrations', fn () => $this->bootstrapStrategy->runMigrations($path, $processOutput), true);
            }
            $state['status'] = 'ready';
            File::replace($statePath, json_encode($state, JSON_THROW_ON_ERROR));
        } catch (\Throwable $error) {
            if (isset($state)) {
                $state['status'] = 'failed';
                File::replace($statePath, json_encode($state, JSON_THROW_ON_ERROR));
            }
            throw $error;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** @return array{path: string, branch: string|null, head: string, bootstrap: mixed} */
    public function describe(string $name): array
    {
        $path = $this->pathFor($name);
        foreach ($this->list() as $worktree) {
            $registeredPath = is_string($worktree['path'] ?? null) ? $worktree['path'] : '';
            if ((realpath($registeredPath) ?: $registeredPath) === (realpath($path) ?: $path)) {
                $statePath = $path.'/storage/worktree-bootstrap.json';

                return [
                    'path' => $path,
                    'branch' => is_string($worktree['branch'] ?? null) ? $worktree['branch'] : null,
                    'head' => is_string($worktree['head'] ?? null) ? $worktree['head'] : '',
                    'bootstrap' => File::exists($statePath)
                        ? json_decode(File::get($statePath), true, flags: JSON_THROW_ON_ERROR) : null,
                ];
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

        $result = Process::path($path)->timeout(10)->run('git status --porcelain');

        if (! $result->successful()) {
            throw new RuntimeException('Cannot verify worktree changes.');
        }

        return trim($result->output()) !== '';
    }

    public function nameFromPath(string $path): ?string
    {
        $prefix = basename(base_path()).'-';
        $basename = basename($path);

        if (! str_starts_with($basename, $prefix)) {
            return null;
        }

        return substr($basename, strlen($prefix));
    }

    private function copyConfigFiles(string $targetPath): void
    {
        $sourcePath = base_path();

        foreach ($this->copyFiles as $file) {
            $source = $sourcePath.'/'.$file;
            $target = $targetPath.'/'.$file;

            if (! File::exists($source)) {
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

        $content = (string) preg_replace(
            '/^APP_NAME=.*/m',
            'APP_NAME="'.$appName.' ('.$name.')"',
            $content,
        );

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
                $content = (string) preg_replace($pattern, $line, $content);
            } else {
                $content = rtrim($content, "\n")."\n".$line."\n";
            }
        }

        File::put($envPath, $content);
    }

    public function portOffset(string $name): int
    {
        return ((int) sprintf('%u', crc32($name))) % 900;
    }

    private function sanitizeSuffix(string $name): string
    {
        return str_replace('-', '_', $name);
    }

    private function git(string $command): \Illuminate\Contracts\Process\ProcessResult
    {
        return Process::path(base_path())->timeout(30)->run("git {$command}");
    }
}
