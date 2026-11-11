<?php

namespace Woda\Worktrees\Commands;

use Illuminate\Console\Command;
use Throwable;
use Woda\Worktrees\Agent\Doctor;
use Woda\Worktrees\Agent\WorktreeInspector;
use Woda\Worktrees\Agent\WorktreeTarget;
use Woda\Worktrees\Commands\Concerns\ResolvesWorktree;
use Woda\Worktrees\SetupLock;

class WorktreeDoctorCommand extends Command
{
    use ResolvesWorktree;

    protected $signature = 'worktree:doctor
        {worktree? : Worktree name, folder or "main" (default: this checkout)}
        {--fix : Fix what can be fixed, in order}
        {--json : Output as JSON}';

    protected $description = 'Find (and with --fix repair) the usual problems of a worktree: stopped containers, missing dependencies, pending migrations, stale caches and workers';

    public function handle(WorktreeInspector $inspector, Doctor $doctor): int
    {
        try {
            $target = $this->resolveWorktree($inspector->manager(), is_string($this->argument('worktree')) ? $this->argument('worktree') : null);
        } catch (\RuntimeException|\JsonException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }
        $fix = (bool) $this->option('fix');
        // A setup that still runs installs and migrates itself: fixes would run in parallel with it.
        $lock = null;
        if ($fix && ! $target->main) {
            $lock = SetupLock::tryAcquire($target->path);
            if ($lock === null) {
                $this->components->error('A worktree setup runs in '.$target->path.' (storage/worktree-bootstrap.lock). Wait until it ends, then run worktree:doctor --fix again.');

                return self::FAILURE;
            }
        }
        try {
            return $this->diagnose($doctor, $target, $fix);
        } finally {
            $lock === null || SetupLock::release($lock);
        }
    }

    private function diagnose(Doctor $doctor, WorktreeTarget $target, bool $fix): int
    {
        $output = $this->output->isVerbose() ? fn (string $type, string $buffer) => $this->output->write($buffer) : null;
        $results = [];
        // In order: a later check sees what an earlier fix changed.
        foreach (Doctor::CHECKS as $id) {
            $result = $this->safely(fn () => $doctor->check($id, $target));
            if ($fix && $result['fixable'] && in_array($result['state'], ['warn', 'fail'], true)) {
                $error = $this->runFix($doctor, $id, $target, $result['message'], $output);
                $result = $this->safely(fn () => $doctor->check($id, $target));
                if ($error !== null) {
                    $result['message'] .= " (fix failed: {$error})";
                }
            }
            $results[$id] = $result;
        }

        if ($this->option('json')) {
            $this->line((string) json_encode(['worktree' => $target->name, 'path' => $target->path, 'checks' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $colors = ['ok' => 'green', 'warn' => 'yellow', 'fail' => 'red', 'skip' => 'gray'];
            $this->table(['Check', 'State', 'Details'], array_map(
                static fn (string $id, array $r): array => [$id, '<fg='.($colors[$r['state']] ?? 'default').">{$r['state']}</>", $r['message']],
                array_keys($results), $results,
            ));
            $fixable = array_keys(array_filter($results, static fn (array $r): bool => $r['fixable'] && in_array($r['state'], ['warn', 'fail'], true)));
            if (! $fix && $fixable !== []) {
                $this->components->info('Fix '.implode(', ', $fixable).' with: php artisan worktree:doctor '.($target->main ? 'main' : $target->name).' --fix');
            }
        }

        return array_filter($results, static fn (array $r): bool => $r['state'] === 'fail') === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  (\Closure(string, string): void)|null  $output
     * @return string|null the error of a failed fix
     */
    private function runFix(Doctor $doctor, string $id, WorktreeTarget $target, string $problem, ?\Closure $output): ?string
    {
        $error = null;
        $fix = function () use ($doctor, $id, $target, $output, &$error): bool {
            try {
                $doctor->fix($id, $target, $output);

                return true;
            } catch (Throwable $e) {
                // Build and install errors are long: the first line, and -v shows the output.
                $lines = array_values(array_filter(array_map(trim(...), explode("\n", $e->getMessage())), static fn (string $line): bool => $line !== ''));
                $error = mb_strimwidth($lines[0] ?? 'unknown error', 0, 160, '…').(count($lines) > 1 ? ' (run with -v for the output)' : '');

                return false;
            }
        };
        $this->option('json') ? $fix() : $this->components->task("Fixing {$id}: {$problem}", $fix);

        return $error;
    }

    /**
     * A check that cannot run (docker missing, compose file broken) is a failure, not a crash.
     *
     * @param  \Closure(): array{state: string, message: string, fixable: bool}  $check
     * @return array{state: string, message: string, fixable: bool}
     */
    private function safely(\Closure $check): array
    {
        try {
            return $check();
        } catch (Throwable $e) {
            return ['state' => 'fail', 'message' => $e->getMessage(), 'fixable' => false];
        }
    }
}
