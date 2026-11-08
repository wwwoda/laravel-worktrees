<?php

namespace Woda\Worktrees\Commands;

use Illuminate\Console\Command;
use Woda\Worktrees\Contracts\BootstrapStrategy;
use Woda\Worktrees\SharedServicesBootstrapStrategy;
use Woda\Worktrees\WorktreeManager;

class WorktreeWorkerCommand extends Command
{
    protected $signature = 'worktree:worker
        {worktree : Worktree name or folder}
        {mode? : listen or horizon (omit to show the current mode)}';

    protected $description = 'Show or switch the queue worker (queue:listen or Horizon) of a shared-services worktree and restart it';

    public function handle(WorktreeManager $manager, BootstrapStrategy $strategy): int
    {
        if (! $strategy instanceof SharedServicesBootstrapStrategy) {
            $this->components->error('The queue worker switch needs the shared bootstrap strategy.');

            return self::FAILURE;
        }
        /** @var string $target */
        $target = $this->argument('worktree');
        try {
            $path = is_dir($target) ? (string) realpath($target) : $manager->pathFor($target);
            $manager->describePath($path);
            if ($manager->isMainCheckout($path)) {
                throw new \RuntimeException('This is the main checkout; the worker switch changes worktrees only.');
            }
        } catch (\RuntimeException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $mode = $this->argument('mode');
        if (! is_string($mode) || $mode === '') {
            $this->line($strategy->queueWorkerMode($path) ?? 'unknown');

            return self::SUCCESS;
        }

        try {
            $strategy->switchQueueWorker($path, $mode, $this->output->isVerbose()
                ? fn (string $type, string $buffer) => $this->output->write($buffer) : null);
        } catch (\RuntimeException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }
        $this->components->info("Queue worker of {$path} is now '{$mode}'; the app container was restarted.");

        return self::SUCCESS;
    }
}
