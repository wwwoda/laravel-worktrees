<?php

namespace Woda\Worktrees\Commands;

use Illuminate\Console\Command;
use RuntimeException;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Process\Process;
use Woda\Worktrees\Agent\WorktreeInspector;
use Woda\Worktrees\Commands\Concerns\ResolvesWorktree;

/**
 * Runs a command where the worktree's PHP runs. Everything after the command
 * name and this command's own options goes to the command unchanged, so
 * `worktree:exec test --filter=X` needs no `--`.
 */
class WorktreeExecCommand extends Command
{
    use ResolvesWorktree;

    protected $signature = 'worktree:exec
        {cmd?* : The command, or an alias such as test, artisan, tinker, composer}
        {--w|worktree= : Worktree name, folder or "main" (default: this checkout)}
        {--service= : Compose service to run it in (default: the app service)}';

    protected $description = 'Run a command in the app container of a worktree (on the host with the native strategy)';

    public function __construct()
    {
        parent::__construct();
        // The options of the command that runs (--filter, --parallel) are not ours.
        $this->ignoreValidationErrors();
    }

    public function handle(WorktreeInspector $inspector): int
    {
        [$worktree, $service, $words] = $this->invocation();
        if ($words === []) {
            $this->components->error('Give a command, for example: php artisan worktree:exec test --filter=UserTest');

            return self::FAILURE;
        }
        try {
            $target = $this->resolveWorktree($inspector->manager(), $worktree);
            // A worktree with the main checkout's compose project would run the command in the main checkout's container.
            $inspector->assertOwnStack($target);
        } catch (RuntimeException|\JsonException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }
        $runner = $inspector->runner();
        $command = $this->commandFor($words);
        $tty = $this->interactiveTerminal();
        $process = $runner->process($target->path, 60)->forever();
        if ($tty) {
            $process->tty();
        }
        $commandLine = $runner->commandLine($command, $service, $tty);
        $result = $process->run($commandLine, $tty ? null : function (string $type, string $buffer): void {
            $type === 'err' ? $this->getOutput()->getErrorStyle()->write($buffer) : $this->output->write($buffer);
        });
        $exitCode = $result->exitCode() ?? 1;
        if ($exitCode !== 0 && $runner->usesCompose() && preg_match('/is not running|no container found|not running/i', $result->errorOutput())) {
            $this->components->warn('The container does not run. Start it with: php artisan worktree:doctor --fix');
        }

        return $exitCode;
    }

    /**
     * The worktree, the service and the command words. With a terminal call
     * the raw arguments count: Symfony would drop the command's own options.
     *
     * @return array{string|null, string|null, list<string>}
     */
    private function invocation(): array
    {
        $tokens = $this->rawTokens();
        if ($tokens === null) {
            /** @var list<string> $words */
            $words = $this->argument('cmd');
            $worktree = $this->option('worktree');
            $service = $this->option('service');

            return [is_string($worktree) ? $worktree : null, is_string($service) ? $service : null, $words];
        }

        return self::parseTokens($tokens);
    }

    /**
     * @param  list<string>  $tokens  the tokens after `artisan`
     * @return array{string|null, string|null, list<string>}
     */
    public static function parseTokens(array $tokens): array
    {
        $i = 0;
        $count = count($tokens);
        // Global options before the command name, then the command name.
        while ($i < $count && str_starts_with($tokens[$i], '-')) {
            $i++;
        }
        $i++;
        $values = ['worktree' => null, 'service' => null];
        for (; $i < $count; $i++) {
            $token = $tokens[$i];
            if ($token === '--') {
                $i++;
                break;
            }
            if (preg_match('/^--(worktree|service)=(.*)$/s', $token, $match)) {
                $values[$match[1]] = $match[2];
            } elseif (in_array($token, ['--worktree', '-w', '--service'], true) && $i + 1 < $count) {
                $values[$token === '--service' ? 'service' : 'worktree'] = $tokens[++$i];
            } elseif (preg_match('/^-w=?(.+)$/s', $token, $match)) {
                $values['worktree'] = $match[1];
            } else {
                break;
            }
        }

        return [$values['worktree'], $values['service'], array_slice($tokens, $i)];
    }

    /**
     * @return list<string>|null
     */
    private function rawTokens(): ?array
    {
        if (! $this->input instanceof ArgvInput) {
            return null;
        }
        // getRawTokens() is new in Symfony 7.1; Laravel 11 allows 7.0.
        $property = new \ReflectionProperty(ArgvInput::class, 'tokens');

        /** @var list<string> */
        return $property->getValue($this->input);
    }

    /**
     * The shell command: an alias (its words as configured) followed by the
     * given words, each quoted. A single word with spaces is a shell command
     * as it is.
     *
     * @param  list<string>  $words
     */
    private function commandFor(array $words): string
    {
        $aliases = config('worktrees.exec.aliases', []);
        $alias = is_array($aliases) && is_string($aliases[$words[0]] ?? null) ? $aliases[$words[0]] : null;
        if ($alias === null && count($words) === 1 && preg_match('/\s/', $words[0])) {
            return $words[0];
        }
        $rest = $alias === null ? $words : array_slice($words, 1);
        $prefix = $alias ?? array_shift($rest);
        $rest = $this->withTestProcesses((string) $prefix, $rest);

        return trim($prefix.' '.implode(' ', array_map(escapeshellarg(...), $rest)));
    }

    /**
     * `php artisan test --parallel` uses one process per CPU. On a shared agent
     * host `exec.test_processes` sets a smaller number, unless the call names one.
     *
     * @param  list<string>  $rest
     * @return list<string>
     */
    private function withTestProcesses(string $prefix, array $rest): array
    {
        $processes = config('worktrees.exec.test_processes');
        if (! is_numeric($processes) || (int) $processes < 1 || ! preg_match('/(^|\s)php\s+artisan\s+test(\s|$)/', $prefix.' ')) {
            return $rest;
        }
        $parallel = false;
        foreach ($rest as $word) {
            if (str_starts_with($word, '--processes')) {
                return $rest;
            }
            $parallel = $parallel || $word === '--parallel' || $word === '-p';
        }

        return $parallel ? [...$rest, '--processes='.(int) $processes] : $rest;
    }

    private function interactiveTerminal(): bool
    {
        return $this->input->isInteractive()
            && Process::isTtySupported()
            && defined('STDIN') && stream_isatty(STDIN)
            && defined('STDOUT') && stream_isatty(STDOUT);
    }
}
