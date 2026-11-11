<?php

namespace Woda\Worktrees\Agent;

use Closure;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\PendingProcess;
use Woda\Worktrees\ComposeProcess;
use Woda\Worktrees\Contracts\BootstrapStrategy;
use Woda\Worktrees\SailBootstrapStrategy;

/**
 * Runs a shell command where the worktree's PHP runs: in its app container
 * (`sail` and `shared`) or on the host (`native`).
 *
 * Both ways clear the variables that the checkout running artisan put into
 * this process (see ComposeProcess), so the command reads the worktree's own
 * `.env`.
 */
final readonly class AppRunner
{
    public function __construct(
        private BootstrapStrategy $strategy,
        private string $appService,
    ) {}

    public function usesCompose(): bool
    {
        return $this->strategy instanceof SailBootstrapStrategy;
    }

    public function appService(): string
    {
        return $this->appService;
    }

    /**
     * The command line that runs the shell command `$command` in the app
     * service (or in `$service`), or on the host for `native`.
     */
    public function commandLine(string $command, ?string $service = null, bool $tty = false): string
    {
        if (! $this->usesCompose()) {
            return $command;
        }

        return sprintf(
            'docker compose exec%s %s sh -c %s',
            $tty ? '' : ' -T',
            escapeshellarg($service ?? $this->appService),
            escapeshellarg($command),
        );
    }

    public function process(string $path, int $timeout): PendingProcess
    {
        return ComposeProcess::in($path, $timeout);
    }

    /**
     * @param  (Closure(string, string): void)|null  $output
     */
    public function run(string $path, string $command, int $timeout = 120, ?Closure $output = null): ProcessResult
    {
        return $this->process($path, $timeout)->run($this->commandLine($command), $output);
    }
}
