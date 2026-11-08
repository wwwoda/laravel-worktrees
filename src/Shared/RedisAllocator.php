<?php

namespace Woda\Worktrees\Shared;

use RuntimeException;

/**
 * Finds free Redis database numbers on the shared Redis: numbers that no
 * other worktree's state names and that config reserves for nobody else
 * (the main checkout uses 0 and 1 by default).
 */
final readonly class RedisAllocator
{
    /**
     * @param  list<int>  $reserved
     * @param  array<string, string>  $envMap  `.env` keys that hold a worktree's Redis numbers, with their map values
     */
    public function __construct(
        private WorktreeRegistry $registry,
        private int $databases,
        private array $reserved,
        private int $perWorktree = 2,
        private array $envMap = [],
    ) {}

    /**
     * @return list<int>
     */
    public function reserved(): array
    {
        return $this->reserved;
    }

    /**
     * @return list<int>
     */
    public function allocate(string $worktreePath): array
    {
        $used = [...$this->reserved, ...$this->usedByOthers($worktreePath)];

        $free = [];
        for ($number = 0; $number < $this->databases && count($free) < $this->perWorktree; $number++) {
            if (! in_array($number, $used, true)) {
                $free[] = $number;
            }
        }
        if (count($free) < $this->perWorktree) {
            throw new RuntimeException("No free Redis databases left (of {$this->databases}). Delete worktrees or raise the shared Redis 'databases' setting.");
        }

        return $free;
    }

    /**
     * True when another worktree's state already names one of these numbers.
     *
     * @param  list<int>  $numbers
     */
    public function taken(string $worktreePath, array $numbers): bool
    {
        if (array_intersect($numbers, $this->usedByOthers($worktreePath)) !== []) {
            return true;
        }

        return in_array(true, array_map(fn (int $n): bool => in_array($n, $this->reserved, true), $numbers), true);
    }

    /**
     * Numbers other worktrees use: from their state, and from their `.env` too,
     * so a worktree that lost its state file keeps its numbers.
     *
     * @return list<int>
     */
    private function usedByOthers(string $worktreePath): array
    {
        $used = [];
        foreach ($this->registry->otherWorktrees($worktreePath) as $path => $state) {
            $used = [...$used, ...($state?->redis() ?? [])];
            // The number may sit inside a longer value, such as redis://redis:6379/{redis_default}.
            foreach ($this->envMap as $key => $template) {
                $value = EnvFile::get($path.'/.env', $key);
                foreach ($value === null ? [] : EnvFile::placeholderValues($template, $value) as $name => $number) {
                    if (in_array($name, ['redis_default', 'redis_cache'], true) && ctype_digit($number)) {
                        $used[] = (int) $number;
                    }
                }
            }
        }

        return $used;
    }
}
