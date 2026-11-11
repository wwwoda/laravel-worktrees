<?php

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Woda\Worktrees\Commands\WorktreeDeleteCommand;
use Woda\Worktrees\Tests\TestCase;

uses(TestCase::class)->in('Feature', 'Integration');

/**
 * worktree:delete whose stdin is (or is not) a terminal, whatever the stdin
 * of the test run is.
 */
function deleteCommandWithStdin(bool $terminal): void
{
    app()->bind(WorktreeDeleteCommand::class, fn () => new class($terminal) extends WorktreeDeleteCommand
    {
        public function __construct(private readonly bool $terminal)
        {
            parent::__construct();
        }

        protected function stdinIsTerminal(): bool
        {
            return $this->terminal;
        }
    });
}

/**
 * A fake `docker compose config` that gives each checkout its own compose
 * project: the start and stop guards refuse a worktree whose project is unknown.
 *
 * @return Closure(PendingProcess): ProcessResult
 */
function composeConfigPerCheckout(): Closure
{
    return fn (PendingProcess $p) => Process::result((string) json_encode(['name' => 'project-'.md5((string) $p->path), 'services' => []]));
}
