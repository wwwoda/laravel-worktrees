<?php

namespace Woda\Worktrees\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Woda\Worktrees\WorktreeManager;

use function Laravel\Prompts\select;

class WorktreeOpenCommand extends Command
{
    protected $signature = 'worktree:open {name? : Worktree name}';

    protected $description = 'Open a worktree in IDE';

    public function handle(WorktreeManager $worktreeManager): int
    {
        $nameArg = $this->argument('name');
        $name = is_string($nameArg) ? $nameArg : null;

        if (! $name) {
            $worktrees = $worktreeManager->list();

            if ($worktrees === []) {
                $this->components->error('No agent worktrees found.');

                return self::FAILURE;
            }

            $options = [];
            foreach ($worktrees as $wt) {
                /** @var string $wtName */
                $wtName = $wt['name'];
                $options[] = $wtName;
            }

            $name = (string) select(
                label: 'Select worktree to open',
                options: $options,
            );
        }

        if (! $worktreeManager->exists($name)) {
            $this->components->error("Worktree '{$name}' does not exist.");

            return self::FAILURE;
        }

        $path = $worktreeManager->pathFor($name);
        /** @var string $ideCommand */
        $ideCommand = config('worktrees.ide.command');

        $this->components->info("Opening '{$path}' in {$ideCommand}...");

        Process::run(sprintf('%s %s', escapeshellarg($ideCommand), escapeshellarg($path)));

        return self::SUCCESS;
    }
}
