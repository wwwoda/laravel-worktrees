<?php

namespace Woda\Worktrees;

use Closure;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

class DatabaseCloner
{
    public function __construct(
        private readonly string $strategy,
        private readonly bool $sqliteCopy,
        private readonly ?string $mysqlDockerContainer,
        private readonly ?string $pgsqlDockerContainer,
        private readonly string $dockerHost,
    ) {}

    private function dockerContainer(): ?string
    {
        return match ($this->resolveStrategy()) {
            'mysql' => $this->mysqlDockerContainer,
            'pgsql' => $this->pgsqlDockerContainer,
            default => null,
        };
    }

    /**
     * @param  (Closure(string, string): void)|null  $output
     */
    public function clone(string $worktreePath, string $suffix, ?Closure $output = null): void
    {
        $this->ensureDockerRunning();

        match ($this->resolveStrategy()) {
            'sqlite' => $this->cloneSqlite($worktreePath),
            'mysql' => $this->cloneMysql($suffix, $output),
            'pgsql' => $this->clonePgsql($suffix, $output),
            default => null,
        };
    }

    public function drop(string $suffix): void
    {
        $this->ensureDockerRunning();

        match ($this->resolveStrategy()) {
            'sqlite' => null, // SQLite DB lives in worktree, removed with it
            'mysql' => $this->dropMysql($suffix),
            'pgsql' => $this->dropPgsql($suffix),
            default => null,
        };
    }

    /**
     * @return list<string>
     */
    public function listCloned(): array
    {
        $this->ensureDockerRunning();

        return match ($this->resolveStrategy()) {
            'mysql' => $this->listMysqlClones(),
            'pgsql' => $this->listPgsqlClones(),
            default => [],
        };
    }

    public function resolveStrategy(): string
    {
        if ($this->strategy !== 'auto') {
            return $this->strategy;
        }

        return match (config('database.default')) {
            'sqlite' => 'sqlite',
            'mysql', 'mariadb' => 'mysql',
            'pgsql' => 'pgsql',
            default => 'none',
        };
    }

    /**
     * Return the source database name from the active connection.
     */
    public function sourceDatabase(): string
    {
        /** @var string $driver */
        $driver = config('database.default');

        /** @var string $db */
        $db = config("database.connections.{$driver}.database", '');

        return $db;
    }

    // ──────────────────────────────────────────────────────────────
    //  Docker + connection config helpers
    // ──────────────────────────────────────────────────────────────

    /**
     * Verify the docker container is running when one is configured.
     */
    private function ensureDockerRunning(): void
    {
        $container = $this->dockerContainer();

        if (! $container) {
            return;
        }

        $result = Process::timeout(5)->run(
            sprintf("docker ps --format '{{.Names}}' --filter name=%s", escapeshellarg($container)),
        );

        $running = array_filter(array_map(trim(...), explode("\n", $result->output())));

        if (! in_array($container, $running, true)) {
            throw new RuntimeException(
                "Docker container '{$container}' is not running. Start it with: docker start {$container}",
            );
        }
    }

    /**
     * @return array{host: string, port: string, database: string, username: string, password: string}
     */
    private function connectionConfig(): array
    {
        /** @var string $driver */
        $driver = config('database.default');

        /** @var string $host */
        $host = config("database.connections.{$driver}.host", '127.0.0.1');
        if ($this->dockerContainer()) {
            $host = $this->dockerHost;
        }

        /** @var string $port */
        $port = config("database.connections.{$driver}.port", '');
        /** @var string $database */
        $database = config("database.connections.{$driver}.database", '');
        /** @var string $username */
        $username = config("database.connections.{$driver}.username", '');
        /** @var string $password */
        $password = config("database.connections.{$driver}.password", '');

        return [
            'host' => $host,
            'port' => (string) $port,
            'database' => $database,
            'username' => $username,
            'password' => (string) $password,
        ];
    }

    /**
     * Build `-e KEY=VAL` flags for docker exec.
     *
     * @param  array<string, string>  $env
     */
    private function dockerEnvFlags(array $env): string
    {
        $flags = '';
        foreach ($env as $key => $value) {
            $flags .= sprintf(' -e %s=%s', $key, escapeshellarg($value));
        }

        return $flags;
    }

