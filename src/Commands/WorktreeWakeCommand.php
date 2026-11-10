<?php

namespace Woda\Worktrees\Commands;

use Illuminate\Console\Command;
use Woda\Worktrees\Contracts\BootstrapStrategy;
use Woda\Worktrees\Idle\IdleStacks;
use Woda\Worktrees\SailBootstrapStrategy;
use Woda\Worktrees\WorktreeManager;

class WorktreeWakeCommand extends Command
{
    protected $signature = 'worktree:wake
        {worktree? : Worktree name or folder (default: this checkout)}';

    protected $description = 'Start the stack of a worktree that worktree:idle-stop (or Sablier) stopped, and wait until it is healthy';

    public function handle(IdleStacks $stacks, BootstrapStrategy $strategy, WorktreeManager $manager): int
    {
        if (! $strategy instanceof SailBootstrapStrategy) {
            $this->components->error('Waking a stack needs the sail or shared strategy.');

            return self::FAILURE;
        }
        $target = $this->argument('worktree');
        try {
            $path = ! is_string($target) || $target === ''
                ? base_path()
                : (str_contains($target, '/') || $target === '.' ? (string) realpath($target) : $manager->pathFor($target));
            // Only a checkout that git registers for this repository.
            $manager->describePath($path);
        } catch (\RuntimeException|\JsonException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }
        $result = $stacks->wake($path, $this->output->isVerbose() ? fn (string $type, string $buffer) => $this->output->write($buffer) : null);
        if (! $result->successful()) {
            $this->components->error('docker compose up -d --wait failed: '.trim($result->errorOutput()));

            return self::FAILURE;
        }
        $this->components->info("The stack of {$path} runs.");

        return self::SUCCESS;
    }
}
