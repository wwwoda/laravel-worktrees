<?php

namespace Woda\Worktrees\Agent;

use Closure;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;
use Woda\Worktrees\ComposeProcess;
use Woda\Worktrees\Shared\EnvFile;
use Woda\Worktrees\Shared\MainCheckout;
use Woda\Worktrees\WorktreeManager;

/**
 * Collects what agent commands report about a checkout: git state, URL,
 * containers, compose profiles, database state and recent log errors.
 */
final readonly class WorktreeInspector
{
    public function __construct(
        private WorktreeManager $manager,
        private AppRunner $runner,
    ) {}

    public function runner(): AppRunner
    {
        return $this->runner;
    }

    public function manager(): WorktreeManager
    {
        return $this->manager;
    }

    /**
     * The compose stack of the checkout; null for the `native` strategy.
     */
    public function stack(string $path): ?ComposeStack
    {
        return $this->runner->usesCompose() ? new ComposeStack($path, $this->runner->appService()) : null;
    }

    /**
     * Why a mutating command must not act on the target's containers: a
     * checkout whose compose project is another checkout's (the main checkout's,
     * or one whose containers carry the project name) would act on that
     * checkout's containers. Null when that is not the case.
     */
    public function ownStackProblem(WorktreeTarget $target): ?string
    {
        $stack = $this->stack($target->path);
        if ($stack === null) {
            return null;
        }
        try {
            if (! $target->main) {
                $stack->assertOwnProject(new ComposeStack(MainCheckout::path(), $stack->appService));
            }
            $owner = $stack->foreignOwner();
        } catch (RuntimeException $error) {
            return $error->getMessage();
        }

        return $owner === null ? null : "The compose project '{$stack->projectName()}' belongs to the containers of {$owner}: acting on it here would change that checkout's containers. Give each checkout its own COMPOSE_PROJECT_NAME (worktrees.env_overrides).";
    }

    public function assertOwnStack(WorktreeTarget $target): void
    {
        $problem = $this->ownStackProblem($target);
        if ($problem !== null) {
            throw new RuntimeException($problem);
        }
    }

    /**
     * Before a command runs in a worktree's container: start its stack when
     * the service does not run (an idle stop or a reboot stopped it), and mark
     * the stack as used, both under the stack lock. The main checkout is
     * never started here. True when the stack was started.
     *
     * @param  (Closure(string, string): void)|null  $output
     */
    public function wakeForUse(WorktreeTarget $target, ?string $service = null, ?Closure $output = null): bool
    {
        $stack = $this->stack($target->path);
        if ($stack === null || $target->main) {
            return false;
        }

        return StackLock::locked($target->path, static function () use ($stack, $target, $service, $output): bool {
            $started = false;
            if (! $stack->isRunning($service ?? $stack->appService)) {
                $result = ComposeProcess::in($target->path, 600)->run('docker compose up -d --wait', $output);
                if (! $result->successful()) {
                    throw new RuntimeException('Starting the stopped stack failed (docker compose up -d --wait): '.trim($result->errorOutput() ?: $result->output()));
                }
                $started = true;
            }
            StackLock::markUsed($target->path);

            return $started;
        });
    }

    /**
     * Runs a command's work in the target's stack with the stack lock held
     * shared, so an idle stop (exclusive) waits until it ends; the use is
     * marked at the start and the end. The main checkout's use is not marked
     * (it is never stopped as idle). Without compose (native), it only runs.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function inUse(WorktreeTarget $target, Closure $callback): mixed
    {
        if ($this->stack($target->path) === null) {
            return $callback();
        }

        return StackLock::shared($target->path, $callback, ! $target->main);
    }

    public function url(string $path): ?string
    {
        $url = EnvFile::resolved($path.'/.env', 'APP_URL');

        return $url === null || $url === '' ? null : $url;
    }

    /**
     * The state of the default database as `php artisan migrate:status` in the
     * app container (or on the host) sees it:
     * `ok` (all migrations ran), `pending`, `empty` (no migrations table) or
     * `unknown` (the command failed; `message` says why).
     *
     * @return array{state: string, ran: int, pending: int, message: string}
     */
    public function migrations(string $path): array
    {
        try {
            $result = $this->runner->run($path, 'php artisan migrate:status --no-ansi --no-interaction', 60);
        } catch (Throwable $error) {
            return ['state' => 'unknown', 'ran' => 0, 'pending' => 0, 'message' => $error->getMessage()];
        }
        $output = $result->output()."\n".$result->errorOutput();
        if (! $result->successful()) {
            $empty = stripos($output, 'Migration table not found') !== false;

            return [
                'state' => $empty ? 'empty' : 'unknown',
                'ran' => 0,
                'pending' => 0,
                'message' => $empty ? 'No migrations table: the database is empty.' : self::firstLine($output),
            ];
        }
        $ran = preg_match_all('/\sRan\s*$/m', $result->output());
        $pending = preg_match_all('/\sPending\s*$/m', $result->output());

        return [
            'state' => $pending > 0 ? 'pending' : 'ok',
            'ran' => (int) $ran,
            'pending' => (int) $pending,
            'message' => $pending > 0 ? "{$pending} pending migrations" : "{$ran} migrations ran",
        ];
    }

    /**
     * When the code of the checkout last changed: the last move of HEAD
     * (commit, checkout, pull, reset) or the newest uncommitted file.
     */
    public function lastCodeChange(string $path): int
    {
        $times = [];
        $result = Process::path($path)->timeout(10)->run('git rev-parse --path-format=absolute --git-path logs/HEAD');
        $reflog = trim($result->output());
        if ($result->successful() && $reflog !== '' && is_file($reflog)) {
            $times[] = (int) filemtime($reflog);
        }
        // Every file, also inside untracked folders: a folder's time does not change when a file in it is edited.
        $status = Process::path($path)->timeout(10)->run("git status --porcelain --untracked-files=all -- . ':(exclude)storage/worktree-bootstrap.*'");
        if ($status->successful()) {
            foreach (preg_split('/\r?\n/', rtrim($status->output())) ?: [] as $line) {
                if (strlen($line) <= 3) {
                    continue;
                }
                $file = substr($line, 3);
                $file = trim(str_contains($file, ' -> ') ? substr($file, (int) strrpos($file, ' -> ') + 4) : $file, '"');
                $full = $path.'/'.$file;
                // A deleted file changed its folder (or, with the folder gone, the closest one that is left).
                while (! file_exists($full) && $full !== $path && dirname($full) !== $full) {
                    $full = dirname($full);
                }
                if (file_exists($full)) {
                    $times[] = (int) filemtime($full);
                }
            }
        }

        return $times === [] ? 0 : max($times);
    }

    /**
     * The last error lines of the newest `storage/logs/laravel*.log`, oldest first.
     *
     * @return list<string>
     */
    public function recentErrors(string $path, int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }
        $logs = glob($path.'/storage/logs/laravel*.log') ?: [];
        usort($logs, static fn (string $a, string $b): int => (int) filemtime($b) <=> (int) filemtime($a));
        $log = $logs[0] ?? null;
        if ($log === null) {
            return [];
        }
        $tail = self::tail($log, 256 * 1024);
        preg_match_all('/^\[([^\]]+)\]\s+[\w-]+\.(ERROR|CRITICAL|ALERT|EMERGENCY):\s*(.*)$/m', $tail, $matches, PREG_SET_ORDER);
        $errors = [];
        foreach (array_slice($matches, -$limit) as $match) {
            $message = trim($match[3]);
            // The context JSON and stack traces are long; the start says what failed.
            $message = (string) preg_replace('/\s+\{"(exception|userId)".*$/', '', $message);
            $errors[] = '['.$match[1].'] '.$match[2].': '.mb_strimwidth($message, 0, 200, '…');
        }

        return $errors;
    }

    /**
     * The main checkout's `APP_URL`; a worktree with the same URL serves the main checkout.
     */
    public function mainUrl(): ?string
    {
        return $this->url(MainCheckout::path());
    }

    /**
     * Everything `worktree:status` and `worktree:context` show.
     *
     * @return array<string, mixed>
     */
    public function status(WorktreeTarget $target, bool $memory = true, bool $database = true): array
    {
        $status = [
            'name' => $target->name,
            'path' => $target->path,
            'main' => $target->main,
            'branch' => $target->branch,
            'head' => substr($target->head, 0, 12),
            'changes' => null,
            'url' => $this->url($target->path),
            'setup' => $target->setupStatus(),
            'compose_project' => null,
            'services' => [],
            'profiles' => null,
            'database' => null,
            'errors' => [],
        ];
        try {
            $status['changes'] = count($this->manager->changedFiles($target->path));
        } catch (RuntimeException $error) {
            $status['errors'][] = $error->getMessage();
        }
        $stack = $this->stack($target->path);
        $appRunning = $stack === null;
        if ($stack !== null) {
            try {
                $status['compose_project'] = $stack->projectName();
                $status['profiles'] = ['active' => $stack->activeProfiles(), 'available' => $stack->availableProfiles()];
                $containers = $stack->containers();
                $running = array_values(array_map(static fn (array $c): string => $c['name'], array_filter($containers, static fn (array $c): bool => $c['state'] === 'running')));
                $usage = $memory ? $stack->memory($running) : [];
                foreach ($containers as $container) {
                    $status['services'][] = [...$container, 'memory' => $usage[$container['name']] ?? null];
                    if ($container['service'] === $stack->appService && $container['state'] === 'running') {
                        $appRunning = true;
                    }
                }
            } catch (RuntimeException $error) {
                $status['errors'][] = $error->getMessage();
            }
        }
        if ($database) {
            $status['database'] = $appRunning
                ? $this->migrations($target->path)
                : ['state' => 'unknown', 'ran' => 0, 'pending' => 0, 'message' => 'The app container does not run.'];
        }

        return $status;
    }

    public static function formatBytes(?int $bytes): string
    {
        if ($bytes === null) {
            return '-';
        }

        return $bytes >= 1024 ** 3
            ? number_format($bytes / 1024 ** 3, 1).' GB'
            : number_format($bytes / 1024 ** 2, 0).' MB';
    }

    private static function firstLine(string $text): string
    {
        foreach (preg_split('/\r?\n/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '') {
                return mb_strimwidth($line, 0, 200, '…');
            }
        }

        return 'no output';
    }

    private static function tail(string $file, int $bytes): string
    {
        $size = (int) filesize($file);
        $handle = fopen($file, 'r');
        if ($handle === false) {
            return '';
        }
        fseek($handle, max(0, $size - $bytes));
        $content = stream_get_contents($handle);
        fclose($handle);

        return is_string($content) ? $content : '';
    }
}
