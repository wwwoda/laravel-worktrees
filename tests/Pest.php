<?php

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
