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
     * `.env` keys that Laravel's default config reads as a connection URL. A URL
     * wins over the separate DB_* and REDIS_* values, so a URL that a worktree's
     * `.env` took over from the main checkout would connect to the main database.
     */
    public const CLEAR_ENV = ['DB_URL', 'DATABASE_URL', 'REDIS_URL'];

    /**
     * @param  list<string>  $copyFromTemplate  which of app, test, e2e start as a template copy
     * @param  array<string, string>  $envMap  `.env` key => value with {placeholders}
     * @param  list<string>  $clearEnv  `.env` keys emptied when they hold a value, unless $envMap sets them
     */
    public function __construct(
        private readonly DatabaseServer $server,
        private readonly SharedStack $stack,
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
        private readonly array $clearEnv = self::CLEAR_ENV,
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
     * @param  (Closure(string): void)|null  $fillApp  fills a new, empty app database (worktree:convert); null: template or main copy
     */
    public function provision(string $name, string $path, array $context = [], bool $fromMain = false, ?Closure $output = null, ?Closure $onStep = null, ?Closure $fillApp = null): WorktreeState
    {
        $step = $onStep ?? static fn (string $message) => null;
        // A run that was asked for --from-main and stopped before the app copy
        // finished keeps that choice: a resume must not switch to the template.
        $fromMain = $fromMain || (WorktreeState::load($path)?->data['from_main_requested'] ?? false) === true;
        if ($fromMain && ! $this->allowFromMain) {
            throw new RuntimeException('--from-main is not allowed on this machine: production-like data stays on machines where config worktrees.shared.database.allow_from_main is true.');
        }
        $names = $this->names($name);
        $this->stack->ensureRunning($step, $output);

        // Reserve the names under the shared lock before touching the server, so a
        // concurrent setup with a name that maps to the same databases (`T9`, `t9`)
        // sees them in this worktree's state and stops.
        [$state, $password] = $this->withAllocationLock(function () use ($names, $path, $context, $fromMain): array {
            $this->assertNoOtherWorktreeUses($names, $path);
            $state = WorktreeState::load($path) ?? new WorktreeState([]);
            $password = $this->password($state, $names->user, $path);
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

        // The user first: PostgreSQL makes it the owner of each new database.
        $this->server->grantUser($names->user, $password, $names->databases());

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
        $needsTemplate = array_intersect(array_keys($missing), array_diff($this->copyFromTemplate, $fromMain || $fillApp ? ['app'] : []));
        if ($needsTemplate !== []) {
            $template = $this->templates->ensure($output, $step);
        }
        foreach ($missing as $role => $database) {
            if ($role === 'app' && $fillApp !== null) {
                $state->data['pending_copies'] = [...$this->pendingCopies($state), $database];
                $state->save($path);
                $this->server->createDatabase($database, $names->user);
                try {
                    $fillApp($database);
                } catch (\Throwable $error) {
                    $this->server->dropDatabase($database);

                    throw $error;
                }
                $state->data['pending_copies'] = array_values(array_diff($this->pendingCopies($state), [$database]));
                $state->save($path);
            } elseif ($role === 'app' && $fromMain) {
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
        if ($this->server->requiresDatabasesInAdvance()) {
            // The user cannot create databases here: Laravel's parallel test
            // databases must exist before the tests run.
            foreach ($names->parallel as $database) {
                $this->server->createDatabase($database, $names->user);
            }
        }
        $this->server->grantUser($names->user, $password, $names->databases());

        return $this->withAllocationLock(function () use ($state, $path, $template, $fromMain, $missing, $fillApp): WorktreeState {
            $redis = $state->redis();
            if (count($redis) !== 2 || $this->redis->taken($path, $redis)) {
                $redis = $this->redis->allocate($path);
            }
            $state->data = [
                ...$state->data,
                'redis' => $redis,
                'compose_project' => EnvFile::get($path.'/.env', 'COMPOSE_PROJECT_NAME'),
                'template' => $template,
                'source' => isset($missing['app']) ? ($fillApp !== null ? 'converted' : ($fromMain ? 'main' : 'template')) : ($state->string('source') ?? 'existing'),
            ];
            unset($state->data['from_main_requested']);
            $state->save($path);
            $this->writeEnv($path, $state);

            return $state;
        });
    }

    public function server(): DatabaseServer
    {
        return $this->server;
    }

    /**
     * Start the shared stack when the database server does not answer.
     *
     * @param  (Closure(string): void)|null  $onStep
     * @param  (Closure(string, string): void)|null  $output
     */
    public function ensureServer(?Closure $onStep = null, ?Closure $output = null): void
    {
        $this->stack->ensureRunning($onStep, $output);
    }

    /**
     * Run the shared stack's start command even when the database answers, so
     * shared containers that were removed (Redis) come back.
     *
     * @param  (Closure(string): void)|null  $onStep
     * @param  (Closure(string, string): void)|null  $output
     */
    public function restartServer(?Closure $onStep = null, ?Closure $output = null): void
    {
        $this->stack->restart($onStep, $output);
    }

    /**
     * The app database a conversion fills: the main database for the main
     * checkout, the derived app database for a worktree.
     */
    public function appDatabase(?string $worktreeName): string
    {
        return $worktreeName === null ? Identifier::check($this->mainDatabase) : $this->names($worktreeName)->app;
    }

    /**
     * Give the main checkout its databases on the shared server: the main
     * database (filled by `$fillMain`, else a template copy), its test and e2e
     * databases, a user with the main database's name and rights on exactly
     * these names, and the reserved Redis numbers.
     *
     * @param  (Closure(string): void)|null  $fillMain
     * @param  (Closure(string, string): void)|null  $output
     * @param  (Closure(string): void)|null  $onStep
     */
    public function provisionMain(string $path, ?Closure $fillMain = null, ?Closure $output = null, ?Closure $onStep = null): WorktreeState
    {
        $step = $onStep ?? static fn (string $message) => null;
        $main = Identifier::check($this->mainDatabase);
        $this->stack->ensureRunning($step, $output);
        $test = $main.'_test';
        $parallel = [];
        for ($token = 1; $token <= $this->parallelWorkers; $token++) {
            $parallel[] = $test.'_test_'.$token;
        }
        $databases = ['app' => $main, 'test' => $test, 'e2e' => $main.'_e2e'];
        foreach ($databases as $database) {
            if (str_starts_with($database, $this->templates->prefix())) {
                throw new RuntimeException("The main database name '{$database}' looks like a template.");
            }
        }

        $state = WorktreeState::load($path) ?? new WorktreeState([]);
        $password = $this->password($state, $main, $path);
        $template = null;
        foreach ($databases as $role => $database) {
            if ($this->server->databaseExists($database) && ! in_array($database, $this->pendingCopies($state), true)) {
                $step("Database {$database} exists; kept as it is.");

                continue;
            }
            if ($this->server->databaseExists($database)) {
                $this->server->dropDatabase($database);
            }
            if ($role === 'app' && $fillMain !== null) {
                $state->data['pending_copies'] = [...$this->pendingCopies($state), $database];
                $state->save($path);
                $this->server->createDatabase($database);
                try {
                    $fillMain($database);
                } catch (\Throwable $error) {
                    $this->server->dropDatabase($database);

                    throw $error;
                }
                $state->data['pending_copies'] = array_values(array_diff($this->pendingCopies($state), [$database]));
            } elseif (in_array($role, $this->copyFromTemplate, true)) {
                $template ??= $this->templates->ensure($output, $step);
                $step("Copying template {$template} into {$database}...");
                $this->copy($state, $path, $template, $database, $main, $output);
            } else {
                $this->server->createDatabase($database);
            }
        }
        $this->server->grantUser($main, $password, [...array_values($databases), ...$parallel]);

        $reserved = $this->redis->reserved();
        $state->data = [
            ...$state->data,
            'name' => 'main',
            'role' => 'main',
            'databases' => [...$databases, 'parallel' => $parallel],
            'user' => $main,
            'password' => $password,
            'redis' => [$reserved[0] ?? 0, $reserved[1] ?? 1],
            'source' => $state->string('source') ?? ($fillMain !== null ? 'converted' : 'template'),
            'created_at' => $state->string('created_at') ?? date(DATE_ATOM),
        ];
        $state->save($path);
        $this->writeEnv($path, $state);

        return $state;
    }

    /**
     * The checks of deprovision(), without any change: what it would drop, or
     * an exception that says why nothing may be dropped. worktree:delete runs
     * them before it stops anything.
     *
     * @return array{databases: list<string>, user: string, redis: list<int>}
     */
    public function checkDeprovision(string $name, string $path): array
    {
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

        return ['databases' => $databases, 'user' => $user, 'redis' => $numbers];
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
        ['databases' => $databases, 'user' => $user, 'redis' => $numbers] = $this->checkDeprovision($name, $path);

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
     * The database and user names a worktree's .env holds under the configured
     * keys, also where they sit inside a longer value such as a DATABASE_URL.
     *
     * @return list<string>
     */
    private function envNames(string $path): array
    {
        $names = [];
        foreach ($this->envMap as $key => $template) {
            $value = EnvFile::get($path.'/.env', $key);
            foreach ($value === null ? [] : EnvFile::placeholderValues($template, $value) as $name => $found) {
                if (in_array($name, ['db_app', 'db_test', 'db_e2e', 'db_user'], true)) {
                    $names[] = $found;
                }
            }
        }

        return $names;
    }

    private function password(WorktreeState $state, string $user, string $path): string
    {
        $candidates = [$state->string('password')];
        if (EnvFile::get($path.'/.env', 'DB_USERNAME') === $user) {
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
        $this->clearConnectionUrls($path);
    }

    /**
     * Empty the connection URL keys that have a value in the worktree's `.env`:
     * Laravel would use a URL copied from the main checkout before the DB_* and
     * REDIS_* values. A key of the `.env` map keeps its value; a missing key
     * stays missing. Safe to run on every setup.
     */
    public function clearConnectionUrls(string $path): void
    {
        $envPath = $path.'/.env';
        $cleared = [];
        foreach (array_diff($this->clearEnv, array_keys($this->envMap)) as $key) {
            if (! in_array(EnvFile::get($envPath, $key), [null, ''], true)) {
                $cleared[$key] = '';
            }
        }
        if ($cleared !== []) {
            EnvFile::upsert($envPath, $cleared);
        }
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
