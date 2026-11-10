<?php

namespace Woda\Worktrees\Fast;

use Closure;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Woda\Worktrees\Shared\EnvFile;
use Woda\Worktrees\Shared\MainCheckout;

/**
 * A ready-made database with test data for new worktrees of a stack with
 * its own database service (the `sail` strategy).
 *
 * The dump is keyed by the worktree's migrations and seeders and the seed
 * command (ContentKey). A new worktree restores it into its empty database;
 * the migrations then find nothing to do. Without a dump for the key, the
 * dump is downloaded from `url` (the project's CI publishes it), else the
 * first worktree migrates, runs the seed command and saves its database as
 * the dump for the next ones.
 *
 * Dumps live in the main checkout's `storage/app/worktrees/dumps/{key}.sql.gz`.
 */
final readonly class DatabaseDump
{
    /**
     * @param  list<string>  $hashPaths
     */
    public function __construct(
        private bool $enabled,
        private string $service = 'mariadb',
        private array $hashPaths = ['database/migrations', 'database/seeders'],
        private string $seedCommand = 'php artisan db:seed --force',
        private string $dumpCommand = 'mariadb-dump -uroot -p"$MARIADB_ROOT_PASSWORD" --single-transaction --routines --triggers --skip-dump-date {database}',
        private string $restoreCommand = 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" {database}',
        private ?string $url = null,
        private ?string $urlHeader = null,
        private int $keep = 3,
    ) {}

    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function seedCommand(): string
    {
        return $this->seedCommand;
    }

    public function key(string $worktreePath): string
    {
        return ContentKey::of($worktreePath, $this->hashPaths, "seed:{$this->seedCommand}\n");
    }

    public function path(string $key): string
    {
        return MainCheckout::file('storage/app/worktrees/dumps/'.$key.'.sql.gz');
    }

    /**
     * The dump for the worktree's key: a local file, else a download, else null.
     *
     * @param  (Closure(string): void)|null  $onStep
     */
    public function find(string $worktreePath, ?Closure $onStep = null): ?string
    {
        $key = $this->key($worktreePath);
        $path = $this->path($key);
        if (is_file($path)) {
            return $path;
        }
        if ($this->url === null || $this->url === '') {
            return null;
        }

        return $this->download($key, $path, $onStep);
    }

    /**
     * Restore the dump into the worktree's database.
     *
     * @param  Closure(int): PendingProcess  $compose  a compose process in the worktree, by timeout
     * @param  (Closure(string, string): void)|null  $output
     */
    public function restore(string $dump, string $worktreePath, Closure $compose, ?Closure $output = null): void
    {
        $command = sprintf(
            'gunzip -t %1$s && gunzip -c %1$s | docker compose exec -T %2$s sh -c %3$s',
            escapeshellarg($dump),
            escapeshellarg($this->service),
            escapeshellarg($this->command($this->restoreCommand, $worktreePath)),
        );
        $result = $compose(900)->run($command, $output);
        if (! $result->successful()) {
            throw new RuntimeException('Restoring the database dump failed: '.trim($result->errorOutput()));
        }
    }

    /**
     * Save the worktree's database as the dump of its key, for the next worktrees.
     *
     * @param  Closure(int): PendingProcess  $compose
     */
    public function save(string $worktreePath, Closure $compose): string
    {
        $key = $this->key($worktreePath);
        $target = $this->path($key);
        File::ensureDirectoryExists(dirname($target));
        $sql = $target.'.tmp-'.bin2hex(random_bytes(4));
        try {
            $result = $compose(900)->run(sprintf(
                'docker compose exec -T %s sh -c %s > %s',
                escapeshellarg($this->service),
                escapeshellarg($this->command($this->dumpCommand, $worktreePath)),
                escapeshellarg($sql),
            ));
            if (! $result->successful() || ! is_file($sql) || filesize($sql) === 0) {
                throw new RuntimeException('Saving the database dump failed: '.trim($result->errorOutput()));
            }
            $gzip = Process::timeout(600)->run(['gzip', '-1', '-f', $sql]);
            if (! $gzip->successful()) {
                throw new RuntimeException('Compressing the database dump failed: '.trim($gzip->errorOutput()));
            }
            rename($sql.'.gz', $target);
        } finally {
            File::delete([$sql, $sql.'.gz']);
        }
        $this->prune();

        return $target;
    }

    private function command(string $template, string $worktreePath): string
    {
        $database = EnvFile::resolved($worktreePath.'/.env', 'DB_DATABASE');
        if ($database === null || $database === '') {
            throw new RuntimeException('The worktree\'s .env has no DB_DATABASE: the dump does not know the database.');
        }

        return str_replace('{database}', escapeshellarg($database), $template);
    }

    /**
     * @param  (Closure(string): void)|null  $onStep
     */
    private function download(string $key, string $path, ?Closure $onStep): ?string
    {
        $url = str_replace('{key}', $key, (string) $this->url);
        $onStep && $onStep("Downloading the database dump {$key}...");
        File::ensureDirectoryExists(dirname($path));
        $temporary = $path.'.download-'.bin2hex(random_bytes(4));
        $command = ['curl', '-fsSL', '--retry', '2', '-o', $temporary];
        if ($this->urlHeader !== null && $this->urlHeader !== '') {
            array_push($command, '-H', $this->urlHeader);
        }
        $command[] = $url;
        $result = Process::timeout(600)->run($command);
        // A missing dump (404) is normal for a new migration: the setup seeds and saves one.
        if (! $result->successful() || ! Process::timeout(120)->run(['gunzip', '-t', $temporary])->successful()) {
            File::delete($temporary);
            // The URL may hold a token: name the key only.
            $onStep && $onStep("No database dump {$key} to download; the setup seeds the database.");

            return null;
        }
        rename($temporary, $path);

        return $path;
    }

    private function prune(): void
    {
        $dumps = glob(MainCheckout::file('storage/app/worktrees/dumps/*.sql.gz')) ?: [];
        if (count($dumps) <= $this->keep) {
            return;
        }
        usort($dumps, static fn (string $a, string $b): int => (int) filemtime($b) <=> (int) filemtime($a));
        File::delete(array_slice($dumps, $this->keep));
    }
}
