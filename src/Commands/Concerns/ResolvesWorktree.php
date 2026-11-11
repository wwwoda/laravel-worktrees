<?php

namespace Woda\Worktrees\Commands\Concerns;

use RuntimeException;
use Woda\Worktrees\Agent\WorktreeTarget;
use Woda\Worktrees\Shared\MainCheckout;
use Woda\Worktrees\WorktreeManager;

/**
 * The checkout an agent command acts on: a worktree name, a folder, `main`,
 * or (without one) the checkout that runs artisan.
 *
 * A word without a slash is a worktree name first; when no worktree has that
 * name, an existing folder of that name (relative to the current folder)
 * counts. `main` is the main checkout; when a worktree is also named `main`,
 * the word is refused as ambiguous and the caller must give a path.
 */
trait ResolvesWorktree
{
    protected function resolveWorktree(WorktreeManager $manager, ?string $target): WorktreeTarget
    {
        $path = match (true) {
            $target === null || $target === '' => base_path(),
            $target === 'main' => $this->mainPath($manager),
            str_contains($target, '/') || $target === '.' || $target === '..' => $target,
            default => $this->pathForName($manager, $target) ?? (is_dir($target)
                ? $target
                : throw new RuntimeException("No worktree named '{$target}'. See php artisan worktree:list.")),
        };
        $path = rtrim(realpath($path) ?: $path, '/');
        // Only a checkout that git registers for this repository.
        $identity = $manager->describePath($path);
        $main = $manager->isMainCheckout($path);
        $record = $identity['bootstrap'];
        $name = is_array($record) && is_string($record['name'] ?? null) && $record['name'] !== ''
            ? $record['name']
            : ($main ? 'main' : ($this->folderName($path) ?? basename($path)));

        return new WorktreeTarget($path, $name, $main, $identity['branch'], $identity['head'], $record);
    }

    /**
     * The folder of a worktree by its name: the name in its bootstrap record,
     * or its folder `{project}-{name}`, where `{project}` is the main
     * checkout's folder (also when artisan runs in a worktree). Null when no
     * worktree has the name.
     */
    /**
     * The main checkout, unless a worktree is also named `main`: then the word
     * could mean either, and a fix could change the wrong database.
     */
    private function mainPath(WorktreeManager $manager): string
    {
        $worktree = $this->pathForName($manager, 'main');
        if ($worktree !== null) {
            throw new RuntimeException("'main' is ambiguous: a worktree is also named main ({$worktree}). Give the path of the checkout: ".MainCheckout::path()." for the main checkout, {$worktree} for the worktree.");
        }

        return MainCheckout::path();
    }

    private function pathForName(WorktreeManager $manager, string $name): ?string
    {
        foreach ($manager->registered() as $index => $worktree) {
            $path = is_string($worktree['path'] ?? null) ? $worktree['path'] : '';
            if ($index === 0 || $path === '') {
                continue;
            }
            $record = $path.'/storage/worktree-bootstrap.json';
            $decoded = is_file($record) ? json_decode((string) file_get_contents($record), true) : null;
            if ((is_array($decoded) && ($decoded['name'] ?? null) === $name) || $this->folderName($path) === $name) {
                return $path;
            }
        }

        return null;
    }

    private function folderName(string $path): ?string
    {
        $prefix = basename(MainCheckout::path()).'-';
        $folder = basename($path);

        return str_starts_with($folder, $prefix) && strlen($folder) > strlen($prefix) ? substr($folder, strlen($prefix)) : null;
    }
}
