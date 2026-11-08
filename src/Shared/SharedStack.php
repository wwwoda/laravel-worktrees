<?php

namespace Woda\Worktrees\Shared;

use Closure;
use RuntimeException;
use Woda\Worktrees\ComposeProcess;

/**
 * The shared stack of the machine (database server, Redis, tools). Worktree
 * commands start it when the database server does not answer, and never
 * stop, reset or upgrade it.
 */
class SharedStack
{
    public function __construct(
        private readonly DatabaseServer $server,
        private readonly ?string $startCommand,
        private readonly int $waitSeconds = 120,
    ) {}

    /**
     * @param  (Closure(string): void)|null  $onStep
     * @param  (Closure(string, string): void)|null  $output
     */
    public function ensureRunning(?Closure $onStep = null, ?Closure $output = null): void
    {
        if ($this->server->ping()) {
            return;
        }
        if ($this->startCommand === null || trim($this->startCommand) === '') {
            throw new RuntimeException('The shared database server does not answer, and no start command is configured (worktrees.shared.stack.start_command). Start the shared stack and run the setup again.');
        }
        $this->runStartCommand($onStep, $output);
        $deadline = time() + $this->waitSeconds;
        while (! $this->server->ping()) {
            if (time() >= $deadline) {
                throw new RuntimeException("The shared database server does not answer {$this->waitSeconds} seconds after the start.");
            }
            usleep(1_000_000);
        }
    }

    /**
     * Run the start command also when the database answers: it brings back other
     * shared containers (Redis) that were removed. Without a start command, nothing.
     *
     * @param  (Closure(string): void)|null  $onStep
     * @param  (Closure(string, string): void)|null  $output
     */
    public function restart(?Closure $onStep = null, ?Closure $output = null): void
    {
        if ($this->startCommand !== null && trim($this->startCommand) !== '') {
            $this->runStartCommand($onStep, $output);
        }
        $this->ensureRunning($onStep, $output);
    }

    /**
     * @param  (Closure(string): void)|null  $onStep
     * @param  (Closure(string, string): void)|null  $output
     */
    private function runStartCommand(?Closure $onStep, ?Closure $output): void
    {
        $onStep && $onStep('Starting the shared stack...');
        // Without the variables this process may carry from a linked worktree's .env
        // (COMPOSE_PROJECT_NAME, DB_*): the command must see the main checkout's own.
        $result = ComposeProcess::in(MainCheckout::path(), 600)->run((string) $this->startCommand, $output);
        if (! $result->successful()) {
            throw new RuntimeException("Starting the shared stack failed: {$result->errorOutput()}");
        }
    }
}
