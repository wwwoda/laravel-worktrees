<?php

namespace Woda\Worktrees\Shared;

use Closure;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * PostgreSQL through `psql`, inside the server's container (`docker exec`)
 * or against host and port. Passwords travel in PGPASSWORD, SQL on stdin.
 *
 * PostgreSQL cannot limit the right to create databases to names. So a
 * worktree's role has no CREATEDB right; the package creates every database
 * of the worktree in advance and makes the role its owner. CONNECT is revoked
 * from PUBLIC on every database the package creates, so no other role can
 * connect to a worktree's or a template database.
 */
final readonly class PostgresServer implements DatabaseServer
{
    public function __construct(
        private ?string $container,
        private string $host,
        private int $port,
        private string $adminUser,
        private string $adminPassword,
        private string $client = 'psql',
        private string $dumpClient = 'pg_dump',
    ) {}

    public function driver(): string
    {
        return 'pgsql';
    }

    public function requiresDatabasesInAdvance(): bool
    {
        return true;
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
        return array_values(array_filter($this->listDatabases(), static fn (string $name): bool => str_starts_with($name, $prefix)));
    }

    public function createDatabase(string $database, ?string $owner = null): void
    {
        $database = Identifier::check($database);
        if ($this->databaseExists($database)) {
            return;
        }
        $sql = "CREATE DATABASE \"{$database}\"".($owner !== null ? ' OWNER "'.Identifier::check($owner).'"' : '').";\n"
            ."REVOKE ALL ON DATABASE \"{$database}\" FROM PUBLIC;";
        $this->admin($sql);
    }

    public function copyDatabase(string $source, string $target, ?string $owner = null, ?Closure $output = null): void
    {
        Identifier::check($source);
        Identifier::check($target);
        if ($this->databaseExists($target)) {
            throw new RuntimeException("Database '{$target}' exists already; it is not overwritten.");
        }
        $ownerClause = $owner !== null ? ' OWNER "'.Identifier::check($owner).'"' : '';
        // A native copy needs a source without open connections (templates have
        // none). A source in use (the main database) is dumped and restored instead.
        $native = $this->run($this->adminUser, $this->adminPassword, "CREATE DATABASE \"{$target}\" TEMPLATE \"{$source}\"{$ownerClause}", timeout: 1800);
        if (! $native->successful) {
            if (! str_contains($native->error, 'is being accessed by other users')) {
                throw new RuntimeException("Copying '{$source}' to '{$target}' failed: {$native->error}");
            }
            $this->dumpRestore($source, $target, $owner, $output);
        }
        try {
            $this->admin("REVOKE ALL ON DATABASE \"{$target}\" FROM PUBLIC;");
            if ($owner !== null) {
                $this->takeOwnership($target, $owner);
            }
        } catch (RuntimeException $error) {
            $this->dropDatabase($target);

            throw $error;
        }
    }

    public function dropDatabase(string $database): void
    {
        $this->admin('DROP DATABASE IF EXISTS "'.Identifier::check($database).'" WITH (FORCE)');
    }

    public function importFile(string $database, string $file, ?Closure $output = null): void
    {
        $result = Process::env(['PGPASSWORD' => $this->adminPassword])->timeout(1800)->run(
            $this->wrap(sprintf('%s -X -q -v ON_ERROR_STOP=1 -U %s%s -d %s < %s', $this->client, escapeshellarg($this->adminUser),
                $this->hostFlags(), escapeshellarg(Identifier::check($database)), escapeshellarg($file))),
            $output,
        );
        if (! $result->successful()) {
            throw new RuntimeException("Importing into '{$database}' failed: {$result->errorOutput()}");
        }
    }

    public function grantUser(string $user, string $password, array $databases): void
    {
        $user = Identifier::check($user);
        $password = Identifier::password($password);
        $sql = "DO \$\$ BEGIN IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = '{$user}') THEN "
            ."CREATE ROLE \"{$user}\" LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION; END IF; END \$\$;\n"
            ."ALTER ROLE \"{$user}\" LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION PASSWORD '{$password}';\n";
        $existing = $this->listDatabases();
        foreach ($databases as $database) {
            if (in_array(Identifier::check($database), $existing, true)) {
                $sql .= "ALTER DATABASE \"{$database}\" OWNER TO \"{$user}\";\n";
            }
        }
        $this->admin($sql);
    }

    public function finishTemplateBuild(string $builder, string $template): void
    {
        // The builder owns the template's objects; copies hand them to the
        // worktree role. It keeps them, but can no longer log in.
        $this->admin('ALTER ROLE "'.Identifier::check($builder).'" NOLOGIN PASSWORD NULL');
    }

    public function userExists(string $user): bool
    {
        $result = $this->run($this->adminUser, $this->adminPassword, "SELECT COUNT(*) FROM pg_roles WHERE rolname = '".Identifier::check($user)."'");
        if (! $result->successful) {
            throw new RuntimeException("Cannot read roles: {$result->error}");
        }

        return trim($result->output) !== '0';
    }

    public function dropUser(string $user): void
    {
        $this->admin('DROP ROLE IF EXISTS "'.Identifier::check($user).'"');
    }

    public function runAs(string $user, string $password, string $sql, ?string $database = null): ServerResult
    {
        return $this->run($user, $password, $sql, $database ?? 'postgres');
    }

    /**
     * Give every object in the database that a non-superuser role owns (the
     * template builder, or the source's owner) to `$owner`.
     */
    private function takeOwnership(string $database, string $owner): void
    {
        $owner = Identifier::check($owner);
        $roles = $this->run($this->adminUser, $this->adminPassword,
            "SELECT DISTINCT r.rolname FROM pg_class c JOIN pg_roles r ON r.oid = c.relowner JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname NOT IN ('pg_catalog', 'information_schema') AND n.nspname NOT LIKE 'pg_toast%' AND NOT r.rolsuper",
            $database);
        if (! $roles->successful) {
            throw new RuntimeException("Cannot read object owners of '{$database}': {$roles->error}");
        }
        $sql = '';
        foreach (array_filter(array_map(trim(...), explode("\n", $roles->output))) as $role) {
            if ($role !== $owner && preg_match('/^[A-Za-z0-9_]{1,63}$/', $role)) {
                $sql .= "REASSIGN OWNED BY \"{$role}\" TO \"{$owner}\";\n";
            }
        }
        if ($sql !== '') {
            $result = $this->run($this->adminUser, $this->adminPassword, $sql, $database);
            if (! $result->successful) {
                throw new RuntimeException("Handing the objects of '{$database}' to '{$owner}' failed: {$result->error}");
            }
        }
    }

    /**
     * @param  (Closure(string, string): void)|null  $output
     */
    private function dumpRestore(string $source, string $target, ?string $owner, ?Closure $output): void
    {
        $this->createDatabase($target, $owner);
        $credentials = '-U '.escapeshellarg($this->adminUser).$this->hostFlags();
        // Restore as the owner role, so the objects belong to it.
        $setRole = $owner !== null ? 'SET ROLE "'.Identifier::check($owner).'";' : '';
        $script = sprintf(
            'f=$(mktemp) && %s %s --no-owner --no-privileges %s > "$f" && { echo %s; cat "$f"; } | %s -X -q -v ON_ERROR_STOP=1 %s -d %s; s=$?; rm -f "$f"; exit $s',
            $this->dumpClient, $credentials, escapeshellarg($source), escapeshellarg($setRole),
            $this->client, $credentials, escapeshellarg($target),
        );
        $result = Process::env(['PGPASSWORD' => $this->adminPassword])->timeout(1800)->run($this->wrap('sh -c '.escapeshellarg($script)), $output);
        if (! $result->successful()) {
            $this->dropDatabase($target);

            throw new RuntimeException("Copying '{$source}' to '{$target}' failed: {$result->errorOutput()}");
        }
    }

    /**
     * @return list<string>
     */
    private function listDatabases(): array
    {
        $result = $this->run($this->adminUser, $this->adminPassword, 'SELECT datname FROM pg_database WHERE NOT datistemplate');
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

    private function run(string $user, string $password, string $sql, string $database = 'postgres', int $timeout = 120): ServerResult
    {
        $command = sprintf('%s -X -q -t -A -v ON_ERROR_STOP=1 -U %s%s -d %s', $this->client, escapeshellarg($user), $this->hostFlags(), escapeshellarg($database));
        $result = Process::env(['PGPASSWORD' => $password])->timeout($timeout)->input($sql)->run($this->wrap($command));

        return new ServerResult($result->successful(), $result->output(), $result->errorOutput());
    }

    private function hostFlags(): string
    {
        return $this->container === null ? sprintf(' -h %s -p %d', escapeshellarg($this->host), $this->port) : '';
    }

    private function wrap(string $command): string
    {
        return $this->container === null
            ? $command
            : sprintf('docker exec -i -e PGPASSWORD %s %s', escapeshellarg($this->container), $command);
    }
}
