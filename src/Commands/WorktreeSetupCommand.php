<?php

namespace Woda\Worktrees\Commands;

use Illuminate\Console\Command;
use Woda\Worktrees\Commands\Concerns\RunsBootstrap;
use Woda\Worktrees\WorktreeManager;

class WorktreeSetupCommand extends Command
{
    use RunsBootstrap;

    protected $signature = 'worktree:setup
        {path : Folder of an existing git worktree of this repository}
        {--name= : Worktree name for its databases and URLs (default: the folder name)}
        {--skip-deps : Skip dependency installation}
        {--skip-build : Skip frontend build}
        {--skip-db : Skip the databases}
        {--from-main : Shared services: copy the main checkout\'s database instead of the template (only where config allows it)}
        {--horizon : Shared services: run Horizon as the queue worker instead of queue:listen}';

    protected $description = 'Set up a git worktree that another tool created, or complete a partial setup; safe to run again';

    public function handle(WorktreeManager $manager): int
    {
        /** @var string $pathArgument */
        $pathArgument = $this->argument('path');
        $path = realpath($pathArgument);
        if ($path === false || ! is_dir($path)) {
            $this->components->error("Folder '{$pathArgument}' does not exist.");

            return self::FAILURE;
        }
        try {
            $described = $manager->describePath($path);
            if ($manager->isMainCheckout($path)) {
                throw new \RuntimeException('This is the main checkout; a worktree setup does not change it.');
            }
        } catch (\RuntimeException|\JsonException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }
        // A run again keeps the name of the first run: completed stages used it.
        $recorded = is_array($described['bootstrap']) && is_string($described['bootstrap']['name'] ?? null) ? $described['bootstrap']['name'] : null;
        $nameOption = $this->option('name');
        $name = is_string($nameOption) && $nameOption !== ''
            ? $nameOption
            : ($recorded ?? trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower(basename($path))), '-'));
        if (! preg_match('/^[a-zA-Z0-9-]+$/', $name)) {
            $this->components->error('Name must be alphanumeric with hyphens only.');

            return self::FAILURE;
        }

        $this->components->info("Setting up '{$name}' at {$path}...");

        return $this->runBootstrap($manager, $name, $path, [
            'resume' => true,
            'setup' => true,
            'skip_deps' => (bool) $this->option('skip-deps'),
            'skip_build' => (bool) $this->option('skip-build'),
            'skip_db' => (bool) $this->option('skip-db'),
            'from_main' => (bool) $this->option('from-main'),
            'horizon' => (bool) $this->option('horizon'),
        ], 'php artisan worktree:setup '.escapeshellarg($path).' --name='.$name);
    }
}
