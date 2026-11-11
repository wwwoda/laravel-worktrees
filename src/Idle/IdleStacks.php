<?php

namespace Woda\Worktrees\Idle;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Woda\Worktrees\ComposeProcess;
use Woda\Worktrees\WorktreeManager;

/**
 * Finds worktree stacks that nobody used for a while and stops them; starts
 * them again on request.
 *
 * A stack counts as used when the network traffic of its app container
 * changed (any HTTP request does that), when its code changed (a commit, a
 * checkout, an edited or deleted file), when a log file was written, or when it was
 * woken. The traffic of the last check is kept in the worktree's
 * `storage/app/worktree/idle.json`, so the first check of a stack never stops it.
 * A stack whose app container does not run counts as idle from its first check.
 */
final readonly class IdleStacks
{
    private const STATE = 'storage/app/worktree/idle.json';

    private const WOKEN = 'storage/app/worktree/woken';

    // Held by a wake and by the stop of an idle stack, so a stop cannot follow a wake unseen.
    private const LOCK = 'storage/app/worktree/idle.lock';

    // The package's own state files (idle.json, woken, the lock) are no use of the stack.
    // --no-optional-locks: a status from the timer must not take index.lock while an agent commits.
    private const STATUS_COMMAND = "git --no-optional-locks status --porcelain -z --untracked-files=all -- . ':(exclude)storage/app/worktree'";

    public function __construct(
        private WorktreeManager $manager,
        private string $appService,
    ) {}

    /**
     * The registered checkouts: path, name and whether it is the main checkout.
     *
     * @return list<array{path: string, name: string, main: bool}>
     */
    public function checkouts(bool $includeMain): array
    {
        $checkouts = [];
        foreach ($this->manager->registered() as $index => $worktree) {
            $path = is_string($worktree['path'] ?? null) ? $worktree['path'] : '';
            if ($path === '' || ! is_dir($path) || ($index === 0 && ! $includeMain)) {
                continue;
            }
            $record = $path.'/storage/worktree-bootstrap.json';
            $decoded = is_file($record) ? json_decode((string) file_get_contents($record), true) : null;
            $name = is_array($decoded) && is_string($decoded['name'] ?? null) ? $decoded['name'] : null;
            $checkouts[] = [
                'path' => $path,
                'name' => $name ?? ($index === 0 ? 'main' : ($this->manager->nameFromPath($path) ?? basename($path))),
                'main' => $index === 0,
            ];
        }

        return $checkouts;
    }

    /**
     * The compose project of a checkout, as compose resolves it from its `.env`.
     */
    public function project(string $path): ?string
    {
        $result = ComposeProcess::in($path, 60)->run('docker compose config --format json');
        $config = $result->successful() ? json_decode($result->output(), true) : null;

        return is_array($config) && is_string($config['name'] ?? null) && $config['name'] !== '' ? $config['name'] : null;
    }

    /**
     * The running containers of the checkout's compose project, by service;
     * null when compose cannot tell (no daemon, unreadable output).
     *
     * @return array<string, string>|null service => container name
     */
    public function running(string $path): ?array
    {
        $result = ComposeProcess::in($path, 30)->run('docker compose ps --format json');
        if (! $result->successful()) {
            return null;
        }
        $running = [];
        foreach (preg_split('/\r?\n/', trim($result->output())) ?: [] as $line) {
            $rows = json_decode($line, true);
            // Compose 2.21 and later print one object per line; earlier ones one array.
            foreach (is_array($rows) && array_is_list($rows) ? $rows : [$rows] as $row) {
                if (is_array($row) && is_string($row['State'] ?? null) && strtolower($row['State']) === 'running' && is_string($row['Service'] ?? null) && is_string($row['Name'] ?? null)) {
                    $running[$row['Service']] = $row['Name'];
                }
            }
        }

        return $running;
    }

    public function appService(): string
    {
        return $this->appService;
    }

    /**
     * Bytes that each container sent so far, by container name. The exact
     * counters of `/proc/net/dev` in the container; `docker stats` (rounded to
     * three digits, so 1 GB moves in 10 MB steps) only where that fails.
     *
     * @param  list<string>  $containers
     * @return array<string, int>
     */
    public function traffic(array $containers): array
    {
        $traffic = [];
        $rest = [];
        foreach ($containers as $container) {
            $result = Process::timeout(30)->run('docker exec '.escapeshellarg($container).' cat /proc/net/dev');
            $bytes = $result->successful() ? self::netDevBytes($result->output()) : null;
            if ($bytes === null) {
                $rest[] = $container;
            } else {
                $traffic[$container] = $bytes;
            }
        }
        if ($rest === []) {
            return $traffic;
        }
        $result = Process::timeout(60)->run('docker stats --no-stream --format '.escapeshellarg('{{.Name}}|{{.NetIO}}').' '.implode(' ', array_map(escapeshellarg(...), $rest)));
        foreach (preg_split('/\r?\n/', trim($result->output())) ?: [] as $line) {
            [$name, $io] = array_pad(explode('|', $line, 2), 2, '');
            // `received / sent`: only what the container sent.
            $bytes = self::bytes(trim(explode('/', $io)[1] ?? '')) ?? 0;
            if (in_array($name, $rest, true)) {
                $traffic[$name] = $bytes;
            }
        }

        return $traffic;
    }

    /**
     * Sent bytes of all interfaces but `lo`; null without an interface line.
     * Received bytes do not count: ARP broadcasts and other containers'
     * broadcasts arrive at an idle container too. A request is answered, so
     * the container sends.
     */
    private static function netDevBytes(string $output): ?int
    {
        $bytes = null;
        foreach (preg_split('/\r?\n/', $output) ?: [] as $line) {
            if (! preg_match('/^\s*([^:\s]+):\s*(.*)$/', $line, $match) || $match[1] === 'lo') {
                continue;
            }
            $fields = preg_split('/\s+/', trim($match[2])) ?: [];
            if (count($fields) >= 9 && ctype_digit($fields[0]) && ctype_digit($fields[8])) {
                $bytes = ($bytes ?? 0) + (int) $fields[8];
            }
        }

        return $bytes;
    }

    /**
     * When the checkout was last used (Unix time). Records the traffic for the next check.
     */
    public function lastActivity(string $path, ?int $traffic, int $now): int
    {
        $saved = $this->state($path);
        $state = $saved;
        // A new reading counts as use. Without the app container, the first check
        // is recorded (traffic null), so the other services become idle from then.
        if (! isset($state['changed_at']) || ($traffic !== null && $traffic !== ($state['traffic'] ?? null))) {
            $state = ['traffic' => $traffic, 'changed_at' => $now] + $state;
        }
        $times = [$state['changed_at']];
        foreach ([self::WOKEN, ...(glob($path.'/storage/logs/*.log') ?: [])] as $file) {
            $file = str_starts_with($file, '/') ? $file : $path.'/'.$file;
            if (is_file($file)) {
                $times[] = (int) filemtime($file);
            }
        }
        $reflog = Process::path($path)->timeout(10)->run('git rev-parse --path-format=absolute --git-path logs/HEAD');
        if ($reflog->successful() && is_file($head = trim($reflog->output()))) {
            $times[] = (int) filemtime($head);
        }
        // -z: paths as they are (no quotes, no escapes); a rename or copy gives the new path, then the old one.
        $status = Process::path($path)->timeout(10)->run(self::STATUS_COMMAND);
        // A changed status counts as use: a deleted file has no time of its own.
        // The first snapshot only records it.
        if ($status->successful() && ($hash = sha1($status->output())) !== ($state['status'] ?? null)) {
            $state['status_changed_at'] = isset($state['status']) ? $now : $state['changed_at'];
            $state['status'] = $hash;
        }
        $times[] = $state['status_changed_at'] ?? $state['changed_at'];
        if ($state !== $saved) {
            $this->saveState($path, $state);
        }
        $entries = explode("\0", $status->output());
        for ($i = 0; $i < count($entries); $i++) {
            $entry = $entries[$i];
            if (strlen($entry) < 4) {
                continue;
            }
            if (str_contains('RC', $entry[0]) || str_contains('RC', $entry[1])) {
                $i++;
            }
            $file = $path.'/'.substr($entry, 3);
            if (file_exists($file)) {
                $times[] = (int) filemtime($file);
            }
        }

        return max($times);
    }

    /**
     * `docker compose stop` for every profile: the containers stay, so a start
     * (or Sablier) brings them back in seconds with their data.
     */
    public function stop(string $path): ProcessResult
    {
        return ComposeProcess::in($path, 300)->run('docker compose --profile '.escapeshellarg('*').' stop');
    }

    /**
     * Stop the stack unless it was woken at or after `$checkedAt`, under the stack
     * lock: a wake that runs now finishes first, and its time is then seen.
     * Null: woken, not stopped.
     */
    public function stopUnlessWoken(string $path, int $checkedAt): ?ProcessResult
    {
        return $this->locked($path, function () use ($path, $checkedAt): ?ProcessResult {
            clearstatcache(true, $path.'/'.self::WOKEN);
            if (is_file($path.'/'.self::WOKEN) && (int) filemtime($path.'/'.self::WOKEN) >= $checkedAt) {
                return null;
            }

            return $this->stop($path);
        });
    }

    /**
     * Start the stack (the services of its active profiles) and wait until it runs and is healthy.
     *
     * @param  (\Closure(string, string): void)|null  $output
     */
    public function wake(string $path, ?\Closure $output = null): ProcessResult
    {
        return $this->locked($path, function () use ($path, $output): ProcessResult {
            $result = ComposeProcess::in($path, 600)->run('docker compose up -d --wait', $output);
            if ($result->successful()) {
                File::put($path.'/'.self::WOKEN, date(DATE_ATOM)."\n");
            }

            return $result;
        });
    }

    /**
     * @template T
     *
     * @param  \Closure(): T  $callback
     * @return T
     */
    private function locked(string $path, \Closure $callback): mixed
    {
        File::ensureDirectoryExists(dirname($path.'/'.self::LOCK));
        $handle = fopen($path.'/'.self::LOCK, 'c');
        if ($handle === false) {
            throw new \RuntimeException("Cannot open the stack lock of {$path}.");
        }
        try {
            flock($handle, LOCK_EX);

            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * The traffic of the last check (null: no app container then) and when it changed,
     * and a hash of the git status and when it changed; empty before the first check.
     *
     * @return array{traffic?: int|null, changed_at?: int, status?: string, status_changed_at?: int}
     */
    private function state(string $path): array
    {
        $file = $path.'/'.self::STATE;
        $decoded = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        $traffic = is_array($decoded) ? ($decoded['traffic'] ?? null) : null;
        if (! is_array($decoded) || ! is_int($decoded['changed_at'] ?? null) || ($traffic !== null && ! is_int($traffic))) {
            return [];
        }
        $state = ['traffic' => $traffic, 'changed_at' => $decoded['changed_at']];
        if (is_string($decoded['status'] ?? null) && is_int($decoded['status_changed_at'] ?? null)) {
            $state['status'] = $decoded['status'];
            $state['status_changed_at'] = $decoded['status_changed_at'];
        }

        return $state;
    }

    /**
     * @param  array{traffic?: int|null, changed_at?: int, status?: string, status_changed_at?: int}  $state
     */
    private function saveState(string $path, array $state): void
    {
        File::ensureDirectoryExists(dirname($path.'/'.self::STATE));
        File::put($path.'/'.self::STATE, (string) json_encode($state));
    }

    /**
     * `1.2kB`, `3.4MiB`, `0B` as bytes.
     */
    public static function bytes(string $value): ?int
    {
        if (! preg_match('/^([\d.]+)\s*([kKMGT]?i?B)$/', $value, $match)) {
            return null;
        }
        $unit = strtolower($match[2]);
        $power = ['b' => 0, 'kb' => 1, 'kib' => 1, 'mb' => 2, 'mib' => 2, 'gb' => 3, 'gib' => 3, 'tb' => 4, 'tib' => 4][$unit] ?? 0;

        return (int) round((float) $match[1] * (str_contains($unit, 'i') ? 1024 : 1000) ** $power);
    }
}
