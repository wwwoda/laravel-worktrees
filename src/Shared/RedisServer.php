<?php

namespace Woda\Worktrees\Shared;

use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * The shared Redis, through `redis-cli` inside its container or against host
 * and port. Used to empty a deleted worktree's own database numbers.
 */
class RedisServer
{
    public function __construct(
        private readonly ?string $container,
        private readonly string $host,
        private readonly int $port,
    ) {}

    public function flush(int $database): void
    {
        $command = $this->container !== null
            ? ['docker', 'exec', $this->container, 'redis-cli', '-n', (string) $database, 'FLUSHDB']
            : ['redis-cli', '-h', $this->host, '-p', (string) $this->port, '-n', (string) $database, 'FLUSHDB'];
        $result = Process::timeout(30)->run($command);
        if (! $result->successful() || ! str_contains($result->output(), 'OK')) {
            throw new RuntimeException("Emptying Redis database {$database} failed: {$result->errorOutput()}{$result->output()}");
        }
    }
}
