<?php

namespace Woda\Worktrees\Shared;

use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * The shared Redis, through `redis-cli` inside its container or against host
 * and port. Used to empty a deleted worktree's own database numbers. A
 * password travels in REDISCLI_AUTH, never on the command line.
 */
class RedisServer
{
    public function __construct(
        private readonly ?string $container,
        private readonly string $host,
        private readonly int $port,
        private readonly ?string $password = null,
    ) {}

    public function ping(): void
    {
        $result = $this->run(['PING']);
        if (! $result['ok'] || ! str_contains($result['output'], 'PONG')) {
            throw new RuntimeException("The shared Redis does not answer: {$result['output']}");
        }
    }

    public function flush(int $database): void
    {
        $result = $this->run(['-n', (string) $database, 'FLUSHDB']);
        if (! $result['ok'] || ! str_contains($result['output'], 'OK')) {
            throw new RuntimeException("Emptying Redis database {$database} failed: {$result['output']}");
        }
    }

    /**
     * @param  list<string>  $arguments
     * @return array{ok: bool, output: string}
     */
    private function run(array $arguments): array
    {
        $auth = $this->password !== null && $this->password !== '';
        $command = $this->container !== null
            ? ['docker', 'exec', ...($auth ? ['-e', 'REDISCLI_AUTH'] : []), $this->container, 'redis-cli', ...$arguments]
            : ['redis-cli', '-h', $this->host, '-p', (string) $this->port, ...$arguments];
        $result = Process::timeout(30)->env($auth ? ['REDISCLI_AUTH' => (string) $this->password] : [])->run($command);

        return ['ok' => $result->successful(), 'output' => trim($result->output().' '.$result->errorOutput())];
    }
}
