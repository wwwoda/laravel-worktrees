<?php

namespace Woda\Worktrees\Commands;

use Illuminate\Console\Command;
use Woda\Worktrees\WorktreeManager;

class WorktreeBootstrapCommand extends Command
{
    protected $signature = 'worktree:bootstrap {name} {--resume : Preserve completed stages and lane config} {--status : Report checkout and bootstrap state as JSON} {--skip-deps} {--skip-build} {--skip-db}';

    protected $description = 'Inspect or resume setup of an existing registered worktree';

    public function handle(WorktreeManager $manager): int
    {
        /** @var string $name */
        $name = $this->argument('name');
        if ($this->option('status')) {
            $this->line(json_encode($manager->describe($name), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }
        if (! $this->option('resume')) {
            $this->components->error('Use --resume to preserve existing worktree configuration.');

            return self::FAILURE;
        }
        $manager->bootstrap($name, [
            'resume' => true,
            'skip_deps' => (bool) $this->option('skip-deps'),
            'skip_build' => (bool) $this->option('skip-build'),
            'skip_db' => (bool) $this->option('skip-db'),
        ], fn (string $stage) => $this->components->info($stage));

        return self::SUCCESS;
    }
}
