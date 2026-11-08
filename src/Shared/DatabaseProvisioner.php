<?php

namespace Woda\Worktrees\Shared;

use Closure;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Gives one worktree its databases, its database user and its Redis numbers
 * on the shared services, records them in the worktree's state file and
 * writes them to its `.env`.
 *
 * It never drops or overwrites a database: a database that exists is kept.
 */
class DatabaseProvisioner
{
    /**
     * @param  list<string>  $copyFromTemplate  which of app, test, e2e start as a template copy
     * @param  array<string, string>  $envMap  `.env` key => value with {placeholders}
     */
    public function __construct(
        private readonly DatabaseServer $server,
        private readonly TemplateManager $templates,
        private readonly RedisAllocator $redis,
        private readonly WorktreeRegistry $registry,
        private readonly string $prefix,
        private readonly string $mainDatabase,
        private readonly int $parallelWorkers,
        private readonly bool $allowFromMain,
        private readonly array $copyFromTemplate,
        private readonly string $appHost,
        private readonly int $appPort,
        private readonly array $envMap,
        private readonly ?RedisServer $redisServer = null,
    ) {}

    public function names(string $name): WorktreeNames
    {
        $names = WorktreeNames::for($name, $this->prefix, $this->parallelWorkers);
        $names->assertSafe($this->mainDatabase, $this->templates->prefix());
        // MySQL account names have at most 32 characters (MariaDB 80, PostgreSQL 63 like the databases).
        if ($this->server->driver() === 'mysql' && strlen($names->user) > 32) {
            throw new RuntimeException("The worktree name '{$name}' is too long for MySQL: the user name '{$names->user}' has more than 32 characters.");
        }

        return $names;
    }

    /**
     * @param  array{branch?: string|null, base?: string|null}  $context
     * @param  (Closure(string, string): void)|null  $output
     * @param  (Closure(string): void)|null  $onStep
     */
    public function provision(string $name, string $path, array $context = [], bool $fromMain = false, ?Closure $output = null, ?Closure $onStep = null): WorktreeState
    {
        $step = $onStep ?? static fn (string $message) => null;
        // A run that was asked for --from-main and stopped before the app copy
        // finished keeps that choice: a resume must not switch to the template.
        $fromMain = $fromMain || (WorktreeState::load($path)?->data['from_main_requested'] ?? false) === true;
        if ($fromMain && ! $this->allowFromMain) {
            throw new RuntimeException('--from-main is not allowed on this machine: production-like data stays on machines where config worktrees.shared.database.allow_from_main is true.');
        }
        $names = $this->names($name);
        if (! $this->server->ping()) {
            throw new RuntimeException('The shared database server does not answer. Start the shared stack and run the setup again.');
        }

        // Reserve the names under the shared lock before touching the server, so a
        // concurrent setup with a name that maps to the same databases (`T9`, `t9`)
        // sees them in this worktree's state and stops.
        [$state, $password] = $this->withAllocationLock(function () use ($names, $path, $context, $fromMain): array {
            $this->assertNoOtherWorktreeUses($names, $path);
            $state = WorktreeState::load($path) ?? new WorktreeState([]);
            $password = $this->password($state, $names, $path);
            $state->data = [
                ...$state->data,
                'name' => $names->worktree,
                'branch' => $context['branch'] ?? $state->string('branch'),
                'base' => $context['base'] ?? $state->string('base'),
                'databases' => ['app' => $names->app, 'test' => $names->test, 'e2e' => $names->e2e, 'parallel' => $names->parallel],
                'user' => $names->user,
                'password' => $password,
                'created_at' => $state->string('created_at') ?? date(DATE_ATOM),
            ];
            if ($fromMain && ! $this->server->databaseExists($names->app)) {
                $state->data['from_main_requested'] = true;
            }
            $state->save($path);

            return [$state, $password];
        });

        // A copy that stopped half way (process killed) left its target behind:
        // it is this worktree's own name, so drop it and copy again.
        foreach ($this->pendingCopies($state) as $database) {
            if ($names->owns($database) && $this->server->databaseExists($database)) {
                $step("Database {$database} is an unfinished copy; copying again.");
                $this->server->dropDatabase($database);
            }
        }

        $missing = array_filter(
            ['app' => $names->app, 'test' => $names->test, 'e2e' => $names->e2e],
            fn (string $database): bool => ! $this->server->databaseExists($database),
        );
        $template = $state->string('template');
        $needsTemplate = array_intersect(array_keys($missing), array_diff($this->copyFromTemplate, $fromMain ? ['app'] : []));
        if ($needsTemplate !== []) {
            $template = $this->templates->ensure($output, $step);
        }
        foreach ($missing as $role => $database) {
            if ($role === 'app' && $fromMain) {
                if (! $this->server->databaseExists($this->mainDatabase)) {
                    throw new RuntimeException("The main database '{$this->mainDatabase}' does not exist; --from-main has nothing to copy.");
                }
                $step("Copying the main database into {$database}...");
                $this->copy($state, $path, $this->mainDatabase, $database, $names->user, $output);
            } elseif (in_array($role, $this->copyFromTemplate, true) && $template !== null) {
                $step("Copying template {$template} into {$database}...");
                $this->copy($state, $path, $template, $database, $names->user, $output);
            } else {
                $this->server->createDatabase($database, $names->user);
            }
        }
        foreach (array_diff(['app' => $names->app, 'test' => $names->test, 'e2e' => $names->e2e], $missing) as $database) {
            $step("Database {$database} exists; kept as it is.");
        }
        $this->server->grantUser($names->user, $password, $names->databases());

        return $this->withAllocationLock(function () use ($state, $path, $template, $fromMain, $missing): WorktreeState {
            $redis = $state->redis();
            if (count($redis) !== 2 || $this->redis->taken($path, $redis)) {
                $redis = $this->redis->allocate($path);
            }
            $state->data = [
                ...$state->data,
                'redis' => $redis,
                'compose_project' => EnvFile::get($path.'/.env', 'COMPOSE_PROJECT_NAME'),
                'template' => $template,
                'source' => isset($missing['app']) ? ($fromMain ? 'main' : 'template') : ($state->string('source') ?? 'existing'),
            ];
            unset($state->data['from_main_requested']);
            $state->save($path);
            $this->writeEnv($path, $state);

            return $state;
        });
    }

