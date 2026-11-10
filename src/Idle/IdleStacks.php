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
 * checkout, an edited file), when a log file was written, or when it was
 * woken. The traffic of the last check is kept in the worktree's
 * `storage/app/worktree/idle.json`, so the first check of a stack never stops it.
 */
final readonly class IdleStacks
{
    private const STATE = 'storage/app/worktree/idle.json';

    private const WOKEN = 'storage/app/worktree/woken';

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
     * The running containers of the checkout's compose project, by service.
     *
     * @return array<string, string> service => container name
     */
    public function running(string $path): array
    {
        $result = ComposeProcess::in($path, 30)->run('docker compose ps --format json');
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
     * Bytes in and out of each container so far, by container name; one call for all.
     *
     * @param  list<string>  $containers
     * @return array<string, int>
     */
    public function traffic(array $containers): array
    {
        if ($containers === []) {
            return [];
        }
        $result = Process::timeout(60)->run('docker stats --no-stream --format '.escapeshellarg('{{.Name}}|{{.NetIO}}').' '.implode(' ', array_map(escapeshellarg(...), $containers)));
        $traffic = [];
        foreach (preg_split('/\r?\n/', trim($result->output())) ?: [] as $line) {
            [$name, $io] = array_pad(explode('|', $line, 2), 2, '');
            $bytes = 0;
            foreach (explode('/', $io) as $part) {
                $bytes += self::bytes(trim($part)) ?? 0;
            }
            if ($name !== '') {
                $traffic[$name] = $bytes;
            }
        }

        return $traffic;
    }

    /**
     * When the checkout was last used (Unix time). Records the traffic for the next check.
     */
    public function lastActivity(string $path, ?int $traffic, int $now): int
    {
        $state = $this->state($path);
        if ($traffic !== null && $traffic !== ($state['traffic'] ?? null)) {
            $state = ['traffic' => $traffic, 'changed_at' => $now];
            $this->saveState($path, $state);
        }
        $times = [is_int($state['changed_at'] ?? null) ? $state['changed_at'] : $now];
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
        $status = Process::path($path)->timeout(10)->run('git status --porcelain --untracked-files=all');
        foreach (preg_split('/\r?\n/', rtrim($status->output())) ?: [] as $line) {
            $file = $path.'/'.trim(substr($line, 3), '"');
            if (strlen($line) > 3 && file_exists($file)) {
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
     * Start the stack (the services of its active profiles) and wait until it runs and is healthy.
     *
     * @param  (\Closure(string, string): void)|null  $output
     */
    public function wake(string $path, ?\Closure $output = null): ProcessResult
    {
        $result = ComposeProcess::in($path, 600)->run('docker compose up -d --wait', $output);
        if ($result->successful()) {
            File::ensureDirectoryExists(dirname($path.'/'.self::WOKEN));
            File::put($path.'/'.self::WOKEN, date(DATE_ATOM)."\n");
        }

        return $result;
    }

    /**
     * @return array{traffic?: int, changed_at?: int}
     */
    private function state(string $path): array
    {
        $file = $path.'/'.self::STATE;
        $decoded = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        $state = [];
        if (is_array($decoded) && is_int($decoded['traffic'] ?? null) && is_int($decoded['changed_at'] ?? null)) {
            $state = ['traffic' => $decoded['traffic'], 'changed_at' => $decoded['changed_at']];
        }

        return $state;
    }

    /**
     * @param  array{traffic: int, changed_at: int}  $state
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
