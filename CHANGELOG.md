# Changelog

## v0.2.1 (unreleased)

A fix for the `shared` strategy. No breaking change.

### Fixed

- **Connection URLs in shared worktrees:** `worktree:create`, `worktree:setup` and `worktree:convert` empty `DB_URL`, `DATABASE_URL` and `REDIS_URL` in the worktree's `.env` when it has a value there. Before, a URL copied from the main checkout's `.env` won over the `DB_*` and `REDIS_*` values of `shared.env`, so the worktree connected to the main database and Redis. The new key `shared.clear_env` holds the list. A key that `shared.env` or `env_overrides` sets keeps its value, and a config published before this release uses the default list. Each setup run checks the keys again, so `worktree:setup` fixes a worktree that v0.2.0 set up. The `native` and `sail` strategies are unchanged. ([#21](https://github.com/wwwoda/laravel-worktrees/issues/21))
- **`.env` writes** replace a key also where the line has the forms that dotenv accepts (`export KEY=value`, spaces around `=`). Before, such a line stayed and a second line was added.

## v0.2.0 (2026-11-08)

This release adds the `shared` strategy (one database server and one Redis for all worktrees of a machine), Laravel 13 support, and new commands. The default strategy stays `native`, and no config key was renamed or removed. See "Breaking changes" and "Upgrade notes" below.

### Added

- **Shared services strategy** (`bootstrap.strategy = shared`, config section `shared`): one MariaDB, MySQL or PostgreSQL server and one Redis for all worktrees, and one app container per worktree. Each worktree gets its own app, test and e2e databases copied from a template (`copy_from_template`), the names of its parallel test databases (Laravel makes them empty; on PostgreSQL the package makes them in advance), its own database user with rights on its exact database names only, two Redis database numbers, a state file `storage/app/worktree/state.json`, and the values of `shared.env` in its `.env`. Names that could equal another worktree's, a template's or the main database are refused.
- **Template databases**, named by a hash of the main checkout's migrations, seeders and seed command, built under a lock by a builder user with rights on the template only. **`worktree:template [--prune] [--name]`**.
- **`worktree:create --from-main`**: copies the main database in place of the template, only where `shared.database.allow_from_main` is true.
- **PostgreSQL** for shared services (`shared.database.driver = pgsql`): native template copies, one role per worktree without the right to create databases, every database (parallel test databases too) created in advance and owned by the role, `CONNECT` revoked from `PUBLIC`, and a clear stop for `php artisan test --parallel --recreate-databases`.
- **App container for shared services:** starts the shared stack when the database server does not answer (`shared.stack.start_command`), writes `shared.app.compose_file` as `COMPOSE_FILE`, copies `vendor` and `node_modules` from the main checkout with copy-on-write when the lock files are equal, builds the bundle in the container, removes a stale `public/hot`, and writes the queue worker mode and queue list to `.env`. **`worktree:create --horizon`**. `worktree:create` prints the time of each step and the URL.
- **`worktree:setup <path> [--name]`**: sets up a git worktree that another tool made, at any path. `worktree:setup` and `worktree:create` can run again and complete a partial setup: a finished database is kept, and only a copy that stopped half way is dropped and copied again; existing config files are kept. Each step is written to `storage/logs/worktree-setup.log`.
- **`worktree:worker <worktree> [listen|horizon]`**: shows or changes the queue worker and recreates the app container.
- **`worktree:convert [name] [--all]`**: moves Sail worktrees and the main checkout to shared services with their data from the old volume. **`worktree:volumes [--remove=NAME]`** lists the old volumes with their size and removes only the named ones. With a PostgreSQL server, `worktree:convert` stops when an old MariaDB volume exists.
- **Safe delete with shared services:** `worktree:delete` checks the state file before it stops anything (a missing or bad one stops the delete with nothing changed), stops the containers, drops only the worktree's own databases and user (each name checked against its derived names, the main database and the templates), empties its Redis numbers (`shared.redis.*`), then removes the folder. It fails when the folder or the worktree entry stays, and reports a branch that remains. **`worktree:delete --delete-branch`** deletes a merged branch without a question.
- **`sail` strategy** (`bootstrap.strategy = sail`, `bootstrap.sail.app_service`): `docker compose up -d` in the worktree, PHP, Composer and artisan through `docker compose exec`, the node install and build on the host, and `docker compose --profile "*" down -v` on delete (without `-v` with `--keep-db`). The setup stops when a worktree has the same compose project as the main checkout.
- **`Contracts\BootstrapStrategy`** with `NativeBootstrapStrategy` (default, the earlier behaviour), `SailBootstrapStrategy` and `SharedServicesBootstrapStrategy`.
- **`env_overrides`**: `.env` values for each worktree, as strings with `{name}` and `{path}` or as closures.
- **Port isolation:** `APP_PORT` and `VITE_PORT`, when they are in `.env`, get `ports.app_base` or `ports.vite_base` plus `crc32(name) % 900`.
- **`worktree:bootstrap <name> --status|--resume`**: prints the checkout and its bootstrap record as JSON, or resumes a failed setup. Stages are recorded in `storage/worktree-bootstrap.json`; a resume skips completed stages, keeps copied config and your changes, refuses a branch change and holds a lock.
- **`WorktreeManager::tearDown()`**, called by `worktree:delete` and `worktree:cleanup` before the folder is removed.
- **Laravel 13** support (illuminate 13, Testbench 11). CI tests Laravel 12 and 13 on PHP 8.3 and 8.4, and runs integration tests against MariaDB 10.11 and PostgreSQL 17 (`tests/Integration`).
- The README describes every command, option and config key.

### Changed

- **`worktree:create --branch X`** uses a local `X`, else `origin/X`, else makes `X` from `--base`. Before, a branch that existed only on `origin` was made again from the base branch. `--issue` with several linked branches asks for `--branch`. A pull request from a fork is fetched into `pr-{number}`.
- **`worktree:create` on an existing worktree** completes its setup. Before, it failed.
- **A failed setup keeps the checkout** and prints the command that continues it.
- **`worktree:delete` and `worktree:cleanup`** remove only folders that git registers as worktrees of this repository, stop the worktree environment first, and keep the checkout when that fails. The files that the package writes into a worktree no longer count as uncommitted changes.
- **The help text of `worktree:create --branch`** names the real default (`branch_prefix` and the name).
- New dependency `vlucas/phpdotenv` `^5.6` (Laravel requires it already).

### Fixed

- `worktree:delete` and `worktree:bootstrap --resume` print the error and exit non-zero instead of a stack trace when the folder is not a registered worktree or a step fails.
- `worktree:cleanup` runs its `gh` calls from the project folder.
- `APP_NAME` and `env_overrides` values with `$1` or `\0` are written literally.

### Breaking changes

- **PHP API:** `WorktreeManager::__construct()` takes `BootstrapStrategy $bootstrapStrategy` in place of `string $nodePackageManager`, and a new `array $envOverrides` argument. The private helpers `installDependencies`, `buildFrontendAssets` and `runDatabaseMigrations` are gone; their logic is in `NativeBootstrapStrategy`. This matters only when you construct or extend `WorktreeManager` yourself; the service container wires it.
- **`.env` ports:** when the main checkout's `.env` has `APP_PORT` or `VITE_PORT`, each new worktree now gets a different value.
- **`--branch` with a branch that exists only on `origin`** now checks out `origin/<branch>` instead of a new branch from the base branch.

### Upgrade notes

1. `composer require --dev woda/laravel-worktrees:^0.2`.
2. If you published `config/worktrees.php`, compare it with the package's file and copy the new keys you need. Laravel merges a published file with the package's file at the top level only: a missing top-level section (`ports`, `shared`, `env_overrides`) comes from the package, but an old `bootstrap` section has no `strategy` key, so `WORKTREE_BOOTSTRAP_STRATEGY` has no effect until you add `'strategy' => env('WORKTREE_BOOTSTRAP_STRATEGY', 'native')` (and `sail`) to it. An old published file works for `native` without a change.
3. If your worktrees must keep the main checkout's `APP_PORT` or `VITE_PORT`, set them with `env_overrides`.
4. If you construct `WorktreeManager` yourself, pass a `BootstrapStrategy` (for example `new NativeBootstrapStrategy(nodePackageManager: 'pnpm')`).
5. To use shared services, read "Shared services" in the README: the project needs an app-only compose file, a start command for the shared stack, and a queue worker that reads `WORKTREE_QUEUE_WORKER` and `WORKTREE_QUEUES`. Existing Sail worktrees move with `php artisan worktree:convert`.

## v0.1.4

### Fixed
- Simplified worktree removal: always delete directory + prune instead of unreliable `git worktree remove`

## v0.1.3

### Fixed
- Worktree deletion fails when copied gitignored files (`.env`, `.claude`) are present

## v0.1.2

### Fixed
- `gh issue develop` (create mode) outputs a full URL; only the branch slug is now extracted

## v0.1.1

### Fixed
- `gh issue develop --list` output includes a tab-separated URL; only the branch name is now used

### Changed
- PR worktree default name is now `pr-{number}` instead of slugified branch name
- Worktree name prompt hint now shows the resulting folder name

## v0.1.0

Initial release.
