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
        {--keep-db : Keep the cloned database}
        {--delete-branch : Delete the local branch when it is merged into the base branch, without asking}';

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

        try {
            $branch = $worktreeManager->describe($name)['branch'];

            if ($processManager->isRunning($name)) {
                $this->components->info("Terminating running process for '{$name}'...");
            }
            // Always: finished sessions may still be tracked and must not outlive the worktree.
            $processManager->terminate($name);

            // First stop the containers: no process may hold files, ports or
            // database connections of what is removed next.
            $this->components->info('Stopping the worktree environment...');
            $worktreeManager->tearDown($name, keepDatabase: (bool) $this->option('keep-db'));

            // Shared services: only the worktree's own databases, user and Redis numbers.
            if ($worktreeManager->usesSharedDatabases() && ! $this->option('keep-db')) {
                foreach ($worktreeManager->removeSharedDatabases($name, fn (string $message) => $this->line("  {$message}")) as $removed) {
                    $this->line("  Removed {$removed}.");
                }
            }

            $this->components->info("Removing worktree '{$name}'...");
            $worktreeManager->remove($name);

            if (! $worktreeManager->usesSharedDatabases() && ! $this->option('keep-db')) {
                $suffix = str_replace('-', '_', $name);
                $databaseCloner->drop($suffix);
            }
        } catch (\RuntimeException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        if (is_string($branch) && $branch !== '') {
            $this->reportBranch($worktreeManager, $branch);
        }

        $this->components->info("Worktree '{$name}' deleted.");

        return self::SUCCESS;
    }

    private function reportBranch(WorktreeManager $worktreeManager, string $branch): void
    {
        $status = $worktreeManager->branchStatus($branch);
        if (! $status['exists']) {
            return;
        }
        if (! $status['merged']) {
            $this->components->warn("Branch '{$branch}' remains; it is not merged into the base branch.");

            return;
        }
        $delete = $this->option('delete-branch')
            || (! $this->option('force') && $this->input->isInteractive() && confirm("Branch '{$branch}' is merged. Delete it?", true));
        if (! $delete) {
            $this->components->warn("Branch '{$branch}' remains (merged; delete it with: git branch -d {$branch}).");

            return;
        }
        if ($worktreeManager->deleteMergedBranch($branch)) {
            $this->components->info("Deleted merged branch '{$branch}'.");
        } else {
            $this->components->warn("Branch '{$branch}' could not be deleted.");
        }
    }
}
