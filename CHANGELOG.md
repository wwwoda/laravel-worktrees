# Changelog

## Unreleased

Lean separate worktree stacks and commands for agent sessions ([#25](https://github.com/wwwoda/laravel-worktrees/issues/25)). New commands and config sections only act when you call or set them. Some changes are on by default for existing setups; they are listed under "On by default" and "Changed".

### Added

- **Agent commands:** `worktree:status` (URL, services with memory, compose profiles, database state, git state; `--json`), `worktree:services` (switch optional services by compose profile: `workers`, `tools`, `none`, `--add`, `--remove`), `worktree:exec` (run a command or an alias such as `test` in the app container, with its options, piped input and exit code passed on; write `--` before the command), `worktree:doctor [--fix]` (stopped containers, missing `vendor` or `node_modules`, a missing build or a stale `public/hot`, cached config, pending migrations or an empty database, workers on old code, a compose project of another checkout), and `worktree:context` (a short summary for the start of an agent session, with a documented session-start hook). New config sections `services`, `exec` and `context`.
- **Fast creation** (`sail`, top-level config section `fast`): one app image per version of its build inputs, shared by all worktrees and built once under a lock (on when `WORKTREE_APP_IMAGE_NAME` is set); a Composer cache folder in the container or Composer on the host; a copy-on-write `vendor` copy when `composer.lock` is equal (off by default); a frontend build cache keyed by the git tree of the build inputs and the `VITE_*` values (off by default: switch it on with `WORKTREE_BUILD_CACHE=true` and list every input of the build); a database dump with test data keyed by migrations, seeders and seed command (off by default; restored at creation, downloaded from a URL that CI publishes, or seeded and saved by the first worktree). Measured on srv4: a warm worktree in 8.3 s.
- **Idle stacks:** `worktree:idle-stop` stops worktree stacks without use for `idle.minutes` (120) for a host timer; it never stops the main checkout. `worktree:wake` starts a stopped stack (also `main`). The README describes Sablier as an option for stacks that a person uses in the browser.
- **The `worktree` skill** in `resources/boost/skills/worktree/SKILL.md`, published with `php artisan vendor:publish --tag=worktrees-skill`.

### On by default

These need no setting and act in existing setups:

- With `sail`, `composer install` runs first with `COMPOSER_DISABLE_NETWORK=1`, and again with the network when that fails (`WORKTREE_COMPOSER_OFFLINE_FIRST=false` switches it off).
- With `sail`, the node install runs while the containers start when no service mounts a named volume at `node_modules` and the strategy has no own node install (`WORKTREE_PARALLEL_NODE=false` switches it off). Its output goes to `storage/logs/worktree-node-install.log`.
- With `sail` and `shared`, the setup waits up to 120 s until no healthcheck says `starting` any more.
- The setup lists the package's bootstrap files in the repository's `.git/info/exclude` (shared by all its worktrees; nothing is committed).
- `worktree:delete` with `sail` or `shared` removes the images that the worktree's compose project built for itself (`{project}/…` or `{project}-{service}`); shared images and the main checkout's images stay.
- `worktree:delete` offers a branch whose merged pull request into the base branch has the same head commit (squash or rebase merges), and deletes such a branch with `git branch -D` when you confirm or pass `--delete-branch`.
- `worktree:exec` and `worktree:doctor --fix` start a stopped worktree stack under the lock that `worktree:idle-stop` takes, and mark it as used.

### Changed

- **`worktree:delete` exits with 1 when it stops** without a terminal (`-n`, an agent, a script) at uncommitted changes or unpushed commits; the message names them and `--force`. Before, it exited with 0. A person who answers no at the terminal still ends it with 0. Scripts that read the exit code now see the stop.
- **`worktree:create`, `worktree:delete` and `worktree:list` give the same result in every checkout.** Run in a linked worktree, they run again in the main checkout (which git names). Before, `worktree:create fix-x` in a worktree `feat` made `app-feat-fix-x` with the worktree's `.env` values.
- **`copy_files` no longer overwrites files that git tracks in the worktree.** They keep the branch's version, so a copied folder such as `.claude` no longer makes every new worktree show changes. A project that copied tracked files on purpose must move them out of the tracked tree. Symlinked folders are still copied with their files.
- With `sail` and `shared`, the node install runs as `pnpm install --prefer-offline` (or `npm install --prefer-offline`).
- The Sail host build runs without the variables of the checkout that runs artisan, so Vite reads the worktree's own `VITE_*` values.

### Upgrade notes

1. Update the package to the release that holds these changes.
2. A published `config/worktrees.php` keeps working; copy the new sections (`services`, `exec`, `context`, `fast`, `idle`) only to change their defaults.
3. For lean stacks, put optional services behind compose profiles (`profiles: [workers]`) and give healthchecks a short first check (`start_interval: 1s`).
4. To share the app image: `image: ${WORKTREE_APP_IMAGE:-myapp/app:latest}` on the app service and `WORKTREE_APP_IMAGE_NAME=myapp-app` in the main checkout's `.env`.
5. For a ready-made database: `WORKTREE_DB_DUMP=true` and `WORKTREE_DB_DUMP_SEED_COMMAND`. The default dump commands read the root password from `MARIADB_ROOT_PASSWORD` or Sail's `MYSQL_ROOT_PASSWORD`.
6. For the frontend build cache: `WORKTREE_BUILD_CACHE=true`, and every path the build reads in `fast.build_cache.paths` (for example `routes` for Wayfinder, `app` for Tailwind's class scan, `vendor` for Flux). A missing input gives a stale bundle without a warning.
7. For idle stacks: run `php artisan worktree:idle-stop` from a timer as the user that owns the checkouts, not as root.
8. For agents: `php artisan vendor:publish --tag=worktrees-skill` and the session-start hook from the README. Scripts that call `worktree:exec` write `--` before the command.

## v0.2.2 (2026-11-09)

A fix for the `shared` and `sail` strategies. No breaking change.

### Fixed

- **Build in the app container on Linux:** with `shared`, the node install on the host ran after `docker compose up -d`. On Linux, pnpm removes an empty `node_modules` folder and makes it again, and that detaches the volume that the app container mounts there. The build in the container then ran on the host's packages and stopped (`ERR_PNPM_ABORTED_REMOVE_MODULES_DIR_NO_TTY`). Now the copy-on-write copy of `node_modules` and the host node install run before the containers start, with `shared` and `sail`; the `native` order (Composer, then node) is unchanged. The copy of `node_modules` from the main checkout also works again: before, the mount point folder that `compose up` made stopped it. ([#23](https://github.com/wwwoda/laravel-worktrees/issues/23))
- **A running app container without its volume:** after `docker compose up -d`, the setup checks that the app container mounts each named volume of the app service. When one is missing (the container ran while `node_modules` was replaced on the host, for example on a resume), the app service is restarted once; when the volume is still missing, the setup stops with a message.
- **The URL at the end of the setup** expands `${KEY}` references to other keys of the worktree's `.env`, as Laravel's dotenv does. Before, `https://${WORKTREE_HOST}` was printed as written.

### Changed

- New marker interface `Contracts\InstallsNodeBeforeBringUp`. `SailBootstrapStrategy` (and so `SharedServicesBootstrapStrategy`) implements it; a custom strategy without it keeps the order of `native`.

## v0.2.1 (2026-11-08)

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
