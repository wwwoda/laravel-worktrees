<?php

namespace Woda\Worktrees\Shared;

use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Every git worktree of the project, wherever its folder is (frontier puts
 * its worktrees outside the base path), with the state file of each.
 */
class WorktreeRegistry
{
    /**
     * @return list<string>
     */
    public function paths(): array
    {
        $result = Process::path(base_path())->timeout(30)->run('git worktree list --porcelain');
        if (! $result->successful()) {
            throw new RuntimeException("Cannot list worktrees: {$result->errorOutput()}");
        }
        $paths = [];
        foreach (explode("\n", $result->output()) as $line) {
            if (str_starts_with($line, 'worktree ')) {
                $paths[] = substr(trim($line), 9);
            }
        }

        return $paths;
    }

    /**
     * States of all worktrees except `$exceptPath`.
     *
     * @return array<string, WorktreeState>
     */
    public function otherStates(string $exceptPath): array
    {
        $except = realpath($exceptPath) ?: $exceptPath;
        $states = [];
        foreach ($this->paths() as $path) {
            if ((realpath($path) ?: $path) === $except) {
                continue;
            }
            $state = WorktreeState::load($path);
            if ($state !== null) {
                $states[$path] = $state;
            }
        }

        return $states;
    }
}
