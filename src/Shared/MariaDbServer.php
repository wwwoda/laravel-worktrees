<?php

namespace Woda\Worktrees\Shared;

use Closure;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * MariaDB and MySQL through their command line clients, either inside the
 * server's Docker container (`docker exec`) or directly against host and port.
 * Passwords travel in MYSQL_PWD and SQL on stdin, never on the command line.
 */
final readonly class MariaDbServer implements DatabaseServer
{
    public function __construct(
        private string $driver,
        private ?string $container,
        private string $host,
        private int $port,
        private string $adminUser,
        private string $adminPassword,
        private string $client = 'mariadb',
        private string $dumpClient = 'mariadb-dump',
    ) {}

    public function driver(): string
    {
        return $this->driver;
    }

    public function requiresDatabasesInAdvance(): bool
    {
        return false;
    }

    public function finishTemplateBuild(string $builder, string $template): void
    {
        $this->dropUser($builder);
    }

    public function ping(): bool
    {
        return $this->run($this->adminUser, $this->adminPassword, 'SELECT 1', timeout: 10)->successful;
    }

    public function databaseExists(string $database): bool
    {
        return in_array(Identifier::check($database), $this->listDatabases(), true);
    }

    public function databasesStartingWith(string $prefix): array
    {
        return array_values(array_filter(
            $this->listDatabases(),
            static fn (string $name): bool => str_starts_with($name, $prefix),
        ));
    }

    public function createDatabase(string $database, ?string $owner = null): void
    {
        $this->admin('CREATE DATABASE IF NOT EXISTS `'.Identifier::check($database).'`');
    }

    public function copyDatabase(string $source, string $target, ?string $owner = null, ?Closure $output = null): void
    {
        Identifier::check($source);
        Identifier::check($target);
        if ($this->databaseExists($target)) {
            throw new RuntimeException("Database '{$target}' exists already; it is not overwritten.");
        }
        $this->createDatabase($target);

        $credentials = '-u '.escapeshellarg($this->adminUser).$this->hostFlags();
        $script = sprintf(
            'f=$(mktemp) && %s %s --single-transaction --routines --triggers --events %s > "$f" && %s %s %s < "$f"; s=$?; rm -f "$f"; exit $s',
            $this->dumpClient, $credentials, escapeshellarg($source),
            $this->client, $credentials, escapeshellarg($target),
        );
        $result = Process::env(['MYSQL_PWD' => $this->adminPassword])->timeout(1800)
            ->run($this->wrap('sh -c '.escapeshellarg($script)), $output);

        if (! $result->successful()) {
            // The target did not exist before this copy: remove the partial one, so
            // the next run copies again instead of keeping an incomplete database.
            $this->dropDatabase($target);

            throw new RuntimeException("Copying '{$source}' to '{$target}' failed: {$result->errorOutput()}");
        }
    }

    public function dropDatabase(string $database): void
    {
        $this->admin('DROP DATABASE IF EXISTS `'.Identifier::check($database).'`');
    }

    public function grantUser(string $user, string $password, array $databases): void
    {
        $account = $this->account($user);
        $password = Identifier::password($password);
        $sql = "CREATE USER IF NOT EXISTS {$account} IDENTIFIED BY '{$password}';\n"
            ."ALTER USER {$account} IDENTIFIED BY '{$password}';\n";
        foreach ($databases as $database) {
            // In GRANT, `_` and `%` are wildcards even inside backticks: escape them
            // so the right covers this exact name only.
            $exact = addcslashes(Identifier::check($database), '_%');
            $sql .= "GRANT ALL PRIVILEGES ON `{$exact}`.* TO {$account};\n";
        }
        $this->admin($sql);
    }

    public function userExists(string $user): bool
    {
        $result = $this->run($this->adminUser, $this->adminPassword,
            "SELECT COUNT(*) FROM mysql.user WHERE User = '".Identifier::check($user)."'");
        if (! $result->successful) {
            throw new RuntimeException("Cannot read users: {$result->error}");
        }

        return trim($result->output) !== '0';
    }

    public function dropUser(string $user): void
    {
        $this->admin('DROP USER IF EXISTS '.$this->account($user));
    }

    public function runAs(string $user, string $password, string $sql, ?string $database = null): ServerResult
    {
        return $this->run($user, $password, $sql, $database);
    }

    /**
     * @return list<string>
     */
    private function listDatabases(): array
    {
        $result = $this->run($this->adminUser, $this->adminPassword, 'SHOW DATABASES');
        if (! $result->successful) {
            throw new RuntimeException("Cannot list databases: {$result->error}");
        }

        return array_values(array_filter(array_map(trim(...), explode("\n", $result->output))));
    }

    private function admin(string $sql): void
    {
        $result = $this->run($this->adminUser, $this->adminPassword, $sql);
        if (! $result->successful) {
            throw new RuntimeException("Database command failed: {$result->error}");
        }
    }

    private function run(string $user, string $password, string $sql, ?string $database = null, int $timeout = 120): ServerResult
    {
        $command = sprintf('%s -N -B -u %s%s%s', $this->client, escapeshellarg($user), $this->hostFlags(),
            $database !== null ? ' '.escapeshellarg(Identifier::check($database)) : '');
        $result = Process::env(['MYSQL_PWD' => $password])->timeout($timeout)->input($sql)->run($this->wrap($command));

        return new ServerResult($result->successful(), $result->output(), $result->errorOutput());
    }

    private function account(string $user): string
    {
        return "'".Identifier::check($user)."'@'%'";
    }

    private function hostFlags(): string
    {
        return $this->container === null
            ? sprintf(' -h %s -P %d', escapeshellarg($this->host), $this->port)
            : '';
    }

    private function wrap(string $command): string
    {
        return $this->container === null
            ? $command
            : sprintf('docker exec -i -e MYSQL_PWD %s %s', escapeshellarg($this->container), $command);
    }
}
