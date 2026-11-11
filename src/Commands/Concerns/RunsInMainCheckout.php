<?php

namespace Woda\Worktrees\Commands\Concerns;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Process as SymfonyProcess;
use Woda\Worktrees\ComposeProcess;
use Woda\Worktrees\Shared\MainCheckout;

/**
 * `worktree:create`, `worktree:delete` and `worktree:list` name and find
 * worktrees relative to the main checkout: its folder name, its `.env` and
 * its config. Run from a linked worktree, they would build `app-feat-fix-x`
 * instead of `app-fix-x`. So there they run again with the same arguments
 * in the main checkout, which git names (the folder of the common `.git`).
 */
trait RunsInMainCheckout
{
    private const DELEGATED = 'WORKTREES_RUN_IN_MAIN_CHECKOUT';

    /**
     * The exit code of the same command in the main checkout; null when
     * artisan already runs there.
     */
    protected function runInMainCheckout(): ?int
    {
        $main = MainCheckout::path();
        $here = base_path();
        if (getenv(self::DELEGATED) === '1' || (realpath($main) ?: $main) === (realpath($here) ?: $here) || ! is_file($main.'/artisan')) {
            return null;
        }
        // On stderr: `worktree:list --json` must print only JSON.
        $this->getOutput()->getErrorStyle()->writeln("Running in the main checkout {$main}.");
        // The values of this worktree's .env are in this process: the main checkout must read its own.
        $keys = ComposeProcess::INHERITED_VARS_TO_CLEAR;
        foreach ([$here.'/.env', $main.'/.env'] as $env) {
            if (File::exists($env)) {
                preg_match_all('/^(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=/m', File::get($env), $matches);
                $keys = [...$keys, ...$matches[1]];
            }
        }
        $process = Process::path($main)->forever()
            ->env([...array_fill_keys($keys, false), self::DELEGATED => '1']);
        $tty = $this->input->isInteractive() && SymfonyProcess::isTtySupported();
        if ($tty) {
            $process->tty();
        }
        $result = $process->run(
            implode(' ', array_map(escapeshellarg(...), [PHP_BINARY, 'artisan', ...$this->tokensForMainCheckout()])),
            $tty ? null : fn (string $type, string $buffer) => $type === 'err'
                ? $this->getOutput()->getErrorStyle()->write($buffer)
                : $this->output->write($buffer),
        );

        return $result->exitCode() ?? self::FAILURE;
    }

    /**
     * The command name, its arguments and the options that differ from their
     * defaults, as artisan reads them.
     *
     * @return list<string>
     */
    private function tokensForMainCheckout(): array
    {
        $tokens = [(string) $this->getName()];
        $global = $this->getApplication()?->getDefinition();
        foreach ($this->getDefinition()->getArguments() as $name => $argument) {
            if ($name === 'command') {
                continue;
            }
            $value = $this->input->getArgument($name);
            foreach (is_array($value) ? $value : [$value] as $item) {
                if (is_scalar($item) && (string) $item !== '') {
                    $tokens[] = (string) $item;
                }
            }
        }
        foreach ($this->getDefinition()->getOptions() as $name => $option) {
            $value = $this->input->getOption($name);
            // artisan's own options (-n, -v, --env) are not the command's.
            if ($global?->hasOption($name) || $value === $option->getDefault() || $value === null || $value === false) {
                continue;
            }
            foreach (is_array($value) ? $value : [$value] as $item) {
                $tokens[] = $item === true ? '--'.$name : '--'.$name.'='.(is_scalar($item) ? (string) $item : '');
            }
        }
        if (! $this->input->isInteractive()) {
            $tokens[] = '--no-interaction';
        }

        return $tokens;
    }
}
