<?php

namespace Woda\Worktrees\Commands;

use Illuminate\Console\Command;
use RuntimeException;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Process\Process;
use Woda\Worktrees\Agent\AppRunner;
use Woda\Worktrees\Agent\WorktreeInspector;
use Woda\Worktrees\Agent\WorktreeTarget;
use Woda\Worktrees\Commands\Concerns\ResolvesWorktree;

/**
 * Runs a command where the worktree's PHP runs. Everything after the command
 * name and this command's own options goes to the command unchanged.
 *
 * Put `--` before the command: artisan reads `-h`, `-V` and `-q` (and their
 * long forms) anywhere on the line before a `--`, and would show its help or
 * version instead of running the command. A call without `--` that holds one
 * of them is refused before artisan reads it (see refuseGlobalOptions()).
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

    /**
     * The options that artisan reads anywhere on the line before a `--`, and
     * which stop or silence the command: help, version, quiet. Short options
     * count with anything glued to them (`-hv` is `-h`).
     */
    private const GLOBAL_OPTIONS = ['-h', '-V', '-q', '--help', '--version', '--quiet', '--silent'];

    /**
     * The first word of a `worktree:exec` call that artisan would read as its
     * own option, because no `--` comes before it; null when there is none.
     *
     * @param  list<string>  $argv  the command line, `artisan` first
     */
    public static function globalOptionClash(array $argv): ?string
    {
        $tokens = array_slice($argv, 1);
        $i = 0;
        while ($i < count($tokens) && str_starts_with($tokens[$i], '-')) {
            $i++;
        }
        if (($tokens[$i] ?? null) !== 'worktree:exec') {
            return null;
        }
        foreach (array_slice($tokens, $i + 1) as $token) {
            if ($token === '--') {
                return null;
            }
            foreach (self::GLOBAL_OPTIONS as $option) {
                $long = str_starts_with($option, '--');
                if ($token === $option || ($long ? str_starts_with($token, $option.'=') : str_starts_with($token, $option))) {
                    return $token;
                }
            }
        }

        return null;
    }

    /**
     * Stops a `worktree:exec` call that artisan would answer with its help or
     * version (exit code 0) instead of running the command. Runs when artisan
     * starts, before it reads its options.
     *
     * @param  list<string>  $argv
     */
    public static function refuseGlobalOptions(array $argv): void
    {
        $token = self::globalOptionClash($argv);
        if ($token === null) {
            return;
        }
        fwrite(STDERR, "worktree:exec: artisan reads {$token} as its own option and would not run your command. Put -- before the command, for example: php artisan worktree:exec -- free -h\n");

        exit(self::INVALID);
    }

    public function handle(WorktreeInspector $inspector): int
    {
        [$worktree, $service, $words] = $this->invocation();
        if ($words === []) {
            $this->components->error('Give a command, for example: php artisan worktree:exec -- test --filter=UserTest');

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
        try {
            // A stack that an idle stop stopped starts again; the use keeps the next idle stop away.
            if ($inspector->wakeForUse($target, $service)) {
                $this->getOutput()->getErrorStyle()->writeln("Started the stopped stack of {$target->name}.");
            }
        } catch (RuntimeException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        // Under the shared stack lock, marked as used at the start and the end:
        // an idle stop waits until the command ends. A stop that comes between
        // the wake above and this lock finds the stack marked as used and skips it.
        return $inspector->inUse($target, fn (): int => $this->runIn($target, $runner, $service, $words));
    }

    /**
     * @param  list<string>  $words
     */
    private function runIn(WorktreeTarget $target, AppRunner $runner, ?string $service, array $words): int
    {
        $command = $this->commandFor($words);
        $tty = $this->interactiveTerminal();
        $process = $runner->process($target->path, 60)->forever();
        if ($tty) {
            $process->tty();
        } elseif (($stdin = $this->pipedInput()) !== null) {
            // `< dump.sql` and `cat x | …`: docker compose exec passes its stdin on.
            $process->input($stdin);
        }
        $commandLine = $runner->commandLine($command, $service, $tty);
        $result = $process->run($commandLine, $tty ? null : function (string $type, string $buffer): void {
            $type === 'err' ? $this->getOutput()->getErrorStyle()->write($buffer) : $this->output->write($buffer);
        });
        $exitCode = $result->exitCode() ?? 1;
        if ($exitCode !== 0 && $runner->usesCompose() && preg_match('/is not running|no container found|not running/i', $result->errorOutput())) {
            $this->components->warn('The container does not run. Start it with: php artisan worktree:doctor '.($target->main ? 'main ' : '').'--fix');
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

    /**
     * Artisan's stdin when it is a pipe or a file; null for a terminal and
     * for /dev/null.
     *
     * @return resource|null
     */
    protected function pipedInput(): mixed
    {
        if (! defined('STDIN') || stream_isatty(STDIN)) {
            return null;
        }
        $mode = fstat(STDIN)['mode'] ?? 0;
        $type = $mode & 0170000;

        // S_IFIFO (pipe) and S_IFREG (file).
        return in_array($type, [0010000, 0100000], true) ? STDIN : null;
    }

    private function interactiveTerminal(): bool
    {
        return $this->input->isInteractive()
            && Process::isTtySupported()
            && defined('STDIN') && stream_isatty(STDIN)
            && defined('STDOUT') && stream_isatty(STDOUT);
    }
}