    /**
     * Wrap a command with `docker exec` when a container is configured.
     *
     * @param  array<string, string>  $env
     */
    private function wrapDocker(string $cmd, array $env = [], bool $interactive = false): string
    {
        $container = $this->dockerContainer();

        if (! $container) {
            return $cmd;
        }

        $iFlag = $interactive ? ' -i' : '';

        return sprintf(
            'docker exec%s%s %s %s',
            $iFlag,
            $this->dockerEnvFlags($env),
            escapeshellarg($container),
            $cmd,
        );
    }

    // ──────────────────────────────────────────────────────────────
    //  SQLite
    // ──────────────────────────────────────────────────────────────

    private function cloneSqlite(string $worktreePath): void
    {
        if (! $this->sqliteCopy) {
            return;
        }

        $sourcePath = database_path('database.sqlite');

        if (! File::exists($sourcePath)) {
            return;
        }

        $targetDir = $worktreePath.'/database';
        File::ensureDirectoryExists($targetDir);

        $targetPath = $targetDir.'/database.sqlite';
        File::copy($sourcePath, $targetPath);

        // Update .env in worktree to point to the copied database
        $envPath = $worktreePath.'/.env';
        if (File::exists($envPath)) {
            $envContent = File::get($envPath);
            $absoluteTarget = realpath($targetPath) ?: $targetPath;
            $envContent = (string) preg_replace(
                '/^DB_DATABASE=.*/m',
                'DB_DATABASE='.$absoluteTarget,
                $envContent,
            );
            File::put($envPath, $envContent);
        }
    }

    // ──────────────────────────────────────────────────────────────
    //  MySQL / MariaDB
    // ──────────────────────────────────────────────────────────────

    /**
     * Build credential flags for mysql/mysqldump CLI.
     */
    private function mysqlCredentialFlags(): string
    {
        $cfg = $this->connectionConfig();
        $flags = '';

        if ($cfg['username'] !== '') {
            $flags .= ' -u '.escapeshellarg($cfg['username']);
        }
        if ($cfg['host'] !== '') {
            $flags .= ' -h '.escapeshellarg($cfg['host']);
        }
        if ($cfg['port'] !== '') {
            $flags .= ' -P '.escapeshellarg($cfg['port']);
        }

        return $flags;
    }

    /**
     * Environment variables for mysql process (password).
     *
     * @return array<string, string>
     */
    private function mysqlEnv(): array
    {
        $cfg = $this->connectionConfig();

        return $cfg['password'] !== '' ? ['MYSQL_PWD' => $cfg['password']] : [];
    }

    /**
     * @param  (Closure(string, string): void)|null  $output
     */
    private function cloneMysql(string $suffix, ?Closure $output = null): void
    {
        $cfg = $this->connectionConfig();
        $sourceDb = $cfg['database'];
        $targetDb = $sourceDb.'_'.$suffix;
        $creds = $this->mysqlCredentialFlags();
        $env = $this->mysqlEnv();

        $this->mysqlExec("CREATE DATABASE IF NOT EXISTS `{$targetDb}`");

        $dumpCmd = sprintf('mysqldump --single-transaction%s %s', $creds, escapeshellarg($sourceDb));
        $restoreCmd = sprintf('mysql%s %s', $creds, escapeshellarg($targetDb));

        $dumpCmd = $this->wrapDocker($dumpCmd, $env);
        $restoreCmd = $this->wrapDocker($restoreCmd, $env, interactive: true);

        $result = Process::env($env)->timeout(300)->run("{$dumpCmd} | {$restoreCmd}", $output);

        if (! $result->successful()) {
            throw new RuntimeException("MySQL clone failed: {$result->errorOutput()}");
        }
    }

    private function dropMysql(string $suffix): void
    {
        $cfg = $this->connectionConfig();
        $targetDb = $cfg['database'].'_'.$suffix;

        $this->mysqlExec("DROP DATABASE IF EXISTS `{$targetDb}`");
    }

    /**
     * @return list<string>
     */
    private function listMysqlClones(): array
    {
        $cfg = $this->connectionConfig();
        $sourceDb = $cfg['database'];
        $creds = $this->mysqlCredentialFlags();
        $env = $this->mysqlEnv();

        $cmd = sprintf('mysql -N%s -e %s', $creds, escapeshellarg("SHOW DATABASES LIKE '{$sourceDb}_%'"));
        $cmd = $this->wrapDocker($cmd, $env);

        $result = Process::env($env)->timeout(10)->run($cmd);

        if (! $result->successful()) {
            return [];
        }

        return array_values(array_filter(array_map(trim(...), explode("\n", $result->output()))));
    }

