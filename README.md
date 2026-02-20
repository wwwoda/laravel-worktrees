# Laravel Worktrees

Git worktree management with database cloning for Laravel.

Create isolated development environments from your Laravel project using git worktrees. Each worktree gets its own branch, dependencies, database clone, and frontend build.

## Installation

```bash
composer require woda/laravel-worktrees
```

Publish the config (optional):

```bash
php artisan vendor:publish --tag=worktrees-config
```

## Configuration

```php
// config/worktrees.php
return [
    'base_path' => env('WORKTREE_BASE_PATH', dirname(base_path())),
    'branch_prefix' => env('WORKTREE_BRANCH_PREFIX', ''),
    'base_branch' => env('WORKTREE_BASE_BRANCH', 'master'),
    'copy_files' => ['.env'],

    'database' => [
        'strategy' => env('WORKTREE_DB_STRATEGY', 'auto'), // auto|sqlite|mysql|pgsql|none
        'sqlite_copy' => true,
        'mysql_docker_container' => env('WORKTREE_DB_MYSQL_DOCKER_CONTAINER'),
        'pgsql_docker_container' => env('WORKTREE_DB_PGSQL_DOCKER_CONTAINER'),
        'docker_host' => env('WORKTREE_DB_DOCKER_HOST', '127.0.0.1'),
    ],

    'bootstrap' => [
        'node_package_manager' => env('WORKTREE_NODE_PM', 'pnpm'),
        'build_frontend' => true,
        'run_migrations' => true,
    ],

    'ide' => [
        'command' => env('WORKTREE_IDE_COMMAND', 'phpstorm'),
    ],
];
```

## Commands

### `worktree:create`

Create a worktree with full environment isolation.

```bash
php artisan worktree:create my-feature
php artisan worktree:create --issue=42        # From GitHub issue
php artisan worktree:create --pr=10           # From pull request
php artisan worktree:create --branch=existing # From existing branch
php artisan worktree:create                   # Interactive mode
```

Options: `--branch`, `--base`, `--issue`, `--pr`, `--skip-deps`, `--skip-build`, `--skip-db`

### `worktree:list`

```bash
php artisan worktree:list
php artisan worktree:list --json
```

### `worktree:delete`

```bash
php artisan worktree:delete my-feature
php artisan worktree:delete my-feature --force     # Skip safety checks
php artisan worktree:delete my-feature --keep-db    # Keep cloned database
```

### `worktree:cleanup`

Remove stale worktrees.

```bash
php artisan worktree:cleanup --dry-run
php artisan worktree:cleanup --force
```

### `worktree:open`

Open a worktree in your IDE.

```bash
php artisan worktree:open my-feature
```

## Database Strategies

The `database.strategy` config determines how databases are cloned:

| Strategy | Behavior |
|----------|----------|
| `auto` | Detect from `database.default` config |
| `sqlite` | Copy the SQLite file into the worktree |
| `mysql` | `mysqldump \| mysql` into a new database |
| `pgsql` | `pg_dump \| psql` into a new database |
| `none` | Skip database cloning |

Docker containers are supported for MySQL and PostgreSQL — set the container name in config and the commands will be wrapped with `docker exec`.

## ProcessManager Contract

The package binds a `NullProcessManager` by default. To integrate with a process manager (e.g. screen sessions, tmux), implement `Woda\Worktrees\Contracts\ProcessManager`:

```php
interface ProcessManager
{
    public function isRunning(string $worktreeName): bool;
    public function terminate(string $worktreeName): void;
    public function runningLabel(string $worktreeName): string|null;
}
```

Bind your implementation in a service provider:

```php
$this->app->bind(ProcessManager::class, MyProcessManager::class);
```

## Requirements

- PHP 8.2+
- Laravel 11 or 12

## License

MIT
