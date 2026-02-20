<?php

namespace Woda\Worktrees\Commands;

use Illuminate\Console\Command;
use Woda\Worktrees\Contracts\ProcessManager;
use Woda\Worktrees\DatabaseCloner;
use Woda\Worktrees\WorktreeManager;

class WorktreeListCommand extends Command
{
    protected $signature = 'worktree:list
        {--json : Output as JSON}';

    protected $description = 'List git worktrees';

    public function handle(
        WorktreeManager $worktreeManager,
        ProcessManager $processManager,
        DatabaseCloner $databaseCloner,
    ): int {
        $worktrees = $worktreeManager->list();

        if ($this->option('json')) {
            $this->line((string) json_encode($worktrees, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if ($worktrees === []) {
            $this->components->info('No agent worktrees found.');

            return self::SUCCESS;
        }

        $clonedDbs = $databaseCloner->listCloned();

        $headers = ['Name', 'Branch', 'Status', 'Process', 'Database'];

        $rows = [];
        foreach ($worktrees as $wt) {
            /** @var string $name */
            $name = $wt['name'];
            /** @var string $branch */
            $branch = $wt['branch'] ?? 'detached';

            $dirty = $worktreeManager->isDirty($name);
            $status = $dirty ? '<fg=yellow>dirty</>' : '<fg=green>clean</>';

            $processLabel = $processManager->runningLabel($name);
            $process = $processLabel !== null ? "<fg=green>{$processLabel}</>" : '-';

            $suffix = str_replace('-', '_', $name);
            $sourceDb = $databaseCloner->sourceDatabase();
            $dbName = $sourceDb.'_'.$suffix;
            $db = in_array($dbName, $clonedDbs, true) ? $dbName : '-';

            $rows[] = [$name, $branch, $status, $process, $db];
        }

        $this->table($headers, $rows);

        return self::SUCCESS;
    }
}
