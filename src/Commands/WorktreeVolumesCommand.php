<?php

namespace Woda\Worktrees\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Woda\Worktrees\Shared\SailConverter;
use Woda\Worktrees\Shared\SharedConfig;

class WorktreeVolumesCommand extends Command
{
    protected $signature = 'worktree:volumes
        {--remove=* : Remove these volumes (exact names from the list)}';

    protected $description = 'List the old worktree volumes kept as backup, with their size; remove the ones you name';

    public function handle(SailConverter $converter): int
    {
        $pattern = (new SharedConfig)->string('convert.backup_volumes', '/_sail-(mariadb|mysql|pgsql|redis)$/');
        try {
            $volumes = $converter->backupVolumes($pattern);
        } catch (\RuntimeException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        /** @var list<string> $remove */
        $remove = array_values(array_filter((array) $this->option('remove'), is_string(...)));
        if ($remove === []) {
            if ($volumes === []) {
                $this->components->info('No old volumes.');

                return self::SUCCESS;
            }
            $this->table(['Volume', 'Size'], array_map(static fn (array $v): array => [$v['name'], $v['size']], $volumes));

            return self::SUCCESS;
        }

        $known = array_column($volumes, 'name');
        $status = self::SUCCESS;
        foreach ($remove as $volume) {
            if (! in_array($volume, $known, true)) {
                $this->components->error("'{$volume}' is not in the list of old volumes; not removed.");
                $status = self::FAILURE;

                continue;
            }
            $result = Process::timeout(60)->run(['docker', 'volume', 'rm', $volume]);
            if ($result->successful()) {
                $this->components->info("Removed {$volume}.");
            } else {
                $this->components->error("Removing {$volume} failed: {$result->errorOutput()}");
                $status = self::FAILURE;
            }
        }

        return $status;
    }
}
