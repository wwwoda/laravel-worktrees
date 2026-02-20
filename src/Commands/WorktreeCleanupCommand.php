<?php

namespace Woda\Worktrees\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Woda\Worktrees\Contracts\ProcessManager;
use Woda\Worktrees\DatabaseCloner;
use Woda\Worktrees\WorktreeManager;

use function Laravel\Prompts\confirm;

class WorktreeCleanupCommand extends Command
{
    protected $signature = 'worktree:cleanup
        {--dry-run : Show what would be removed}
        {--force : Skip confirmation}';

    protected $description = 'Remove worktrees whose issues or PRs are closed';

    public function handle(
        WorktreeManager $worktreeManager,
        ProcessManager $processManager,
        DatabaseCloner $databaseCloner,
    ): int {
        $worktrees = $worktreeManager->list();

        if ($worktrees === []) {
            $this->components->info('No agent worktrees found.');

            return self::SUCCESS;
        }

        $toRemove = [];

        foreach ($worktrees as $wt) {
            /** @var string $name */
            $name = $wt['name'];
            /** @var string $branch */
            $branch = $wt['branch'] ?? '';

            if ($branch === '') {
                continue;
            }

            // Skip dirty worktrees
            if ($worktreeManager->isDirty($name)) {
                $this->components->warn("Skipping '{$name}' — has uncommitted changes.");

                continue;
            }

            $reason = $this->closedReason($branch);

            if ($reason !== null) {
                $toRemove[$name] = $reason;
            }
        }

        if ($toRemove === []) {
            $this->components->info('No worktrees with closed issues/PRs found.');

            return self::SUCCESS;
        }

        $this->components->info('Worktrees to remove:');
        foreach ($toRemove as $name => $reason) {
            $this->line("  - {$name} ({$reason})");
        }

        if ($this->option('dry-run')) {
            $this->components->info('Dry run — no changes made.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! confirm('Remove these worktrees?')) {
            $this->components->info('Cancelled.');

            return self::SUCCESS;
        }

        foreach (array_keys($toRemove) as $name) {
            if ($processManager->isRunning($name)) {
                $processManager->terminate($name);
            }

            $worktreeManager->remove($name, true);

            $suffix = str_replace('-', '_', $name);
            $databaseCloner->drop($suffix);

            $this->components->info("Removed '{$name}'.");
        }

        $this->components->info('Cleanup complete.');

        return self::SUCCESS;
    }

    private function closedReason(string $branch): ?string
    {
        // Check for a merged or closed PR on this branch
        $result = Process::timeout(15)->run(
            sprintf('gh pr list --head %s --state all --json state,number --limit 5', escapeshellarg($branch)),
        );

        if ($result->successful()) {
            /** @var list<array{state: string, number: int}> $prs */
            $prs = json_decode($result->output(), true) ?: [];

            $hasOpen = false;
            $closedPr = null;

            foreach ($prs as $pr) {
                if ($pr['state'] === 'OPEN') {
                    $hasOpen = true;
                } elseif ($closedPr === null) {
                    $closedPr = $pr;
                }
            }

            if (! $hasOpen && $closedPr !== null) {
                $state = strtolower($closedPr['state']);

                return "PR #{$closedPr['number']} {$state}";
            }
        }

        // Check if branch name starts with an issue number
        if (preg_match('/^(\d+)-/', $branch, $matches)) {
            $issueNumber = $matches[1];

            $result = Process::timeout(15)->run(
                sprintf('gh issue view %s --json state', $issueNumber),
            );

            if ($result->successful()) {
                /** @var array{state: string}|null $data */
                $data = json_decode($result->output(), true);

                if (($data['state'] ?? '') === 'CLOSED') {
                    return "issue #{$issueNumber} closed";
                }
            }
        }

        return null;
    }
}
