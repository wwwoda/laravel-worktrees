<?php

namespace Woda\Worktrees\Commands;

use Illuminate\Console\Command;
use Throwable;
use Woda\Worktrees\Agent\WorktreeInspector;
use Woda\Worktrees\Commands\Concerns\ResolvesWorktree;

/**
 * A short summary of the checkout for the start of an agent session. A
 * session-start hook prints it, so it never fails: what it cannot read, it
 * leaves out.
 */
class WorktreeContextCommand extends Command
{
    use ResolvesWorktree;

    protected $signature = 'worktree:context
        {worktree? : Worktree name, folder or "main" (default: this checkout)}
        {--json : Output as JSON}
        {--no-db : Do not check the database}';

    protected $description = 'Print a short summary of a worktree for an agent session (URL, services, database, login, recent errors)';

    public function handle(WorktreeInspector $inspector): int
    {
        try {
            $target = $this->resolveWorktree($inspector->manager(), is_string($this->argument('worktree')) ? $this->argument('worktree') : null);
            $status = $inspector->status($target, memory: false, database: ! $this->option('no-db'));
            $status['notes'] = $this->notes();
            $logLines = config('worktrees.context.log_errors', 5);
            $status['log_errors'] = $inspector->recentErrors($target->path, is_numeric($logLines) ? (int) $logLines : 5);
        } catch (Throwable $e) {
            $this->line('Worktree context unavailable: '.$e->getMessage());

            return self::SUCCESS;
        }
        if ($this->option('json')) {
            $this->line((string) json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }
        foreach ($this->lines($status) as $line) {
            $this->line($line);
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $status
     * @return list<string>
     */
    private function lines(array $status): array
    {
        $text = static fn (mixed $value): string => is_scalar($value) ? (string) $value : '';
        $lines = [
            '# Worktree '.$text($status['name']).($status['main'] ? ' (main checkout)' : ''),
            '- Folder: '.$text($status['path']),
            '- Branch: '.($text($status['branch']) ?: 'detached').(is_int($status['changes']) && $status['changes'] > 0 ? ", {$status['changes']} uncommitted files" : ''),
            '- URL: '.($text($status['url']) ?: 'not set'),
        ];
        if ($status['setup'] !== null && $status['setup'] !== 'ready') {
            $lines[] = '- Setup: '.$text($status['setup']).' (see php artisan worktree:doctor)';
        }
        /** @var list<array{service: string, state: string}> $services */
        $services = $status['services'];
        if ($services !== []) {
            $running = array_map(static fn (array $s): string => $s['service'], array_filter($services, static fn (array $s): bool => $s['state'] === 'running'));
            $stopped = array_map(static fn (array $s): string => $s['service'], array_filter($services, static fn (array $s): bool => $s['state'] !== 'running'));
            $lines[] = '- Running: '.($running === [] ? 'nothing' : implode(', ', $running)).($stopped === [] ? '' : '; stopped: '.implode(', ', $stopped));
        } elseif ($status['profiles'] !== null) {
            $lines[] = '- Running: nothing (start it with php artisan worktree:doctor --fix)';
        }
        if (is_array($status['profiles'])) {
            /** @var array{active: list<string>, available: list<string>} $profiles */
            $profiles = $status['profiles'];
            if ($profiles['available'] !== []) {
                $lines[] = '- Optional services: '.($profiles['active'] === [] ? 'none on' : implode(', ', $profiles['active']).' on')
                    .' (available: '.implode(', ', $profiles['available']).'; switch with php artisan worktree:services NAME)';
            }
        }
        if (is_array($status['database'])) {
            /** @var array{state: string, message: string} $database */
            $database = $status['database'];
            $lines[] = '- Database: '.$database['state'].', '.$database['message'];
        }
        /** @var list<string> $notes */
        $notes = $status['notes'];
        foreach ($notes as $note) {
            $lines[] = '- '.$note;
        }
        $lines[] = '- Commands: php artisan worktree:exec test --filter=X (tests in the container), worktree:exec artisan …, worktree:status, worktree:doctor --fix';
        /** @var list<string> $errors */
        $errors = $status['log_errors'];
        if ($errors !== []) {
            $lines[] = '- Last errors in storage/logs:';
            foreach ($errors as $error) {
                $lines[] = '  '.$error;
            }
        }

        return $lines;
    }

    /**
     * Project notes from `context.notes`, for example where the test login is.
     *
     * @return list<string>
     */
    private function notes(): array
    {
        $notes = config('worktrees.context.notes', []);

        return is_array($notes) ? array_values(array_filter($notes, static fn (mixed $note): bool => is_string($note) && $note !== '')) : [];
    }
}
