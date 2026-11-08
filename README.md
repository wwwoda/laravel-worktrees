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

    'ports' => [
        'app_base' => (int) env('WORKTREE_APP_PORT_BASE', 8100),
        'vite_base' => (int) env('WORKTREE_VITE_PORT_BASE', 5200),
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

Options: `--branch`, `--base`, `--issue`, `--pr`, `--skip-deps`, `--skip-build`, `--skip-db` (with shared services only on a resume, once the worktree has its own databases), `--from-main` (shared services)

`--branch` attaches an existing local branch, or a remote `origin/<branch>`, before creating a new
branch from `--base`. `--issue` fails when the issue has several linked branches; pass `--branch`.
A failed bootstrap keeps the checkout so it can be resumed.

### `worktree:bootstrap`

Inspect or resume the environment setup of an existing worktree. Resume skips completed stages,
keeps the copied configuration and never touches uncommitted edits. Stage progress is recorded in
`storage/worktree-bootstrap.json` inside the worktree.

```bash
php artisan worktree:bootstrap my-feature --status   # JSON: path, branch, head, bootstrap record
php artisan worktree:bootstrap my-feature --resume
```

### `worktree:list`

```bash
php artisan worktree:list
php artisan worktree:list --json
```

### `worktree:delete`

```bash
php artisan worktree:delete my-feature
php artisan worktree:delete my-feature --force     # Skip safety checks
php artisan worktree:delete my-feature --keep-db    # Keep cloned database and compose volumes
```

Delete and cleanup only remove directories that git registers as worktrees of this repository.
Teardown brings down every compose profile of the worktree; a failed teardown keeps the checkout.

### `worktree:cleanup`

Remove worktrees whose PR or issue is closed. Dirty worktrees and worktrees with unpushed commits are skipped.

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

## Shared Services

With `bootstrap.strategy` set to `shared`, all worktrees on a machine use one database server and one Redis, and each worktree runs one app container (started like the Sail strategy). Set `database.strategy` to `none`; the `shared` config section replaces it. Supported servers: MariaDB and MySQL.

What each worktree gets:

- **Its own databases:** `{prefix}_{name}` (app), `{prefix}_{name}_test`, `{prefix}_{name}_e2e`, and Laravel's parallel test databases `{prefix}_{name}_test_test_1` to `_N` (`parallel_workers`). A dash in the name becomes `_`.
- **A template copy:** the app, test and e2e databases start as a copy of a template database (`copy_from_template`). After the copy, migrations newer than the template run in each.
- **Its own database user** with a generated password. The user has rights on the exact database names above and on nothing else: it cannot create, read, change or drop the main database, a template, or another worktree's database. The grant escapes `_` and `%`, which MariaDB and MySQL read as wildcards in a `GRANT`.
- **Two Redis database numbers** (default and cache), free among all worktrees; the numbers in `redis.reserved` (the main checkout's) are never given out.
- **A state file** `storage/app/worktree/state.json` with its names, user, password and Redis numbers. A missing or broken file is rebuilt; it is never fatal.
- **`.env` values** from the `shared.env` map, for example `DB_DATABASE={db_app}` and `DB_USERNAME={db_user}`. Add keys for the test and e2e names if your project reads them (`DB_TEST_DATABASE={db_test}`).

The main database is `shared.database.main` (`WORKTREE_SHARED_DB_MAIN`); when it is empty, the package reads `DB_DATABASE` from the main checkout's `.env`, also when artisan runs in a linked worktree.

Names are refused when they could equal another worktree's database (names that end in `-test`, `-e2e`, `-test-N`, `-e2e-N`), a template (`tpl-…`), the main database, or the names that another worktree already uses.

### Template databases

The template is named `{prefix}_tpl_{hash}`. The hash covers the content of `template.hash_paths` in the main checkout (migrations and seeders by default) and the seed command, so a changed migration or seeder gives a new template. The build runs `php artisan migrate --force` and then `template.seed_command`, in the main checkout's app container (`template.runner = container`) or on the host (`host`), connected as a temporary user that has rights on the template only. Builds run under a lock: a second setup waits for the first build and uses its result. A template counts as ready when its database exists and its marker in the main checkout's `storage/app/worktrees/templates/` exists.

```bash
php artisan worktree:template           # build the current template when it is missing
php artisan worktree:template --prune   # also drop older templates
php artisan worktree:template --name    # print the current template name
```

### App container, dependencies and queue worker

- **Shared stack:** when the database server does not answer, `shared.stack.start_command` runs in the main checkout, and the setup waits until the server answers. Worktree commands never stop, reset or upgrade the shared stack.
- **App container:** `docker compose up -d` in the worktree, then a wait until the app service runs PHP. `shared.app.compose_file` is written to the worktree's `.env` as `COMPOSE_FILE`, so compose uses the project's app-only file.
- **Dependencies:** when `composer.lock` (or the node lock file) equals the main checkout's, `vendor` (or the host `node_modules`) is copied from the main checkout with copy-on-write: `cp -c` on macOS (APFS), `cp --reflink=always` elsewhere. When the filesystem cannot do that (for example ext4), or the lock files differ, `composer install` runs in the container and the node install on the host.
- **Frontend:** the bundle is built in the container (`pnpm install --frozen-lockfile && pnpm run build`). Give the container its own `node_modules` volume in your compose file, because native packages install for one platform only. A `public/hot` whose dev server does not answer is removed.
- **Queue worker:** `WORKTREE_QUEUE_WORKER=listen|horizon` and `WORKTREE_QUEUES=<list>` are written to `.env` for your container to read. The list holds every queue that `config/horizon.php` names when the project requires `laravel/horizon`, else `default`. `worktree:create --horizon` picks Horizon.
- `worktree:create` prints the time of each step and the worktree's URL.

### Production-like data

`worktree:create --from-main` copies the main checkout's database into the worktree's app database in place of the template. It works only where `shared.database.allow_from_main` is true (`WORKTREE_ALLOW_FROM_MAIN`); elsewhere it is refused.

## Port Isolation (Sail)

When running multiple worktrees with Laravel Sail, each worktree needs unique host ports to avoid conflicts. If your `.env` contains `APP_PORT` or `VITE_PORT`, the package will automatically derive unique ports per worktree.

Ports are computed as `base + (crc32(name) % 900)`, giving ranges of 8100–8999 for `APP_PORT` and 5200–6099 for `VITE_PORT` by default. Configure the base ports in `config/worktrees.php` or via environment variables:

```env
WORKTREE_APP_PORT_BASE=8100
WORKTREE_VITE_PORT_BASE=5200
```

Port replacement only applies when the key already exists in your `.env` file — it won't inject ports you haven't defined.

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

- PHP 8.2+ (Laravel 13 needs 8.3+)
- Laravel 11, 12 or 13
- Shared services: the `mariadb`/`mariadb-dump` (or `mysql`/`mysqldump`) clients, inside the server's container (`shared.database.container`) or on the host

## License

MIT
