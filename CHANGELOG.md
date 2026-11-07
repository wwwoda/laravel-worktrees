# Changelog

## Unreleased

### Added

- **Shared services strategy** (`bootstrap.strategy = shared`, config section `shared`): one MariaDB or MySQL server and one Redis for all worktrees. Each worktree gets its own app, test, e2e and parallel test databases copied from a template, its own database user with rights on its exact database names only, two Redis database numbers, a state file `storage/app/worktree/state.json`, and the values in its `.env`. Names that could equal another worktree's, a template's or the main database are refused.
- **Template databases**, named by a hash of the main checkout's migrations, seeders and seed command, built under a lock as a temporary user with rights on the template only. `worktree:template [--prune] [--name]`.
- **`worktree:create --from-main`**: copies the main database in place of the template, only where `shared.database.allow_from_main` is true.
- **PostgreSQL** for shared services (`shared.database.driver = pgsql`): native template copies, one role per worktree without the right to create databases, every database (parallel test databases too) created in advance and owned by the role, `CONNECT` revoked from `PUBLIC`, and a clear stop for `--recreate-databases`.
- Integration tests against a real MariaDB and PostgreSQL 17 (`tests/Integration`, CI job `integration`).

- **`Contracts\BootstrapStrategy` interface** — abstracts how bootstrap
  steps run for a worktree (composer install, node install, migrate,
  build, bring-up, tear-down).
- **`NativeBootstrapStrategy`** (default) — runs commands directly via
  `Process::path($worktree)->run(...)`. Mirrors the previous behaviour.
- **`SailBootstrapStrategy`** — `docker compose up -d` on bring-up, then
  `docker compose exec -T <appService> sh -c …` for PHP/composer/artisan
  commands. Vite + node-pm stay on host (host-native HMR is the
  recommended pattern on macOS to avoid VirtioFS chokidar lag).
  `tearDown()` runs `docker compose down -v` from `WorktreeDeleteCommand`.
- **`config('worktrees.bootstrap.strategy')`** — `'native'` (default) or
  `'sail'`. Selectable via `WORKTREE_BOOTSTRAP_STRATEGY` env. Sail mode
  honours `worktrees.bootstrap.sail.app_service` (default `laravel.test`).
- **`config('worktrees.env_overrides')`** — map of `KEY => string|Closure`
  upserts applied to the copied `.env` after the standard
  APP_NAME/APP_URL/DB_DATABASE/APP_PORT/VITE_PORT rewrites. Closures
  receive `(string $name, string $worktreePath)`. Existing lines are
  replaced; missing lines are appended.
- **`WorktreeManager::tearDown(string $name, ?Closure $output)`** —
  delegates to the bootstrap strategy. `WorktreeDeleteCommand` calls it
  before `git worktree remove`.
- **`worktree:bootstrap <name> --status|--resume`**: reports the checkout and its bootstrap record as JSON, or resumes a failed setup. Stages are recorded in `storage/worktree-bootstrap.json`; resume skips completed stages, keeps copied config and edits, refuses a branch change and holds a lock.
- **Explicit branch resolution**: `worktree:create --branch X` uses a local `X`, else `origin/X`, else creates `X` from `--base`. `--issue` with several linked branches asks for `--branch`.
- **Laravel 13** support (illuminate 13, Testbench 11). CI tests Laravel 12 and 13 on PHP 8.3 and 8.4, also for pull requests to other branches than `master`.

### Changed

- **`WorktreeManager::__construct()`** now takes
  `BootstrapStrategy $bootstrapStrategy` (previously `string $nodePackageManager`)
  and a new `array $envOverrides` argument. Direct constructor consumers
  must update; the service container handles wiring automatically.
- **`bootstrap()`** delegates `installComposerDependencies`,
  `installNodeDependencies`, `buildFrontend`, `runMigrations`, plus a new
  `bringUp` step to the configured strategy. The previous private helpers
  (`installDependencies`, `buildFrontendAssets`, `runDatabaseMigrations`)
  are removed from `WorktreeManager` — their logic moved into
  `NativeBootstrapStrategy`.

### Fixed

- `worktree:delete` and `worktree:bootstrap --resume` print the error and exit non-zero instead of a stack trace when the folder is not a registered worktree or a step fails.
- `worktree:delete` and `worktree:cleanup` refuse a folder that git does not register; a failed teardown keeps the checkout.
- Cleanup runs its `gh` calls from the project directory.
- `APP_NAME` and `env_overrides` values with `$1` or `\0` are written literally.

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
