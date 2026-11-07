<?php

namespace Woda\Worktrees\Commands\Concerns;

use Illuminate\Support\Facades\File;
use Woda\Worktrees\Shared\EnvFile;
use Woda\Worktrees\WorktreeManager;

/**
 * Runs the bootstrap of one worktree for a command: steps and process output
 * go to the terminal and to `storage/logs/worktree-setup.log` in the worktree,
 * so a backgrounded run can be followed; at the end a table of step times and
 * the worktree's URL.
 */
trait RunsBootstrap
{
    /**
     * @param  array{skip_deps?: bool, skip_build?: bool, skip_db?: bool, resume?: bool, from_main?: bool, base?: string|null, horizon?: bool, setup?: bool}  $options
     */
    protected function runBootstrap(WorktreeManager $manager, string $name, string $path, array $options, string $resumeHint): int
    {
        $log = $path.'/storage/logs/worktree-setup.log';
        File::ensureDirectoryExists(dirname($log));
        $write = static function (string $text) use ($log): void {
            File::append($log, $text);
        };
        $write(sprintf("\n[%s] Setup of '%s' at %s\n", date(DATE_ATOM), $name, $path));
        $verbose = $this->output->isVerbose();

        $onStep = function (string $message) use ($write, $verbose): void {
            $write('['.date('H:i:s')."] {$message}\n");
            if ($verbose) {
                $this->components->info($message);
            }
        };
        $processOutput = function (string $type, string $buffer) use ($write, $verbose): void {
            $write($buffer);
            if ($verbose) {
                $this->output->write($buffer);
            }
        };

        $timings = [];
        $started = microtime(true);
        try {
            $manager->bootstrapPath($name, $path, $options, $onStep, $processOutput, function (string $stage, float $seconds) use (&$timings, $write): void {
                $timings[] = [$stage, number_format($seconds, 1).' s'];
                $write(sprintf("[%s] %s done in %.1f s\n", date('H:i:s'), $stage, $seconds));
            });
        } catch (\RuntimeException $e) {
            $write('['.date('H:i:s').'] FAILED: '.$e->getMessage()."\n");
            $this->components->error($e->getMessage());
            $this->components->warn("The checkout is kept. Continue with: {$resumeHint}");
            $this->components->warn("Log: {$log}");

            return self::FAILURE;
        }

        $timings[] = ['total', number_format(microtime(true) - $started, 1).' s'];
        $this->table(['Step', 'Time'], $timings);
        $this->components->info("Worktree '{$name}' is ready.");
        $write('['.date('H:i:s')."] Ready.\n");
        $url = EnvFile::get($path.'/.env', 'APP_URL');
        if ($url !== null && $url !== '') {
            $this->line('  '.$url);
        }

        return self::SUCCESS;
    }
}
