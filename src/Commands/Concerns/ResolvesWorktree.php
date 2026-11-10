<?php

namespace Woda\Worktrees\Commands\Concerns;

use RuntimeException;
use Woda\Worktrees\Agent\WorktreeTarget;
use Woda\Worktrees\Shared\MainCheckout;
use Woda\Worktrees\WorktreeManager;

/**
 * The checkout an agent command acts on: a worktree name, a folder, `main`,
 * or (without one) the checkout that runs artisan.
 */
trait ResolvesWorktree
{
    protected function resolveWorktree(WorktreeManager $manager, ?string $target): WorktreeTarget
    {
        $path = match (true) {
            $target === null || $target === '' => base_path(),
            $target === 'main' => MainCheckout::path(),
            str_contains($target, '/') || $target === '.' || $target === '..' => $target,
            default => $this->pathForName($manager, $target),
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
     * checkout's folder (also when artisan runs in a worktree).
     */
    private function pathForName(WorktreeManager $manager, string $name): string
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

        throw new RuntimeException("No worktree named '{$name}'. See php artisan worktree:list.");
    }

    private function folderName(string $path): ?string
    {
        $prefix = basename(MainCheckout::path()).'-';
        $folder = basename($path);

        return str_starts_with($folder, $prefix) && strlen($folder) > strlen($prefix) ? substr($folder, strlen($prefix)) : null;
    }
}
