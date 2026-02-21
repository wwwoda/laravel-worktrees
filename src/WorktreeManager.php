<?php

namespace Woda\Worktrees;

use Closure;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

class WorktreeManager
{
    public function __construct(
        private readonly string $basePath,
        private readonly string $branchPrefix,
        private readonly string $baseBranch,
        /** @var list<string> */
        private readonly array $copyFiles,
        private readonly DatabaseCloner $databaseCloner,
        private readonly string $nodePackageManager,
        private readonly bool $buildFrontend,
        private readonly bool $runMigrations,
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

        // Create the worktree with a new branch
        $result = $this->git(sprintf(
            'worktree add -b %s %s %s',
            escapeshellarg($branch),
            escapeshellarg($path),
            escapeshellarg($baseBranch),
        ));

        if (! $result->successful()) {
            // Branch may already exist, try without -b
            $result = $this->git(sprintf(
                'worktree add %s %s',
                escapeshellarg($path),
                escapeshellarg($branch),
            ));

            if (! $result->successful()) {
                $error = $result->errorOutput();

                if (str_contains($error, 'is already used by worktree') || str_contains($error, 'is already checked out')) {
                    throw new RuntimeException("Branch '{$branch}' is already checked out in another worktree.");
                }

                throw new RuntimeException("Failed to create worktree: {$error}");
            }
        }

        return $path;
    }

    public function remove(string $name, bool $force = false): void
    {
        $path = $this->pathFor($name);

        if (! $this->exists($name)) {
            throw new RuntimeException("Worktree '{$name}' does not exist.");
        }

        if ($force) {
            File::deleteDirectory($path);
            $this->git('worktree prune');
        } else {
            // --force is needed because copied gitignored files (e.g. .env, .claude)
            // leave untracked files that prevent clean removal.
            $result = $this->git(sprintf('worktree remove --force %s', escapeshellarg($path)));

            if (! $result->successful()) {
                throw new RuntimeException("Failed to remove worktree: {$result->errorOutput()}");
            }
        }
    }

    public function exists(string $name): bool
    {
        $path = $this->pathFor($name);

        return is_dir($path);
    }

    public function pathFor(string $name): string
    {
        $projectName = basename(base_path());

        return $this->basePath.'/'.$projectName.'-'.$name;
    }

    /**
     * @return array{clean: bool, unpushed: bool}
     */
    public function safetyCheck(string $name): array
    {
        $path = $this->pathFor($name);

        // Check for uncommitted changes
        $statusResult = Process::path($path)->timeout(10)->run('git status --porcelain');
        $clean = trim($statusResult->output()) === '';

        // Check for unpushed commits
        $branchResult = Process::path($path)->timeout(10)->run('git rev-parse --abbrev-ref HEAD');
        $branch = trim($branchResult->output());

        $logResult = Process::path($path)->timeout(10)->run(
            sprintf('git log %s --not --remotes --oneline', escapeshellarg($branch)),
        );
        $unpushed = trim($logResult->output()) !== '';

        return [
            'clean' => $clean,
            'unpushed' => $unpushed,
        ];
    }

    /**
     * @param  array{skip_deps?: bool, skip_build?: bool, skip_db?: bool}  $options
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

        $step('Copying config files...');
        $this->copyConfigFiles($path);
        $this->applyEnvReplacements($path, $name);

        if (empty($options['skip_deps'])) {
            $step('Installing Composer dependencies...');
            $this->installDependencies($path, $processOutput);
        }

        if (empty($options['skip_db'])) {
            $step('Cloning database...');
            $this->databaseCloner->clone($path, $this->sanitizeSuffix($name), $processOutput);
        }

        if (empty($options['skip_build']) && $this->buildFrontend) {
            $step('Building frontend assets...');
            $this->buildFrontendAssets($path, $processOutput);
        }

        if (empty($options['skip_deps']) && $this->runMigrations) {
            $step('Running migrations...');
            $this->runDatabaseMigrations($path, $processOutput);
        }
    }

    /**
     * Check for dirty (uncommitted) changes in a worktree.
     */
    public function isDirty(string $name): bool
    {
        $path = $this->pathFor($name);

        $result = Process::path($path)->timeout(10)->run('git status --porcelain');

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

        File::put($envPath, $content);
    }

    /**
     * @param  (Closure(string, string): void)|null  $output
     */
    private function installDependencies(string $path, ?Closure $output = null): void
    {
        $result = Process::path($path)->timeout(300)->run('composer install --no-interaction', $output);

        if (! $result->successful()) {
            throw new RuntimeException("Composer install failed: {$result->errorOutput()}");
        }

        $result = Process::path($path)->timeout(300)->run("{$this->nodePackageManager} install", $output);

        if (! $result->successful()) {
            throw new RuntimeException("Node dependency install failed: {$result->errorOutput()}");
        }
    }

    /**
     * @param  (Closure(string, string): void)|null  $output
     */
    private function buildFrontendAssets(string $path, ?Closure $output = null): void
    {
        $result = Process::path($path)->timeout(300)->run("{$this->nodePackageManager} run build", $output);

        if (! $result->successful()) {
            throw new RuntimeException("Frontend build failed: {$result->errorOutput()}");
        }
    }

    /**
     * @param  (Closure(string, string): void)|null  $output
     */
    private function runDatabaseMigrations(string $path, ?Closure $output = null): void
    {
        $result = Process::path($path)->timeout(120)->run('php artisan migrate --force', $output);

        if (! $result->successful()) {
            throw new RuntimeException("Migration failed: {$result->errorOutput()}");
        }
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
