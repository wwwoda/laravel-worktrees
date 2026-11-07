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
     */
    public function __construct(
        private WorktreeRegistry $registry,
        private int $databases,
        private array $reserved,
        private int $perWorktree = 2,
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
        $used = $this->reserved;
        foreach ($this->registry->otherStates($worktreePath) as $state) {
            $used = [...$used, ...$state->redis()];
        }

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
        foreach ($this->registry->otherStates($worktreePath) as $state) {
            if (array_intersect($numbers, $state->redis()) !== []) {
                return true;
            }
        }

        return in_array(true, array_map(fn (int $n): bool => in_array($n, $this->reserved, true), $numbers), true);
    }
}
