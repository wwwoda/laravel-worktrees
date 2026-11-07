<?php

namespace Woda\Worktrees\Shared;

final class ServerResult
{
    public function __construct(
        public readonly bool $successful,
        public readonly string $output,
        public readonly string $error,
    ) {}
}
