<?php

namespace Woda\Worktrees\Tests\Support;

use Closure;
use RuntimeException;
use Woda\Worktrees\Shared\DatabaseServer;
use Woda\Worktrees\Shared\ServerResult;

/**
 * An in-memory database server for feature tests: databases hold a list of
 * rows, users hold their granted database names.
 */
class FakeDatabaseServer implements DatabaseServer
{
    /** @var array<string, list<string>> */
    public array $databases = [];

    /** @var array<string, array{password: string, databases: list<string>}> */
    public array $users = [];

    /** @var list<string> */
    public array $log = [];

    public bool $up = true;

    public function driver(): string
    {
        return 'mariadb';
    }

    public bool $inAdvance = false;

    public function requiresDatabasesInAdvance(): bool
    {
        return $this->inAdvance;
    }

    public function finishTemplateBuild(string $builder, string $template): void
    {
        $this->retireUser($builder);
    }

    public function ping(): bool
    {
        return $this->up;
    }

    public function databaseExists(string $database): bool
    {
        return array_key_exists($database, $this->databases);
    }

    public function databasesStartingWith(string $prefix): array
    {
        return array_values(array_filter(array_keys($this->databases), fn (string $name) => str_starts_with($name, $prefix)));
    }

    public function createDatabase(string $database, ?string $owner = null): void
    {
        $this->log[] = "create {$database}";
        $this->databases[$database] ??= [];
    }

    public function copyDatabase(string $source, string $target, ?string $owner = null, ?Closure $output = null): void
    {
        if (! $this->databaseExists($source)) {
            throw new RuntimeException("no {$source}");
        }
        if ($this->databaseExists($target)) {
            throw new RuntimeException("Database '{$target}' exists already; it is not overwritten.");
        }
        $this->log[] = "copy {$source} {$target}";
        $this->databases[$target] = $this->databases[$source];
    }

    public function dropDatabase(string $database): void
    {
        $this->log[] = "drop {$database}";
        unset($this->databases[$database]);
    }

    public function importFile(string $database, string $file, ?Closure $output = null, ?string $owner = null): void
    {
        $this->log[] = "import {$database}".($owner !== null ? " as {$owner}" : '');
        $this->databases[$database] = ['imported: '.trim((string) file_get_contents($file))];
    }

    public function grantUser(string $user, string $password, array $databases): void
    {
        $this->log[] = "grant {$user}";
        $this->users[$user] = ['password' => $password, 'databases' => $databases];
    }

    public function userExists(string $user): bool
    {
        return array_key_exists($user, $this->users);
    }

    public function dropUser(string $user): void
    {
        $this->log[] = "drop user {$user}";
        unset($this->users[$user]);
    }

    public function retireUser(string $user): void
    {
        // Like the real servers: the account cannot log in, but keeps its rights,
        // which the views and routines it defined need.
        $this->log[] = "retire user {$user}";
    }

    public function runAs(string $user, string $password, string $sql, ?string $database = null): ServerResult
    {
        return new ServerResult(false, '', 'not supported by the fake');
    }
}