    private function mysqlExec(string $sql): void
    {
        $creds = $this->mysqlCredentialFlags();
        $env = $this->mysqlEnv();

        $cmd = sprintf('mysql%s -e %s', $creds, escapeshellarg($sql));
        $cmd = $this->wrapDocker($cmd, $env);

        $result = Process::env($env)->timeout(30)->run($cmd);

        if (! $result->successful()) {
            throw new RuntimeException("MySQL command failed: {$result->errorOutput()}");
        }
    }

    // ──────────────────────────────────────────────────────────────
    //  PostgreSQL
    // ──────────────────────────────────────────────────────────────

    /**
     * Run a psql/createdb/dropdb command with credentials.
     *
     * @return \Illuminate\Contracts\Process\ProcessResult
     */
    private function pgsqlAdminExec(string $cmd, int $timeout = 30)
    {
        $cfg = $this->connectionConfig();
        $env = $cfg['password'] !== '' ? ['PGPASSWORD' => $cfg['password']] : [];

        $cmd = $this->wrapDocker($cmd, $env);

        return Process::env($env)->timeout($timeout)->run($cmd);
    }

    /**
     * Build common psql credential flags.
     */
    private function pgsqlCredentialFlags(): string
    {
        $cfg = $this->connectionConfig();
        $flags = '';

        if ($cfg['username'] !== '') {
            $flags .= ' -U '.escapeshellarg($cfg['username']);
        }
        if ($cfg['host'] !== '') {
            $flags .= ' -h '.escapeshellarg($cfg['host']);
        }
        if ($cfg['port'] !== '') {
            $flags .= ' -p '.escapeshellarg($cfg['port']);
        }

        return $flags;
    }

    /**
     * @param  (Closure(string, string): void)|null  $output
     */
    private function clonePgsql(string $suffix, ?Closure $output = null): void
    {
        $cfg = $this->connectionConfig();
        $sourceDb = $cfg['database'];
        $targetDb = $sourceDb.'_'.$suffix;
        $creds = $this->pgsqlCredentialFlags();
        $env = $cfg['password'] !== '' ? ['PGPASSWORD' => $cfg['password']] : [];

        // Create target database
        $createCmd = sprintf('createdb%s %s', $creds, escapeshellarg($targetDb));
        $result = $this->pgsqlAdminExec($createCmd);

        if (! $result->successful()) {
            // May already exist — try to continue; dump/restore will fail if real problem
            $err = $result->errorOutput();
            if (! str_contains($err, 'already exists')) {
                throw new RuntimeException("PostgreSQL createdb failed: {$err}");
            }
        }

        // pg_dump | psql (safer than createdb -T which needs no active connections)
        $dumpCmd = sprintf('pg_dump%s %s', $creds, escapeshellarg($sourceDb));
        $restoreCmd = sprintf('psql%s %s', $creds, escapeshellarg($targetDb));

        $dumpCmd = $this->wrapDocker($dumpCmd, $env);
        $restoreCmd = $this->wrapDocker($restoreCmd, $env, interactive: true);

        $result = Process::env($env)->timeout(300)->run("{$dumpCmd} | {$restoreCmd}", $output);

        if (! $result->successful()) {
            throw new RuntimeException("PostgreSQL clone failed: {$result->errorOutput()}");
        }
    }

    private function dropPgsql(string $suffix): void
    {
        $cfg = $this->connectionConfig();
        $targetDb = $cfg['database'].'_'.$suffix;
        $creds = $this->pgsqlCredentialFlags();

        $cmd = sprintf('dropdb --if-exists%s %s', $creds, escapeshellarg($targetDb));
        $result = $this->pgsqlAdminExec($cmd);

        if (! $result->successful()) {
            throw new RuntimeException("PostgreSQL dropdb failed: {$result->errorOutput()}");
        }
    }

    /**
     * @return list<string>
     */
    private function listPgsqlClones(): array
    {
        $cfg = $this->connectionConfig();
        $sourceDb = $cfg['database'];
        $creds = $this->pgsqlCredentialFlags();

        $sql = sprintf(
            "SELECT datname FROM pg_database WHERE datname LIKE '%s_%%' ORDER BY datname",
            $sourceDb,
        );
        $cmd = sprintf('psql%s -t -A -c %s %s', $creds, escapeshellarg($sql), escapeshellarg($sourceDb));
        $result = $this->pgsqlAdminExec($cmd, 10);

        if (! $result->successful()) {
            return [];
        }

        return array_values(array_filter(array_map(trim(...), explode("\n", $result->output()))));
    }
}
