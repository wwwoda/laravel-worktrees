<?php

namespace Woda\Worktrees\Commands;

use Illuminate\Console\Command;
use Woda\Worktrees\Agent\WorktreeInspector;
use Woda\Worktrees\Commands\Concerns\ResolvesWorktree;

class WorktreeStatusCommand extends Command
{
    use ResolvesWorktree;

    protected $signature = 'worktree:status
        {worktree? : Worktree name, folder or "main" (default: this checkout)}
        {--json : Output as JSON}
        {--no-memory : Do not read the memory of the containers}
        {--no-db : Do not check the database}';

    protected $description = 'Show the URL, services, memory, database state and git state of a worktree';

    public function handle(WorktreeInspector $inspector): int
    {
        try {
            $target = $this->resolveWorktree($inspector->manager(), is_string($this->argument('worktree')) ? $this->argument('worktree') : null);
        } catch (\RuntimeException|\JsonException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }
        $status = $inspector->status($target, ! $this->option('no-memory'), ! $this->option('no-db'));

        if ($this->option('json')) {
            $this->line((string) json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->renderStatus($status);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $status
     */
    private function renderStatus(array $status): void
    {
        $text = static fn (mixed $value): string => is_scalar($value) ? (string) $value : '';
        $this->components->twoColumnDetail('Worktree', $text($status['name']).($status['main'] ? ' (main checkout)' : ''));
        $this->components->twoColumnDetail('Folder', $text($status['path']));
        $changes = is_int($status['changes']) ? ($status['changes'] === 0 ? 'clean' : "{$status['changes']} uncommitted files") : 'unknown';
        $this->components->twoColumnDetail('Branch', ($text($status['branch']) ?: 'detached').' @ '.$text($status['head']).', '.$changes);
        $this->components->twoColumnDetail('URL', $text($status['url']) ?: '-');
        $this->components->twoColumnDetail('Setup', $text($status['setup']) ?: '-');
        if ($status['compose_project'] !== null) {
            $this->components->twoColumnDetail('Compose project', $text($status['compose_project']));
        }
        if (is_array($status['profiles'])) {
            /** @var array{active: list<string>, available: list<string>} $profiles */
            $profiles = $status['profiles'];
            $this->components->twoColumnDetail('Profiles', ($profiles['active'] === [] ? 'none' : implode(', ', $profiles['active']))
                .($profiles['available'] === [] ? '' : ' (available: '.implode(', ', $profiles['available']).')'));
        }
        if (is_array($status['database'])) {
            /** @var array{state: string, message: string} $database */
            $database = $status['database'];
            $this->components->twoColumnDetail('Database', $database['state'].': '.$database['message']);
        }
        /** @var list<array{service: string, name: string, state: string, health: string, memory: int|null}> $services */
        $services = $status['services'];
        if ($services !== []) {
            $known = array_filter(array_map(static fn (array $s): ?int => $s['memory'], $services), is_int(...));
            $this->table(['Service', 'State', 'Memory'], [
                ...array_map(static fn (array $s): array => [
                    $s['service'],
                    $s['state'].($s['health'] !== '' ? " ({$s['health']})" : ''),
                    WorktreeInspector::formatBytes($s['memory']),
                ], $services),
                ...($known === [] ? [] : [['total', '', WorktreeInspector::formatBytes(array_sum($known))]]),
            ]);
        }
        /** @var list<string> $errors */
        $errors = $status['errors'];
        foreach ($errors as $error) {
            $this->components->warn($error);
        }
    }
}
