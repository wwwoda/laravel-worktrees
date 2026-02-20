<?php

namespace Woda\Worktrees\Commands;

use Illuminate\Console\Command;
use Woda\Worktrees\Contracts\ProcessManager;
use Woda\Worktrees\DatabaseCloner;
use Woda\Worktrees\WorktreeManager;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;

class WorktreeDeleteCommand extends Command
{
    protected $signature = 'worktree:delete
        {name? : Worktree name}
        {--force : Skip safety checks and confirmation}
        {--keep-db : Keep the cloned database}';

    protected $description = 'Delete a git worktree';

    public function handle(
        WorktreeManager $worktreeManager,
        ProcessManager $processManager,
        DatabaseCloner $databaseCloner,
    ): int {
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
                /** @var string $wtBranch */
                $wtBranch = $wt['branch'] ?? 'detached';
                $options[$wtName] = $wtName.' ('.$wtBranch.')';
            }

            $name = (string) select(
                label: 'Select worktree to delete',
                options: $options,
            );
        }

        if (! $worktreeManager->exists($name)) {
            $this->components->error("Worktree '{$name}' does not exist.");

            return self::FAILURE;
        }

        // Safety checks
        if (! $this->option('force')) {
            $safety = $worktreeManager->safetyCheck($name);

            if (! $safety['clean']) {
                $this->components->warn("Worktree '{$name}' has uncommitted changes.");
                if (! confirm('Continue anyway?', false)) {
                    return self::SUCCESS;
                }
            }

            if ($safety['unpushed']) {
                $this->components->warn("Worktree '{$name}' has unpushed commits.");
                if (! confirm('Continue anyway?', false)) {
                    return self::SUCCESS;
                }
            }

            if (! confirm("Delete worktree '{$name}'?")) {
                $this->components->info('Cancelled.');

                return self::SUCCESS;
            }
        }

        // Kill running process if any
        if ($processManager->isRunning($name)) {
            $this->components->info("Terminating running process for '{$name}'...");
            $processManager->terminate($name);
        }

        // Remove worktree
        $this->components->info("Removing worktree '{$name}'...");
        $worktreeManager->remove($name, (bool) $this->option('force'));

        // Drop database
        if (! $this->option('keep-db')) {
            $suffix = str_replace('-', '_', $name);
            $databaseCloner->drop($suffix);
        }

        $this->components->info("Worktree '{$name}' deleted.");

        return self::SUCCESS;
    }
}