    /**
     * Drop a deleted worktree's own databases and user and empty its Redis
     * numbers. Only names that its state file lists, that its name derives
     * and that are neither the main database nor a template are touched; any
     * other name stops the whole step before anything is dropped.
     *
     * @param  (Closure(string): void)|null  $onStep
     * @return list<string> what was removed
     */
    public function deprovision(string $name, string $path, ?Closure $onStep = null): array
    {
        $step = $onStep ?? static fn (string $message) => null;
        $state = WorktreeState::load($path);
        if ($state === null) {
            throw new RuntimeException("Worktree '{$name}' has no state file: its shared databases are not dropped, because nothing is guessed. Drop them by hand if needed.");
        }
        // The allowed names come from the worktree being deleted, never from the
        // state file: a copied or stale state of another worktree must not count.
        $names = $this->names($name);
        $stateName = $state->string('name');
        if ($stateName !== null && strtolower($stateName) !== strtolower($name)) {
            throw new RuntimeException("The state file of '{$name}' belongs to worktree '{$stateName}'. Nothing was dropped.");
        }
        // The state's lists must have their full shape: a part that does not parse
        // would read as "nothing to drop" and leave data behind half deleted.
        $recorded = $state->data['databases'] ?? null;
        // Provisioning always writes the parallel list (empty with no workers): a missing one is incomplete.
        $parallel = is_array($recorded) ? ($recorded['parallel'] ?? null) : null;
        if (! is_array($recorded) || ! is_string($recorded['app'] ?? null) || ! is_string($recorded['test'] ?? null) || ! is_string($recorded['e2e'] ?? null)
            || ! is_array($parallel) || ! array_is_list($parallel) || array_filter($parallel, static fn (mixed $value): bool => ! is_string($value)) !== []) {
            throw new RuntimeException("The database list in the state file of '{$name}' is incomplete. Nothing was dropped.");
        }
        if (! $this->redis->isAllocation($state->data['redis'] ?? null)) {
            throw new RuntimeException("The Redis numbers in the state file of '{$name}' are missing or not an allocation of this package. Nothing was dropped.");
        }
        $databases = $state->databases();
        foreach ($databases as $database) {
            if (! ($names->owns($database) || $names->ownsParallel($database)) || strtolower($database) === strtolower($this->mainDatabase)
                || str_starts_with($database, $this->templates->prefix())) {
                throw new RuntimeException("Refusing to drop '{$database}': it is not one of the worktree's own database names. Nothing was dropped.");
            }
        }
        $user = $state->string('user');
        if ($user === null) {
            throw new RuntimeException("The state file of '{$name}' names no user; it is incomplete. Nothing was dropped.");
        }
        if ($user !== $names->user) {
            throw new RuntimeException("Refusing to drop user '{$user}': it is not the worktree's own user. Nothing was dropped.");
        }

        $numbers = $state->redis();
        if ($numbers !== [] && $this->redis->taken($path, $numbers)) {
            throw new RuntimeException('Another worktree or the main checkout also uses Redis databases '.implode(', ', $numbers).' of this state. Nothing was dropped.');
        }

        // Reach Redis before anything is dropped, so a Redis problem leaves the
        // worktree whole instead of half deleted. The flush itself comes first too:
        // a Redis that answers PING may still refuse FLUSHDB (ACL).
        if ($numbers !== [] && $this->redisServer !== null) {
            $this->redisServer->ping();
        }
        $removed = [];
        foreach ($numbers as $number) {
            if ($this->redisServer === null) {
                continue;
            }
            $this->redisServer->flush($number);
            $removed[] = "Redis database {$number}";
        }

        foreach ($databases as $database) {
            if ($this->server->databaseExists($database)) {
                $step("Dropping database {$database}...");
                $this->server->dropDatabase($database);
                $removed[] = "database {$database}";
            }
        }
        if ($this->server->userExists($user)) {
            $this->server->dropUser($user);
            $removed[] = "user {$user}";
        }

        return $removed;
    }

