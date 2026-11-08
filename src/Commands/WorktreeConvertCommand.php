<?php

namespace Woda\Worktrees\Commands;

use Illuminate\Console\Command;
use Woda\Worktrees\Shared\SailConverter;

use function Laravel\Prompts\select;

class WorktreeConvertCommand extends Command
{
    protected $signature = 'worktree:convert
        {name? : Convert only this worktree (main for the main checkout, or its folder path)}
        {--all : Convert every worktree that is not converted yet, without asking}';

    protected $description = 'Move Sail worktrees to shared services, keeping their data; the old volumes stay as backup';

    public function handle(SailConverter $converter): int
    {
        try {
            $candidates = $converter->candidates();
        } catch (\RuntimeException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }
        $this->table(
            ['Worktree', 'Branch', 'Last commit', 'Merged', 'Status'],
            array_map(static fn (array $c): array => [
                $c['name'], $c['branch'] ?? '(detached)', $c['last_commit'],
                $c['main'] ? '' : ($c['merged'] ? 'yes' : 'no'),
                $c['converted'] ? 'converted' : 'to convert',
            ], $candidates),
        );

        $pending = array_values(array_filter($candidates, static fn (array $c): bool => ! $c['converted']));
        $name = $this->argument('name');
        if (is_string($name) && $name !== '') {
            // A folder path picks exactly one; a name may fit several (folders outside the worktree folder).
            $folder = is_dir($name) ? (realpath($name) ?: $name) : null;
            $pending = array_values(array_filter($candidates, static fn (array $c): bool => $folder !== null
                ? (realpath($c['path']) ?: $c['path']) === $folder
                : $c['name'] === $name));
            if ($pending === []) {
                $this->components->error("No worktree named '{$name}'.");

                return self::FAILURE;
            }
            if (count($pending) > 1) {
                $this->components->error("Several worktrees are named '{$name}': ".implode(', ', array_column($pending, 'path')).'. Give the folder path instead.');

                return self::FAILURE;
            }
            if ($pending[0]['converted']) {
                $this->components->info("'{$name}' is converted already.");

                return self::SUCCESS;
            }
        }
        if ($pending === []) {
            $this->components->info('Nothing to convert.');

            return self::SUCCESS;
        }

        $ask = ! $this->option('all') && ! (is_string($name) && $name !== '');
        $failed = [];
        foreach ($pending as $candidate) {
            $action = 'convert';
            if ($ask) {
                $merged = $candidate['merged'];
                // Delete goes through worktree:delete <name>, which finds the folder by
                // name: offer it only when that name leads to exactly this folder.
                $deletable = ! $candidate['main'] && $converter->deletableByName($candidate['name'], $candidate['path']);
                $action = (string) select(
                    label: sprintf('%s (%s, %s)%s', $candidate['name'], $candidate['branch'] ?? 'detached', $candidate['last_commit'],
                        $merged ? ($deletable ? ': merged, delete suggested' : ': merged; outside the worktree folder, delete it by hand') : ''),
                    options: $deletable
                        ? ['convert' => 'Convert', 'delete' => 'Delete the worktree', 'skip' => 'Skip']
                        : ['convert' => 'Convert', 'skip' => 'Skip'],
                    default: $merged && $deletable ? 'delete' : 'convert',
                );
            }
            if ($action === 'skip') {
                continue;
            }
            if ($action === 'delete') {
                if ($this->call('worktree:delete', ['name' => $candidate['name']]) !== self::SUCCESS) {
                    $failed[] = $candidate['name'];
                }

                continue;
            }

            $this->components->info("Converting '{$candidate['name']}'...");
            try {
                $converter->convert(
                    $candidate,
                    fn (string $message) => $this->line("  {$message}"),
                    $this->output->isVerbose() ? fn (string $type, string $buffer) => $this->output->write($buffer) : null,
                );
                $this->components->info("'{$candidate['name']}' is converted. Its old volume stays as backup (worktree:volumes).");
            } catch (\RuntimeException $e) {
                $failed[] = $candidate['name'];
                $this->components->error("'{$candidate['name']}': {$e->getMessage()}");
                if ($candidate['main']) {
                    $this->components->error('The main checkout converts first; stopping.');

                    return self::FAILURE;
                }
            }
        }

        if ($failed !== []) {
            $this->components->error('Not converted or not deleted: '.implode(', ', $failed).'. Run worktree:convert again to retry.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
