<?php

namespace Woda\Worktrees\Commands;

use Illuminate\Console\Command;
use Woda\Worktrees\Shared\TemplateManager;

class WorktreeTemplateCommand extends Command
{
    protected $signature = 'worktree:template
        {--prune : Drop every template database except the current one}
        {--name : Print the current template name only}';

    protected $description = 'Build the template database for shared-services worktrees when it is missing';

    public function handle(TemplateManager $templates): int
    {
        if ($this->option('name')) {
            $this->line($templates->name());

            return self::SUCCESS;
        }

        try {
            $name = $templates->ensure(
                $this->output->isVerbose() ? fn (string $type, string $buffer) => $this->output->write($buffer) : null,
                fn (string $message) => $this->components->info($message),
            );
            $this->components->info("Template {$name} is ready.");

            if ($this->option('prune')) {
                foreach ($templates->prune() as $dropped) {
                    $this->components->info("Dropped old template {$dropped}.");
                }
            }
        } catch (\RuntimeException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
