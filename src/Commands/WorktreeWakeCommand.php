<?php

namespace Woda\Worktrees\Commands;

use Illuminate\Console\Command;
use Woda\Worktrees\Contracts\BootstrapStrategy;
use Woda\Worktrees\Idle\IdleStacks;
use Woda\Worktrees\SailBootstrapStrategy;
use Woda\Worktrees\WorktreeManager;

class WorktreeWakeCommand extends Command
{
    protected $signature = 'worktree:wake
        {worktree? : Worktree name or folder (default: this checkout)}';

    protected $description = 'Start the stack of a worktree that worktree:idle-stop (or Sablier) stopped, and wait until it is healthy';

    public function handle(IdleStacks $stacks, BootstrapStrategy $strategy, WorktreeManager $manager): int
    {
        if (! $strategy instanceof SailBootstrapStrategy) {
            $this->components->error('Waking a stack needs the sail or shared strategy.');

            return self::FAILURE;
        }
        $target = $this->argument('worktree');
        try {
            // A folder first (also a relative one without a slash), then a worktree name.
            $path = match (true) {
                ! is_string($target) || $target === '' => base_path(),
                is_dir($target) => (string) realpath($target),
                str_contains($target, '/') => throw new \RuntimeException("{$target} is no folder."),
                default => $manager->pathFor($target),
            };
            // Only a checkout that git registers for this repository.
            $manager->describePath($path);
        } catch (\RuntimeException|\JsonException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }
        // As the setup does: a copied .env may name another checkout's compose project,
        // and `up` would then start (or change) that checkout's containers.
        $others = array_values(array_filter($stacks->checkouts(true), static fn (array $checkout): bool => realpath($checkout['path']) !== realpath($path)));
        $isMain = $others !== [] && ! in_array(true, array_column($others, 'main'), true);
        if (! $isMain) {
            $project = $stacks->project($path);
            if ($project === null) {
                $this->components->error("The compose config of {$path} cannot be read.");

                return self::FAILURE;
            }
            foreach ($others as $other) {
                if ($stacks->project($other['path']) === $project) {
                    $owner = $other['main'] ? "the main checkout's" : "{$other['name']}'s";
                    $this->components->error("{$path} has {$owner} compose project {$project}: set its own COMPOSE_PROJECT_NAME in its .env.");

                    return self::FAILURE;
                }
            }
        }
        $result = $stacks->wake($path, $this->output->isVerbose() ? fn (string $type, string $buffer) => $this->output->write($buffer) : null);
        if (! $result->successful()) {
            $this->components->error('docker compose up -d --wait failed: '.trim($result->errorOutput()));

            return self::FAILURE;
        }
        $this->components->info("The stack of {$path} runs.");

        return self::SUCCESS;
    }
}
