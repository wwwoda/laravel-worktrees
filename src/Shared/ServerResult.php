<?php

namespace Woda\Worktrees\Shared;

final readonly class ServerResult
{
    public function __construct(
        public bool $successful,
        public string $output,
        public string $error,
    ) {}
}
