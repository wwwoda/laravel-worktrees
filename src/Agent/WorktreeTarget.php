<?php

namespace Woda\Worktrees\Agent;

/**
 * A checkout that an agent command acts on: a linked worktree or the main checkout.
 */
final readonly class WorktreeTarget
{
    public function __construct(
        public string $path,
        public string $name,
        public bool $main,
        public ?string $branch,
        public string $head,
        public mixed $bootstrap,
    ) {}

    /**
     * The status of the bootstrap record (`ready`, `failed`, `running`), or null without one.
     */
    public function setupStatus(): ?string
    {
        return is_array($this->bootstrap) && is_string($this->bootstrap['status'] ?? null) ? $this->bootstrap['status'] : null;
    }
}