    /**
     * Copy with a record in the state file while the copy runs, so a copy that
     * stops half way is found and repeated on the next run.
     *
     * @param  (Closure(string, string): void)|null  $output
     */
    private function copy(WorktreeState $state, string $path, string $source, string $target, string $owner, ?Closure $output): void
    {
        $state->data['pending_copies'] = [...$this->pendingCopies($state), $target];
        $state->save($path);
        $this->server->copyDatabase($source, $target, $owner, $output);
        $state->data['pending_copies'] = array_values(array_diff($this->pendingCopies($state), [$target]));
        $state->save($path);
    }

    /**
     * @return list<string>
     */
    private function pendingCopies(WorktreeState $state): array
    {
        $pending = $state->data['pending_copies'] ?? [];

        return is_array($pending) ? array_values(array_filter($pending, is_string(...))) : [];
    }

    private function assertNoOtherWorktreeUses(WorktreeNames $names, string $path): void
    {
        $ours = array_map(strtolower(...), [...$names->databases(), $names->user]);
        foreach ($this->registry->otherWorktrees($path) as $otherPath => $state) {
            // A worktree that lost its state file (or has an incomplete one) still names
            // its databases and user in its .env: count both.
            $theirs = [...$this->envNames($otherPath), ...($state?->databases() ?? []), (string) $state?->string('user')];
            if (array_intersect($ours, array_map(strtolower(...), $theirs)) !== []) {
                throw new RuntimeException("The worktree at {$otherPath} already uses the database names of '{$names->worktree}'. Choose another name.");
            }
        }
    }

    /**
     * The values a worktree's .env holds for the configured database keys.
     *
     * @return list<string>
     */
    private function envNames(string $path): array
    {
        $keys = array_keys(array_filter($this->envMap, static fn (string $value): bool => preg_match('/\{db_(app|test|e2e|user)\}/', $value) === 1));

        return array_values(array_filter(array_map(static fn (string $key): ?string => EnvFile::get($path.'/.env', $key), $keys), is_string(...)));
    }

    private function password(WorktreeState $state, WorktreeNames $names, string $path): string
    {
        $candidates = [$state->string('password')];
        if (EnvFile::get($path.'/.env', 'DB_USERNAME') === $names->user) {
            $candidates[] = EnvFile::get($path.'/.env', 'DB_PASSWORD');
        }
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && preg_match('/^[A-Za-z0-9]{16,128}$/', $candidate)) {
                return $candidate;
            }
        }

        return bin2hex(random_bytes(16));
    }

    private function writeEnv(string $path, WorktreeState $state): void
    {
        /** @var array{app: string, test: string, e2e: string} $databases */
        $databases = $state->data['databases'];
        $redis = $state->redis();
        $values = [
            '{db_host}' => $this->appHost,
            '{db_port}' => (string) $this->appPort,
            '{db_app}' => $databases['app'],
            '{db_test}' => $databases['test'],
            '{db_e2e}' => $databases['e2e'],
            '{db_user}' => (string) $state->string('user'),
            '{db_password}' => (string) $state->string('password'),
            '{redis_default}' => (string) $redis[0],
            '{redis_cache}' => (string) $redis[1],
            '{name}' => (string) $state->string('name'),
        ];
        EnvFile::upsert($path.'/.env', array_map(static fn (string $value): string => strtr($value, $values), $this->envMap));
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function withAllocationLock(Closure $callback): mixed
    {
        $lockPath = MainCheckout::file('storage/app/worktrees/allocate.lock');
        File::ensureDirectoryExists(dirname($lockPath));
        $handle = fopen($lockPath, 'c');
        if ($handle === false || ! flock($handle, LOCK_EX)) {
            throw new RuntimeException('Cannot take the worktree allocation lock.');
        }
        try {
            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
