# Changelog

## Unreleased

### Added

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
