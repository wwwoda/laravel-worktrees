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

        try {
            if (! $worktreeManager->exists($name)) {
                $this->components->error("Worktree '{$name}' does not exist.");

                return self::FAILURE;
            }

            // Registration first: the safety checks run git in the folder, and a look-alike folder is not ours.
            $branch = $worktreeManager->describe($name)['branch'];

            // Shared services: a missing or bad state file stops the delete before
            // anything is stopped or removed.
            if ($worktreeManager->usesSharedDatabases() && ! $this->option('keep-db')) {
                $worktreeManager->checkSharedDatabases($name);
            }

            if (! $this->option('force') && ! $this->confirmDelete($worktreeManager, $name)) {
                return self::SUCCESS;
            }

            if ($processManager->isRunning($name)) {
                $this->components->info("Terminating running process for '{$name}'...");
            }
            // Always: finished sessions may still be tracked and must not outlive the worktree.
            $processManager->terminate($name);

            // First stop the containers: no process may hold files, ports or
            // database connections of what is removed next.
            $this->components->info('Stopping the worktree environment...');
            $verbose = $this->output->isVerbose();
            $worktreeManager->tearDown($name, function (string $type, string $buffer) use ($verbose): void {
                if ($verbose) {
                    $this->output->write($buffer);
                } elseif (str_starts_with($buffer, 'Removed the image')) {
                    $this->line('  '.trim($buffer));
                }
            }, keepDatabase: (bool) $this->option('keep-db'));

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
        } catch (\JsonException $e) {
            $this->components->error('storage/worktree-bootstrap.json is not valid JSON: '.$e->getMessage());

            return self::FAILURE;
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
        $how = $status['via'] === 'pull request' ? ' by a pull request (squashed or rebased)' : '';
        $delete = $this->option('delete-branch')
            || (! $this->option('force') && $this->input->isInteractive() && confirm("Branch '{$branch}' is merged{$how}. Delete it?", true));
        if (! $delete) {
            $flag = $status['via'] === 'pull request' ? '-D' : '-d';
            $this->components->warn("Branch '{$branch}' remains (merged{$how}; delete it with: git branch {$flag} {$branch}).");

            return;
        }
        if ($worktreeManager->deleteMergedBranch($branch, $status['via'] === 'pull request')) {
            $this->components->info("Deleted merged branch '{$branch}'.");
        } else {
            $this->components->warn("Branch '{$branch}' could not be deleted.");
        }
    }

    /**
     * Safety checks and the final question; false when the user stops.
     */
    private function confirmDelete(WorktreeManager $worktreeManager, string $name): bool
    {
        $safety = $worktreeManager->safetyCheck($name);

        if (! $safety['clean']) {
            $this->components->warn("Worktree '{$name}' has uncommitted changes.");
            if (! confirm('Continue anyway?', false)) {
                return false;
            }
        }

        if ($safety['unpushed']) {
            $this->components->warn("Worktree '{$name}' has unpushed commits.");
            if (! confirm('Continue anyway?', false)) {
                return false;
            }
        }

        if (! confirm("Delete worktree '{$name}'?")) {
            $this->components->info('Cancelled.');

            return false;
        }

        return true;
    }
}
