<?php

namespace Woda\Worktrees\Fast;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Woda\Worktrees\Shared\MainCheckout;

/**
 * Reuses a frontend build when its inputs are the same.
 *
 * The key is the git tree of the build inputs (`paths`: resources, lock
 * files, the Vite config) at HEAD, and the build command. A checkout with
 * uncommitted changes in those paths has no key and always builds.
 *
 * Sources, in order: the cache in the main checkout's
 * `storage/app/worktrees/builds/{key}` (filled after each build of the
 * package), then the main checkout's own build when the main checkout has
 * the same key, no changes in those paths, and a manifest newer than its
 * last checkout or commit.
 */
final readonly class BuildCache
{
    /**
     * @param  list<string>  $paths
     */
    public function __construct(
        private bool $enabled,
        private array $paths,
        private string $buildCommand,
        private string $output = 'public/build',
        private int $keep = 5,
        // Default: the main checkout of the repository that runs artisan.
        private ?string $mainCheckout = null,
    ) {}

    private function main(): string
    {
        return $this->mainCheckout ?? MainCheckout::path();
    }

    public function enabled(): bool
    {
        return $this->enabled && $this->paths !== [];
    }

    /**
     * The key of the checkout's build inputs; null with uncommitted changes there.
     */
    public function key(string $checkout): ?string
    {
        $pathspec = implode(' ', array_map(escapeshellarg(...), $this->paths));
        $status = Process::path($checkout)->timeout(30)->run('git status --porcelain -- '.$pathspec);
        if (! $status->successful() || trim($status->output()) !== '') {
            return null;
        }
        $tree = Process::path($checkout)->timeout(30)->run('git ls-tree -r HEAD -- '.$pathspec);
        if (! $tree->successful() || trim($tree->output()) === '') {
            return null;
        }

        return substr(hash('sha256', "build:{$this->buildCommand}\n".$tree->output()), 0, 16);
    }

    /**
     * Copy a matching build into the worktree. Returns where it came from
     * (`cache` or `main checkout`), or null when nothing matches.
     */
    public function restore(string $worktreePath): ?string
    {
        if (! $this->enabled()) {
            return null;
        }
        $key = $this->key($worktreePath);
        if ($key === null) {
            return null;
        }
        $cached = $this->cachePath($key);
        if (is_file($cached.'/manifest.json') && $this->copy($cached, $worktreePath.'/'.$this->output)) {
            touch($cached);

            return 'cache';
        }
        $main = $this->main();
        if ($this->mainBuildMatches($main, $key) && $this->copy($main.'/'.$this->output, $worktreePath.'/'.$this->output)) {
            $this->store($worktreePath, $key);

            return 'main checkout';
        }

        return null;
    }

    /**
     * Keep the worktree's fresh build for the next worktree with the same inputs.
     */
    public function store(string $worktreePath, ?string $key = null): void
    {
        if (! $this->enabled()) {
            return;
        }
        $key ??= $this->key($worktreePath);
        $source = $worktreePath.'/'.$this->output;
        if ($key === null || ! is_file($source.'/manifest.json')) {
            return;
        }
        $target = $this->cachePath($key);
        if (is_dir($target)) {
            return;
        }
        $temporary = $target.'.tmp-'.bin2hex(random_bytes(4));
        if ($this->copy($source, $temporary)) {
            // Another setup may have stored the same key meanwhile; both copies are equal.
            if (! @rename($temporary, $target)) {
                File::deleteDirectory($temporary);
            }
        }
        $this->prune();
    }

    private function mainBuildMatches(string $main, string $key): bool
    {
        $manifest = $main.'/'.$this->output.'/manifest.json';
        if (! is_file($manifest) || $this->key($main) !== $key) {
            return false;
        }
        // A build older than the last move of HEAD may be of other files.
        $reflog = Process::path($main)->timeout(10)->run('git rev-parse --path-format=absolute --git-path logs/HEAD');
        $file = trim($reflog->output());
        if (! $reflog->successful() || $file === '' || ! is_file($file)) {
            return false;
        }

        return filemtime($manifest) >= filemtime($file);
    }

    private function copy(string $from, string $to): bool
    {
        if (! is_dir($from)) {
            return false;
        }
        File::deleteDirectory($to);
        File::ensureDirectoryExists(dirname($to));
        // Copy-on-write where the file system has it (APFS, Btrfs, XFS); a plain copy elsewhere.
        $command = PHP_OS_FAMILY === 'Darwin'
            ? ['cp', '-c', '-R', $from, $to]
            : ['cp', '-R', '--reflink=auto', $from, $to];
        if (Process::timeout(120)->run($command)->successful()) {
            return true;
        }
        File::deleteDirectory($to);

        return File::copyDirectory($from, $to);
    }

    private function cachePath(string $key): string
    {
        return $this->main().'/storage/app/worktrees/builds/'.$key;
    }

    private function prune(): void
    {
        $directories = glob($this->main().'/storage/app/worktrees/builds/*', GLOB_ONLYDIR) ?: [];
        $directories = array_values(array_filter($directories, static fn (string $d): bool => ! str_contains(basename($d), '.tmp-')));
        if (count($directories) <= $this->keep) {
            return;
        }
        usort($directories, static fn (string $a, string $b): int => (int) filemtime($b) <=> (int) filemtime($a));
        foreach (array_slice($directories, $this->keep) as $old) {
            File::deleteDirectory($old);
        }
    }
}
