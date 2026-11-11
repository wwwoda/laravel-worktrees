# Laravel Worktrees

Git worktrees for Laravel projects, each with its own environment.

`php artisan worktree:create my-feature` makes a git worktree next to your project, copies its configuration, installs the dependencies, gives it its own database, builds the frontend and runs the migrations. Three strategies decide where this happens: on the host (`native`), in a Laravel Sail stack per worktree (`sail`), or in one app container per worktree that uses one database server and one Redis for all worktrees of the machine (`shared`).

## Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Strategies](#strategies)
- [Commands](#commands)
- [Configuration reference](#configuration-reference)
- [Native and Sail](#native-and-sail)
- [Shared services](#shared-services)
- [Files the package writes](#files-the-package-writes)
- [ProcessManager contract](#processmanager-contract)
- [Development](#development)

## Requirements

- PHP 8.2 or later (Laravel 13 needs PHP 8.3).
- Laravel 11, 12 or 13. CI tests Laravel 12 and 13. Composer blocks every Laravel 11 release with a security advisory by default.
- Git with `git worktree`.
- The GitHub CLI `gh` for `--issue`, `--pr`, the interactive issue and PR lists, and `worktree:cleanup`.
- `sail` and `shared`: Docker with Compose v2 (`docker compose`).
- `shared`: the database clients (`mariadb` and `mariadb-dump`, `mysql` and `mysqldump`, or `psql` and `pg_dump`) in the database server's container or on the host, and `redis-cli` in the Redis container or on the host for `worktree:delete`.

## Installation

```bash
composer require --dev woda/laravel-worktrees
php artisan vendor:publish --tag=worktrees-config   # optional: config/worktrees.php
```

The service provider registers itself. The commands are available in the console only.

## Strategies

`bootstrap.strategy` (`WORKTREE_BOOTSTRAP_STRATEGY`) selects the strategy.

| Strategy | Where the steps run | Database of a worktree |
|---|---|---|
| `native` (default) | On the host: `composer install`, the node install and build, `php artisan migrate`. | A clone made by `database.strategy` (see below). |
| `sail` | `docker compose up -d` in the worktree. PHP, Composer and artisan run in the app service (`docker compose exec`). The node install and the build run on the host. | Usually the worktree's own database service from its compose file (`database.strategy = none`). |
| `shared` | Like `sail`, but the bundle is built in the app container, and the compose file holds the app only. | Its own databases and user on the machine's shared server, copied from a template. Its own Redis numbers. |

The **legacy** database clone strategies (`database.strategy`) work with `native` and `sail`. The `shared` strategy does not use them: set `database.strategy` to `none` there.

| `database.strategy` | What it does |
|---|---|
| `auto` (default) | Selects from `database.default`: `sqlite`, `mysql` (also for `mariadb`), `pgsql`, else `none`. |
| `sqlite` | Copies `database/database.sqlite` into the worktree (when `database.sqlite_copy` is true) and sets `DB_DATABASE=database/database.sqlite`. |
| `mysql` | `mysqldump \| mysql` into `{database}_{name}` on the main checkout's server. |
| `pgsql` | `pg_dump \| psql` into `{database}_{name}` on the main checkout's server. |
| `none` | No clone. |

## Commands

All commands that take a worktree name find the folder `{base_path}/{project}-{name}`, where `{project}` is the folder name of the main checkout. A name has letters, digits and hyphens only.

### `worktree:create`

Create a worktree and set up its environment.

```bash
php artisan worktree:create my-feature                 # new branch my-feature from the base branch
php artisan worktree:create --issue=42                 # branch of issue 42 (gh issue develop), name 42
php artisan worktree:create --pr=10                    # branch of pull request 10, name pr-10
php artisan worktree:create --branch=feature/login     # existing branch, name feature-login
php artisan worktree:create my-feature --base=develop  # new branch from develop
php artisan worktree:create                            # interactive: issue, PR, branch or new
```

| Option | Meaning |
|---|---|
| `name` | The worktree name. Without it and without `--branch`, `--issue` or `--pr`, the command asks. |
| `--branch=` | The branch. A local branch is used as it is, else `origin/<branch>`, else a new branch from the base branch. Default: `{branch_prefix}{name}`. |
| `--base=` | The base branch for a new branch. Default: `base_branch`. |
| `--issue=` | Use the branch that is linked to this GitHub issue, or create one with `gh issue develop`. Stops when the issue has several linked branches: give `--branch`. Not together with `--pr`. |
| `--pr=` | Use the head branch of this pull request. The head of a pull request from a fork is fetched into the local branch `pr-{number}`. |
| `--skip-deps` | Do not install Composer and node dependencies. This also skips the migrations. |
| `--skip-build` | Do not build the frontend. |
| `--skip-db` | Do not make the database. With `shared`, only on a resume after the worktree has its own databases. |
| `--from-main` | `shared` only: copy the main database in place of the template (see [Production-like data](#production-like-data)). |
| `--horizon` | `shared` only: run Horizon as the queue worker in place of `queue:listen`. |

When the folder exists already, `worktree:create` completes the setup of that worktree; it never starts again from zero. When a step fails, the checkout stays, and the command prints the command that continues the setup. At the end it prints the time of each step and the worktree's `APP_URL`, with `${KEY}` references to other keys of the worktree's `.env` expanded. Each step is also written to `storage/logs/worktree-setup.log` in the worktree.

### `worktree:setup`

Set up a git worktree that another tool made, at any path. The steps are the same as in `worktree:create`, but the git worktree must exist already and be registered in this repository. The main checkout is refused.

```bash
php artisan worktree:setup ../lanes/t901             # name from the folder: t901
php artisan worktree:setup /srv/lanes/x --name=t902
```

| Option | Meaning |
|---|---|
| `path` | The folder of the worktree. |
| `--name=` | The worktree name for its databases, URL and ports. Default: the name of an earlier run, else the folder name in lower case with other characters changed to `-`. |
| `--skip-deps`, `--skip-build`, `--skip-db`, `--from-main`, `--horizon` | As in `worktree:create`. |

`worktree:setup` and `worktree:create` are safe to run again. Each step checks what is done and does only what is missing. A database that exists is never dropped, created again or migrated fresh. The one exception is a copy that stopped half way: it is recorded as incomplete, and the next run drops it and copies it again. Configuration files that exist in the worktree are kept. A run with a different name than the first run is refused.

### `worktree:bootstrap`

Show or resume the setup of a worktree in the base path.

```bash
php artisan worktree:bootstrap my-feature --status   # JSON: path, branch, head, bootstrap record
php artisan worktree:bootstrap my-feature --resume
```

| Option | Meaning |
|---|---|
| `--status` | Print the folder, branch, commit and the bootstrap record as JSON. |
| `--resume` | Run the stages that are not complete. Completed stages, copied configuration and your changes stay. Stops when the branch changed since the first run, or when a `.env` exists but the configuration stage is not recorded. |
| `--skip-deps`, `--skip-build`, `--skip-db` | As in `worktree:create`. |

Without `--status` or `--resume` the command stops. The stages are recorded in `storage/worktree-bootstrap.json`; a lock prevents two runs at the same time.

### `worktree:list`

```bash
php artisan worktree:list          # name, branch, clean or dirty, process, cloned database
php artisan worktree:list --json
```

The list holds the worktrees in the base path whose folder starts with `{project}-`. The database column shows databases of the clone strategies only.

### `worktree:open`

```bash
php artisan worktree:open my-feature   # runs: {ide.command} <folder>
php artisan worktree:open              # select from the list
```

### `worktree:delete`

```bash
php artisan worktree:delete my-feature
php artisan worktree:delete my-feature --force          # no safety questions
php artisan worktree:delete my-feature --keep-db        # keep the databases and the compose volumes
php artisan worktree:delete my-feature --delete-branch  # also delete the branch when it is merged
php artisan worktree:delete                             # select from the list
```

| Option | Meaning |
|---|---|
| `--force` | Do not ask, and do not check for uncommitted changes and unpushed commits. |
| `--keep-db` | Keep the database clone on the server (`mysql`, `pgsql`) or the shared databases, user and Redis numbers (`shared`). The compose volumes stay (`docker compose down` without `-v`). A SQLite clone is in the worktree folder, so it goes with the folder. |
| `--delete-branch` | Delete the local branch when it is merged into the base branch, without a question. |

The order:

1. The folder must be a worktree that git registers for this repository; any other folder is refused.
2. `shared` without `--keep-db`: the state file is checked (see [Safe delete](#safe-delete)). A problem stops the delete before anything changes.
3. Without `--force`: questions about uncommitted changes, unpushed commits and the delete.
4. The `ProcessManager` stops the worktree's processes.
5. `sail` and `shared`: `docker compose --profile "*" down -v` stops every service of every profile. When this fails, the checkout stays.
6. `shared`: the worktree's own databases and user are dropped, and its Redis numbers are emptied.
7. The folder is removed and `git worktree prune` runs. The command fails when the folder is still there or `git worktree list` still names it (for example files that belong to the container's user).
8. `native` and `sail`: the database clone is dropped.
9. A branch that is not merged stays, with a message. A merged branch is deleted after a question, or with `--delete-branch`.

The files that the package writes into a worktree (the bootstrap record, the state file, the setup log) do not count as uncommitted changes.

### `worktree:cleanup`

Remove the worktrees whose pull request is merged or closed, or whose issue is closed (a branch name that starts with `<number>-`). Worktrees with uncommitted changes or unpushed commits are skipped.

```bash
php artisan worktree:cleanup --dry-run   # show what would be removed
php artisan worktree:cleanup --force     # no question
```

Each removal stops the processes and containers, drops the database (or the shared databases, user and Redis numbers) and removes the folder, like `worktree:delete` without the questions and without the branch step. With `shared`, a worktree whose state file does not allow the drop is skipped with a message after its containers are stopped.

### `worktree:template` (`shared`)

```bash
php artisan worktree:template           # build the current template when it is missing
php artisan worktree:template --prune   # then drop all older templates
php artisan worktree:template --name    # print the current template name only
php artisan worktree:template -v        # show the output of the build
```

See [Template databases](#template-databases).

### `worktree:worker` (`shared`)

Show or change the queue worker of a worktree. A change recreates the app container, so the worker starts in the new mode.

```bash
php artisan worktree:worker t9                  # prints listen or horizon
php artisan worktree:worker t9 horizon
php artisan worktree:worker ../lanes/t901 listen
```

`worktree` is a name or a folder. The main checkout is refused. See [Queue worker](#queue-worker).

### `worktree:convert` (`shared`)

Move worktrees of the Sail layout (a database container and volume for each worktree) and the main checkout to shared services. Their data comes along.

```bash
php artisan worktree:convert            # table of all checkouts, then a question for each
php artisan worktree:convert --all      # convert all, no questions
php artisan worktree:convert t9         # convert one: a name, main, or a folder path
```

See [Moving Sail worktrees](#moving-sail-worktrees).

### `worktree:volumes` (`shared`)

```bash
php artisan worktree:volumes                                   # old volumes with their size
php artisan worktree:volumes --remove=shop-t9_sail-mariadb     # remove one (repeat for more)
```

`--remove` removes only volumes that are in the list.

### Agent commands

Five commands for daily work in a worktree, written for agent sessions and people alike. Without a worktree argument they act on the checkout that runs artisan; a worktree can also be given as its name (the name in its bootstrap record or its folder `{project}-{name}`), as a folder, or as `main`. A word without a slash is a name first; when no worktree has that name, an existing folder of that name in the current folder counts. `main` means the main checkout; when a worktree is also named `main`, the command refuses the word and asks for a path. With `native`, the commands run on the host and know no containers.

#### `worktree:status`

```bash
php artisan worktree:status            # this checkout
php artisan worktree:status t9 --json  # for scripts
```

Shows the folder, branch and commit, the number of uncommitted files, the URL (`APP_URL` with `${KEY}` expanded), the setup state, the compose project, the active and available compose profiles, the database state (`php artisan migrate:status` in the app container: `ok`, `pending`, `empty` or `unknown`) and each container with its state, health and memory. `--no-memory` and `--no-db` skip the slow parts.

#### `worktree:services`

Optional services are [compose profiles](https://docs.docker.com/compose/how-tos/profiles/): a service with `profiles: [workers]` runs only when `COMPOSE_PROFILES` in the worktree's `.env` names `workers`.

```bash
php artisan worktree:services                     # the profiles, their services and what runs
php artisan worktree:services workers             # exactly these profiles
php artisan worktree:services phpmyadmin --add    # add one (aliases: services.aliases)
php artisan worktree:services workers --remove
php artisan worktree:services none                # only the services without a profile
php artisan worktree:services workers -w t9       # another worktree
```

The command writes `COMPOSE_PROFILES` to the worktree's `.env`, runs `docker compose up -d`, and stops and removes the services whose profiles are off. `--no-apply` only writes `.env`. A worktree with the main checkout's compose project is refused. `services.default` sets the profiles of new worktrees (see [Configuration reference](#agent-commands-1)).

A compose file for this, with a light queue worker, Horizon and the scheduler off by default:

```yaml
services:
  laravel.test: { ... }          # always on
  worker:
    <<: *app
    profiles: [workers]
    command: ["php", "artisan", "queue:work", "--queue=high,default"]
  scheduler:
    <<: *app
    profiles: [workers]
    command: ["php", "artisan", "schedule:work"]
  horizon:
    <<: *app
    profiles: [horizon]
    command: ["php", "artisan", "horizon"]
  phpmyadmin:
    image: phpmyadmin:5.2
    profiles: [tools]
```

#### `worktree:exec`

Runs a command in the worktree's app container (`docker compose exec`), on the host with `native`. Put `--` between this command's own options and the command:

```bash
php artisan worktree:exec -- test --filter=UserTest      # php artisan test --filter=UserTest
php artisan worktree:exec -- test --parallel             # with exec.test_processes processes
php artisan worktree:exec -- artisan migrate:status
php artisan worktree:exec -- composer require foo/bar
php artisan worktree:exec -- "php -i | grep memory"      # one quoted word: a shell command
php artisan worktree:exec -w t9 --service=mariadb -- mariadb -e 'select 1'
php artisan worktree:exec -- tinker < script.php          # piped input goes to the command
```

| Option | Meaning |
|---|---|
| `-w`, `--worktree=` | The worktree; before the `--`. |
| `--service=` | The compose service. Default: `bootstrap.sail.app_service`. |

Why the `--`: artisan reads its own options `-h`, `--help`, `-V`, `--version` and `-q` anywhere on the line up to a `--`. Without it, `worktree:exec free -h` would show the help of `worktree:exec`, and `worktree:exec composer --version` would print the version of Laravel, both with exit code 0. `worktree:exec` refuses such a call without `--` (exit code 2). A call without these options also works without `--`, but scripts and agents should always write it.

The first word can be an alias of `exec.aliases` (`test`, `artisan`, `tinker`, `composer`). Each further word is quoted, so the shell in the container does not change it. The command gets a terminal when artisan has one; otherwise piped input (`< file`, `cat file |`) goes to its stdin. The exit code is the command's. A worktree with the main checkout's compose project is refused, as the command would run in the main checkout's container. When the worktree's service does not run (after an idle stop or a reboot), `worktree:exec` first starts its stack (`docker compose up -d --wait`) and says so on stderr; it never starts the main checkout. While the command runs, `worktree:exec` holds the stack lock (`storage/app/worktree/idle.lock`) shared and marks the stack as used at its start and its end (`storage/app/worktree/woken`): a command that stops the stack must take the lock exclusively, so it waits until the command ends. Without `-w`, the command runs in the checkout that runs artisan: in the main checkout, `worktree:exec -- artisan migrate` changes the main database.

#### `worktree:doctor`

```bash
php artisan worktree:doctor          # show the problems
php artisan worktree:doctor --fix    # fix them, in this order
```

| Check | Problem | Fix |
|---|---|---|
| `setup` | The bootstrap record says failed, or running while no setup holds the setup lock. | None: the hint names the command that continues the setup. |
| `env` | No `.env`. | None. |
| `compose_project` | A worktree has the main checkout's compose project. | None: no other fix runs then, as it would act on the main checkout's containers. |
| `app_url` | No `APP_URL`, or the main checkout's URL. | None. |
| `containers` | The app container or a service of an active profile does not run. | Starts them (the setup's start step, with its compose project check). |
| `vendor` | No `vendor/autoload.php`. | `composer install` in the container. |
| `node_modules` | `package.json` without `node_modules`. | The node install (then the container start again, for its volume). |
| `hot` | `public/hot` names a dev server that does not answer. | Removes `public/hot`. |
| `build` | No `public/build/manifest.json` and no `public/hot`. | The frontend build. |
| `caches` | A config, route or event cache in `bootstrap/cache`. | `config:clear`, `route:clear`, `event:clear`. |
| `database` | No migrations table, or pending migrations. | The migrations. |
| `workers` | A running container with the app container's image (Horizon, `queue:work`, `schedule:work`) started before the last code change (the last move of `HEAD` or the newest uncommitted file). With `shared` and `WORKTREE_QUEUE_WORKER=horizon`, the app container itself. | Restarts these containers. |

`--fix` takes the setup lock (`storage/worktree-bootstrap.lock`) and refuses while a setup runs, as the setup installs and migrates itself. In the main checkout it fixes only when named (`worktree:doctor main --fix`): without an argument it refuses, as its fixes would migrate the main database. The `containers` fix starts the stack with the stack lock held exclusively; every other fix holds the stack lock shared while it runs. Each fix marks the stack as used.

With `native`, the fixes run on the host with the variables of the checkout that runs artisan cleared, so they reach the target's own database. A check that cannot tell (no running container) says `skip`. The exit code is 1 when a check fails. `--json` prints the results.

#### `worktree:context`

A short summary for the start of an agent session: folder, branch, URL, what runs, the optional services, the database state, the project's notes (`context.notes`, for example where the test login is), the main commands and the last errors of `storage/logs/laravel*.log`. It never fails: what it cannot read, it leaves out. `--json` and `--no-db` as in `worktree:status`.

A Claude Code session-start hook in the project's `.claude/settings.json` prints it into each new session:

```json
{
  "hooks": {
    "SessionStart": [
      {
        "hooks": [
          { "type": "command", "command": "php artisan worktree:context 2>/dev/null || true", "timeout": 30 }
        ]
      }
    ]
  }
}
```

Where artisan runs only in a container, use the container's command instead, for example `docker compose exec -T laravel.test php artisan worktree:context`.

## Configuration reference

Publish `config/worktrees.php` to change values, or set the environment variables. Defaults in brackets. Laravel merges a published file with the package's file at the top level only: a section that you publish (for example `bootstrap` or `shared`) must hold every key of that section that you want to set by environment variable.

### General

| Key | Env | Meaning |
|---|---|---|
| `base_path` | `WORKTREE_BASE_PATH` | Folder for new worktrees. [the parent folder of the project] |
| `branch_prefix` | `WORKTREE_BRANCH_PREFIX` | Prefix of a new branch, for example `agent/`. [empty] |
| `base_branch` | `WORKTREE_BASE_BRANCH` | Base branch for new branches, and the branch that "merged" refers to. [`master`] |
| `copy_files` | | Files and folders copied from the main checkout into a new worktree. [`['.env']`] |
| `ports.app_base` | `WORKTREE_APP_PORT_BASE` | Base of the worktree's `APP_PORT`. [8100] |
| `ports.vite_base` | `WORKTREE_VITE_PORT_BASE` | Base of the worktree's `VITE_PORT`. [5200] |
| `env_overrides` | | `.env` values for each worktree (see [Env overrides](#env-overrides)). [`[]`] |
| `ide.command` | `WORKTREE_IDE_COMMAND` | Command of `worktree:open`. [`phpstorm`] |

### Bootstrap

| Key | Env | Meaning |
|---|---|---|
| `bootstrap.strategy` | `WORKTREE_BOOTSTRAP_STRATEGY` | `native`, `sail` or `shared`. [`native`] |
| `bootstrap.node_package_manager` | `WORKTREE_NODE_PM` | `pnpm`, `npm`, `yarn` or `bun`. [`pnpm`] |
| `bootstrap.build_frontend` | | Build the frontend. [true] |
| `bootstrap.run_migrations` | | Run the migrations. [true] |
| `bootstrap.sail.app_service` | `WORKTREE_SAIL_APP_SERVICE` | The compose service that runs PHP (`sail` and `shared`). [`laravel.test`] |

### Agent commands

| Key | Env | Meaning |
|---|---|---|
| `services.default` | `WORKTREE_DEFAULT_PROFILES` | Compose profiles of a new worktree, written as `COMPOSE_PROFILES` at setup (empty: none). [none: keep the main checkout's value] |
| `services.aliases` | | Other names for profiles. [`['phpmyadmin' => 'tools']`] |
| `exec.aliases` | | Short names for the first word of `worktree:exec`. [`test`, `artisan`, `tinker`, `composer`] |
| `exec.test_processes` | `WORKTREE_TEST_PROCESSES` | Processes for `php artisan test --parallel` without `--processes`, for example 8 on a shared agent host. [none: one per CPU] |
| `context.notes` | | Lines that `worktree:context` prints, for example `'Test login: test@example.com / password'`. [`[]`] |
| `context.log_errors` | | How many error lines of `laravel.log` `worktree:context` prints. [5] |

### Database clone (`native`, `sail`)

| Key | Env | Meaning |
|---|---|---|
| `database.strategy` | `WORKTREE_DB_STRATEGY` | `auto`, `sqlite`, `mysql`, `pgsql` or `none`. [`auto`] |
| `database.sqlite_copy` | | Copy the SQLite file. [true] |
| `database.mysql_docker_container` | `WORKTREE_DB_MYSQL_DOCKER_CONTAINER` | Run the MySQL clients in this container (`docker exec`). [none] |
| `database.pgsql_docker_container` | `WORKTREE_DB_PGSQL_DOCKER_CONTAINER` | Run the PostgreSQL clients in this container. [none] |
| `database.docker_host` | `WORKTREE_DB_DOCKER_HOST` | Database host inside that container. [`127.0.0.1`] |

### Shared services (`shared`)

| Key | Env | Meaning |
|---|---|---|
| `shared.stack.start_command` | `WORKTREE_SHARED_STACK_START_COMMAND` | Command that starts the shared stack, run in the main checkout. [none] |
| `shared.stack.wait_seconds` | `WORKTREE_SHARED_STACK_WAIT` | Time to wait for the database server after the start. [120] |
| `shared.app.compose_file` | `WORKTREE_APP_COMPOSE_FILE` | The app-only compose file, written to the worktree's `.env` as `COMPOSE_FILE`. [none: compose's default file] |
| `shared.dependencies.copy_on_write` | `WORKTREE_COPY_ON_WRITE` | Copy `vendor` and `node_modules` from the main checkout when the lock files are equal. [true] |
| `shared.queue.mode` | `WORKTREE_QUEUE_MODE` | Default queue worker: `listen` or `horizon`. [`listen`] |
| `shared.queue.env.mode` | | `.env` key for the worker mode. [`WORKTREE_QUEUE_WORKER`] |
| `shared.queue.env.queues` | | `.env` key for the queue list. [`WORKTREE_QUEUES`] |
| `shared.database.driver` | `WORKTREE_SHARED_DB_DRIVER` | `mariadb`, `mysql` or `pgsql`. [`mariadb`] |
| `shared.database.container` | `WORKTREE_SHARED_DB_CONTAINER` | Run the clients in this container (`docker exec`). Empty: the clients on the host connect to host and port. [none] |
| `shared.database.host` | `WORKTREE_SHARED_DB_ADMIN_HOST` | Admin connection host. [`127.0.0.1`] |
| `shared.database.port` | `WORKTREE_SHARED_DB_ADMIN_PORT` | Admin connection port. [3306, or 5432 for `pgsql`] |
| `shared.database.admin_username` | `WORKTREE_SHARED_DB_ADMIN_USERNAME` | Admin user. [`root`, or `postgres` for `pgsql`] |
| `shared.database.admin_password` | `WORKTREE_SHARED_DB_ADMIN_PASSWORD` | Admin password. [empty] |
| `shared.database.client` | `WORKTREE_SHARED_DB_CLIENT` | Client command, with options if necessary (`mariadb --skip-ssl`). [`mariadb`, `mysql` or `psql`] |
| `shared.database.dump_client` | `WORKTREE_SHARED_DB_DUMP_CLIENT` | Dump command. [`mariadb-dump`, `mysqldump` or `pg_dump`] |
| `shared.database.app_host` | `WORKTREE_SHARED_DB_HOST` | How the app containers reach the server; written to `.env`. [`mariadb`, `mysql` or `pgsql`] |
| `shared.database.app_port` | `WORKTREE_SHARED_DB_PORT` | Port for the app containers. [3306, or 5432 for `pgsql`] |
| `shared.database.main` | `WORKTREE_SHARED_DB_MAIN` | The main checkout's database. No worktree command writes to it. [`DB_DATABASE` from the main checkout's `.env`] |
| `shared.database.prefix` | `WORKTREE_SHARED_DB_PREFIX` | Prefix of all database and user names. Letters, digits and `_`. [`wt`] |
| `shared.database.parallel_workers` | `WORKTREE_SHARED_DB_PARALLEL_WORKERS` | Number of parallel test databases. [8] |
| `shared.database.copy_from_template` | | Which of `app`, `test`, `e2e` start as a template copy; the others start empty. [all three] |
| `shared.database.allow_from_main` | `WORKTREE_ALLOW_FROM_MAIN` | Allow `--from-main` on this machine. [false] |
| `shared.database.template.hash_paths` | | Files and folders of the main checkout that make the template hash. [`database/migrations`, `database/seeders`] |
| `shared.database.template.seed_command` | `WORKTREE_TEMPLATE_SEED_COMMAND` | Runs after `php artisan migrate --force` to fill the template. Empty: no seed. [`php artisan db:seed --force`] |
| `shared.database.template.runner` | `WORKTREE_TEMPLATE_RUNNER` | `container` (the main checkout's app container, connects to `app_host` and `app_port`) or `host` (connects to `host` and `port`). [`container`] |
| `shared.database.template.env` | | Extra environment for the template build, for example `['QUEUE_CONNECTION' => 'sync']`. [`[]`] |
| `shared.convert.image` | `WORKTREE_CONVERT_IMAGE` | Image of the temporary database that reads an old volume. [`mariadb:10.11`] |
| `shared.convert.volume` | `WORKTREE_CONVERT_VOLUME` | Name of a worktree's old database volume. `{compose_project}` is the old `COMPOSE_PROJECT_NAME`, else the folder name. [`{compose_project}_sail-mariadb`] |
| `shared.convert.backup_volumes` | `WORKTREE_BACKUP_VOLUMES` | Regular expression of the volumes that `worktree:volumes` lists. [`/_sail-(mariadb\|mysql\|pgsql\|redis)$/`] |
| `shared.redis.container` | `WORKTREE_SHARED_REDIS_CONTAINER` | Run `redis-cli` in this container. Empty: host and port. [none] |
| `shared.redis.host` | `WORKTREE_SHARED_REDIS_ADMIN_HOST` | Redis host for `redis-cli` on the host. [`127.0.0.1`] |
| `shared.redis.port` | `WORKTREE_SHARED_REDIS_ADMIN_PORT` | Redis port. [6379] |
| `shared.redis.password` | `WORKTREE_SHARED_REDIS_PASSWORD` | Redis password. [none] |
| `shared.redis.databases` | `WORKTREE_SHARED_REDIS_DATABASES` | Number of Redis databases; must not be more than the server's `databases` setting. [256] |
| `shared.redis.reserved` | | Redis numbers that no worktree gets (the main checkout's). [`[0, 1]`] |
| `shared.env` | | `.env` values for each worktree, with placeholders (see [The worktree's .env](#the-worktrees-env)). |
| `shared.clear_env` | | Connection URL keys that the setup empties in each worktree's `.env` (see [The worktree's .env](#the-worktrees-env)). [`['DB_URL', 'DATABASE_URL', 'REDIS_URL']`] |

## Native and Sail

### What the setup does

1. Copies `copy_files` from the main checkout. In `worktree:setup`, and when a setup continues, files that exist in the worktree are kept.
2. Changes the worktree's `.env`:
   - `APP_NAME` becomes `"{app.name} ({name})"`.
   - `APP_URL` gets `-{name}` after its first host label: `https://shop.test` becomes `https://shop-my-feature.test`.
   - With the `mysql` or `pgsql` clone, `DB_DATABASE` becomes `{database}_{name}` (`-` changed to `_`).
   - `APP_PORT` and `VITE_PORT`, only when the key is in `.env` (see [Ports](#ports)).
   - The `env_overrides` values.
3. `sail`: the node install on the host, then `docker compose up -d`, then a wait until the app service runs PHP. When the app service lost a named volume (see [App container and dependencies](#app-container-and-dependencies)), it is restarted.
4. Composer install, and with `native` the node install after it.
5. The database: the clone. A database that exists already is kept. A clone that stopped half way is dropped and made again.
6. The frontend build.
7. The migrations (not with `--skip-deps`).

With `shared` the order is different: after step 2, the shared databases are made and written to `.env`, and only then the app container starts (see [App container and dependencies](#app-container-and-dependencies)). Step 5 does not occur.

### Ports

When `.env` has `APP_PORT` or `VITE_PORT`, each worktree gets its own value: `base + (crc32(name) % 900)`. With the defaults, `APP_PORT` is in 8100 to 8999 and `VITE_PORT` in 5200 to 6099. A key that is not in `.env` is not added.

### Env overrides

`env_overrides` sets `.env` keys after the changes above. A key that exists is replaced, a missing key is added. A value is a string with the placeholders `{name}` and `{path}`, or a closure `fn (string $name, string $worktreePath): string`. Use strings when you cache the config: `php artisan config:cache` cannot store closures.

```php
'env_overrides' => [
    'COMPOSE_PROJECT_NAME' => 'shop-{name}',
    'WORKTREE_HOST' => 'shop-{name}.test',
],
```

### Compose project

With `sail` and `shared`, a worktree must have its own compose project. When the worktree's compose project is the same as the main checkout's, the setup stops: `up` and `down` would act on the main checkout's containers and volumes. Set `COMPOSE_PROJECT_NAME` per worktree with `env_overrides`. Before it runs `docker compose`, the package clears the variables that the main checkout's `.env` put into the PHP process (all `COMPOSE_*` variables, the keys of the worktree's `.env`, and others such as `DB_HOST` and `APP_URL`), so compose reads the worktree's own `.env`.

When Sail's compose file builds the app image from `vendor/laravel/sail/...`, and the worktree has no `vendor` yet, the package lends the missing files from the checkout that runs artisan (with `shared`, the main checkout) to `docker compose up` and removes them after the start.

### Databases with the Sail strategy

The `mysql` and `pgsql` clones copy on the main checkout's server. When each Sail worktree runs its own database service (the usual Sail compose file), the worktree does not use that server: set `WORKTREE_DB_STRATEGY=none`, and the migrations fill the worktree's own database. Keep `mysql` or `pgsql` only when `DB_HOST` of the worktrees points at the main checkout's server. The `sqlite` clone works with Sail: the relative path `database/database.sqlite` is correct on the host and in the container.

## Shared services

With `bootstrap.strategy = shared`, all worktrees of a machine use one database server and one Redis (the "shared stack"), and each worktree runs one app container. This makes worktrees fast to create and small, and keeps their data apart.

### What the project must provide

1. **An app-only compose file.** It holds the app service (`bootstrap.sail.app_service`) and the services that each worktree needs for itself, but no database and no Redis. Name it in `shared.app.compose_file`, for example `compose.worktree.yaml`. The app service must reach the shared server and Redis, for example through an external Docker network of the shared stack.
2. **A start command for the shared stack**, in `shared.stack.start_command`, for example `docker compose -f compose.shared.yaml up -d`. It runs in the main checkout when the database server does not answer. Worktree commands never stop, reset or upgrade the shared stack.
3. **A queue worker that reads `.env`.** The app container starts `php artisan queue:listen` or `php artisan horizon` from `WORKTREE_QUEUE_WORKER`, with the queues in `WORKTREE_QUEUES` (see [Queue worker](#queue-worker)).
4. **A `node_modules` volume for the app container**, because the bundle is built in the container and native node packages install for one platform only.
5. **`COMPOSE_PROJECT_NAME` per worktree**, for example with `env_overrides`.

A minimal `.env` of the main checkout for MariaDB, where the shared stack is in the main checkout's compose project:

```env
WORKTREE_BOOTSTRAP_STRATEGY=shared
WORKTREE_DB_STRATEGY=none
WORKTREE_APP_COMPOSE_FILE=compose.worktree.yaml
WORKTREE_SHARED_STACK_START_COMMAND="docker compose -f compose.shared.yaml up -d"
WORKTREE_SHARED_DB_CONTAINER=shop-mariadb
WORKTREE_SHARED_DB_ADMIN_PASSWORD=secret
WORKTREE_SHARED_REDIS_CONTAINER=shop-redis
```

For PostgreSQL, add `WORKTREE_SHARED_DB_DRIVER=pgsql` and use the PostgreSQL container. Everything else is the same.

### What each worktree gets

- **Its own databases:** `{prefix}_{name}` (app), `{prefix}_{name}_test`, `{prefix}_{name}_e2e`, and Laravel's parallel test databases `{prefix}_{name}_test_test_1` to `_N` (`parallel_workers`). The name is in lower case, and `-` becomes `_`.
- **A template copy:** the databases in `copy_from_template` start as a copy of the [template](#template-databases). After the copy, the migrations that are newer than the template run in the app, test and e2e databases.
- **Its own database user** (the name of the app database) with a generated password. See [The database guard](#the-database-guard).
- **Two Redis database numbers** (default and cache). See [Redis numbers](#redis-numbers).
- **A state file** (see [The state file](#the-state-file)).
- **The `.env` values** of `shared.env`, and empty connection URLs (`shared.clear_env`, see [The worktree's .env](#the-worktrees-env)).

### The worktree's `.env`

`shared.env` is the map of `.env` keys that the setup writes, with placeholders: `{db_host}`, `{db_port}`, `{db_app}`, `{db_test}`, `{db_e2e}`, `{db_user}`, `{db_password}`, `{redis_default}`, `{redis_cache}`, `{name}`. The default:

```php
'env' => [
    'DB_HOST' => '{db_host}',
    'DB_PORT' => '{db_port}',
    'DB_DATABASE' => '{db_app}',
    'DB_USERNAME' => '{db_user}',
    'DB_PASSWORD' => '{db_password}',
    'REDIS_DB' => '{redis_default}',
    'REDIS_CACHE_DB' => '{redis_cache}',
],
```

**Connection URLs:** Laravel uses `DB_URL` (or `DATABASE_URL` in configs from Laravel 10 and older) before the `DB_*` values, and `REDIS_URL` before the `REDIS_*` values. A worktree's `.env` that is a copy of the main checkout's would then connect to the main database and Redis. So the setup empties each key of `shared.clear_env` that has a value in the worktree's `.env` (default: `DB_URL`, `DATABASE_URL`, `REDIS_URL`). A key that is not in `.env` is not added. A key that `shared.env` or `env_overrides` sets keeps that value, for example `'DATABASE_URL' => 'mysql://{db_user}:{db_password}@{db_host}:{db_port}/{db_app}'`. Add the keys of your own connections that read a URL, or set `'clear_env' => []` to empty nothing. Each run of `worktree:setup`, `worktree:create` or `worktree:bootstrap --resume` checks them again, also when the database step is complete, so a run fixes a worktree that was set up with an older version. `worktree:convert` empties them in the main checkout's `.env` too; there only `shared.env` keeps a key, as `env_overrides` is not written to the main checkout. A config published before v0.2.1 has no `clear_env` key: the default applies. The `native` and `sail` strategies do not change these keys.

Add keys for the test and e2e databases when your project reads them, for example `'DB_TEST_DATABASE' => '{db_test}'`. A placeholder can be part of a longer value, such as `'REDIS_URL' => 'redis://redis:6379/{redis_default}'`. The package also reads these keys from other worktrees' `.env` to find names and numbers that are in use. The setup also writes `COMPOSE_FILE` (from `shared.app.compose_file`) and the queue worker keys.

### Names that are refused

A worktree name is refused when its databases could be the same as the databases of another worktree, a template or the main database:

- names that are or end in `test`, `e2e`, `test-N`, `e2e-N` or `test-test-N` (after a `-`), and names that start with `tpl-`;
- a name whose databases include the main database;
- a name whose databases or user another worktree uses already (also when the names differ only in case);
- a database name longer than 63 characters, or a user name longer than 32 characters on MySQL.

### The database guard

The worktree's user has rights on its exact database names and on nothing else. It cannot create, read, change or drop the main database, a template, or the database of another worktree. Names are never matched by pattern: a pattern for `t9` would also cover `t9-1`.

- **MariaDB and MySQL:** one `GRANT ALL PRIVILEGES` for each exact database name. The grant escapes `_` and `%`, which are wildcards in a `GRANT` also inside backticks. Each setup revokes all earlier rights first. The user can create its own databases again, because the rights are on the names, so `php artisan test --parallel --recreate-databases` works.
- **PostgreSQL:** see below.

### PostgreSQL

Set `shared.database.driver` to `pgsql`. The default admin user is `postgres`, the default clients are `psql` and `pg_dump`, the default port is 5432. PostgreSQL cannot limit the right to create databases to names, so it works differently:

| | MariaDB, MySQL | PostgreSQL |
|---|---|---|
| Right to create databases | On the worktree's exact names. | None (`NOCREATEDB`). The package creates **every** database in advance, the parallel test databases too, and makes the role their owner. |
| `php artisan test --parallel --recreate-databases` | Works. | Stops at the start with a clear message: the role cannot create the databases again. Run `php artisan test --parallel`; the databases exist. |
| Access of other users | Only the granted names. | `CONNECT` is revoked from `PUBLIC` on every database that the package creates. |
| Copy | `mariadb-dump \| mariadb` through a private temporary file. Definers of views, triggers, routines and events become the worktree's user. | `CREATE DATABASE … TEMPLATE …`. A source with open connections (the main database for `--from-main`) is dumped and restored. All objects in the copy are given to the worktree's role. |
| Template builder after the build | Locked (`ACCOUNT LOCK`); it stays the definer of the template's views. | `NOLOGIN`; it keeps the template's objects. |
| `worktree:convert` | Imports the old Sail volume. Stops when an old volume exists, because the volume holds MariaDB data. It stops before it removes containers or imports data; it only records the old names in the state file. |

The `--recreate-databases` guard runs when the package boots in a test worker: it acts only in a worktree that has a state file and whose default connection is `pgsql`.

### Template databases

The template is named `{prefix}_tpl_{hash}`. The hash covers the content of `template.hash_paths` in the main checkout and the seed command, so a changed migration or seeder gives a new template.

The build runs `php artisan migrate --force` and then `template.seed_command` with the main checkout's code: in the main checkout's app container (`template.runner = container`) or on the host (`host`). It connects as a builder user that has rights on the template only, with the config read fresh (a config cache of the main checkout is not used) and `DB_URL` and `DATABASE_URL` empty. Builds run under a lock: a second setup waits for the first build and then uses it. A template is ready when its database exists and its marker exists in the main checkout's `storage/app/worktrees/templates/`. A database without its marker is a stopped build; the next run builds it again.

Old templates stay until `php artisan worktree:template --prune`.

### Redis numbers

Each worktree gets the two lowest free numbers from 0 to `redis.databases - 1`. A number is free when no other worktree names it in its state file or in its `.env`, and when it is not in `redis.reserved` (the main checkout's numbers, 0 and 1 by default). The allocation runs under a lock in the main checkout, so two setups at the same time get different numbers. When no numbers are free, the setup stops: delete worktrees or set a higher `databases` value on the Redis server and in `redis.databases`.

### The state file

`storage/app/worktree/state.json` in the worktree (mode `0600`) records the worktree name, branch, database names, user, password, Redis numbers, compose project, template and the source of the app database (`template`, `main`, `converted` or `existing`). `storage/app` is ignored by git in Laravel projects.

A missing or broken state file does not stop a setup: the setup makes it again from the derived names. It keeps the password from `.env` when `.env` names the same user. `worktree:delete` never guesses: without a good state file it drops nothing (see [Safe delete](#safe-delete)).

### App container and dependencies

- **Shared stack:** when the database server does not answer, `shared.stack.start_command` runs in the main checkout, and the setup waits up to `stack.wait_seconds` until the server answers. Without a start command, the setup stops with a message.
- **Order:** the databases are made and `.env` names them before the app container starts, so the worker and the scheduler start with the right database.
- **Host node install first:** the copy of `node_modules` and the node install on the host run before `docker compose up -d`. On Linux, a host install that replaces the `node_modules` folder (pnpm removes an empty one and makes it again) detaches the volume that a running container mounts there; the container then builds with the host's packages.
- **App container:** `docker compose up -d` in the worktree, then a wait until the app service runs PHP. When the app service declares a named volume that its container does not mount (a container that already ran while `node_modules` was replaced on the host), the app service is restarted once; when the volume is still missing, the setup stops. A `public/hot` whose dev server does not answer is removed.
- **Dependencies:** when `composer.lock` is the same as in the main checkout, `vendor` is copied from the main checkout with copy-on-write (`cp -c` on macOS with APFS, `cp --reflink=always` on other systems). The same applies to the host `node_modules` and the node lock file (`pnpm-lock.yaml`, `package-lock.json`, `yarn.lock`, `bun.lock`, `bun.lockb`). Then `composer install` runs in the container and the node install on the host in all cases; after a copy they are fast. A file system without copy-on-write (for example ext4) skips the copy. Set `shared.dependencies.copy_on_write` to false to never copy.
- **Frontend:** built in the container: `pnpm install --frozen-lockfile && pnpm run build` (`npm ci && npm run build` for npm).
- **Migrations:** run in the container for the app, test and e2e databases, with the config read fresh.

### Queue worker

The setup writes two keys to the worktree's `.env` for your container to read:

- `WORKTREE_QUEUE_WORKER`: `listen` (`php artisan queue:listen`, which always runs the current code) or `horizon`. The default is `shared.queue.mode`; `--horizon` selects Horizon.
- `WORKTREE_QUEUES`: a comma-separated list. When the project requires `laravel/horizon` (in `require` or `require-dev`) and has `config/horizon.php`, the list holds every queue that its defaults and environments name. Else `default`.

`php artisan worktree:worker <worktree> <mode>` changes the mode later and recreates the app container. The key names can be changed in `shared.queue.env`.

An example entrypoint of the app container:

```sh
if [ "$WORKTREE_QUEUE_WORKER" = "horizon" ]; then
  php artisan horizon
else
  php artisan queue:listen --queue="${WORKTREE_QUEUES:-default}"
fi
```

### Setup and resume

`worktree:create` and `worktree:setup` can run again on the same worktree, for example after a failure or a stopped process. They continue where the earlier run stopped:

- A database that exists is kept. A copy that stopped half way is recorded in the state file as pending; the next run drops it and copies again.
- A run that was started with `--from-main` keeps that choice when it continues.
- `--skip-db` works with shared services only when the worktree has its own databases already; else its `.env` would name the main database.
- The log is in `storage/logs/worktree-setup.log`; use `-v` to see the output in the terminal.

### Production-like data

`worktree:create --from-main` (and `worktree:setup --from-main`) copies the main database into the worktree's app database in place of the template. It works only where `shared.database.allow_from_main` is true (`WORKTREE_ALLOW_FROM_MAIN=true`); on other machines it is refused. Use it only on machines where production-like data may be.

### Moving Sail worktrees

`worktree:convert` moves worktrees of the Sail layout to shared services. It shows a table of the main checkout and all worktrees: branch, date of the last commit, whether the branch is merged into the base branch, and whether it is converted. Then it asks for each one: convert, delete (suggested when merged), or skip. `--all` converts all without questions. A name (`main` for the main checkout) or a folder path (when several worktrees have the same name) converts one. The main checkout converts first; when it fails, the command stops. A converted checkout is skipped on the next run.

For each checkout the conversion:

1. Removes the old containers, found by their compose project label, so the old compose file is not necessary.
2. Starts a temporary database container (`shared.convert.image`, no network, `--skip-grant-tables`) on the old volume (`shared.convert.volume`), dumps the old database and removes the container.
3. Imports the dump into the new app database on the shared server. The definers in the dump become the new user. An empty or missing old volume gives a template copy, with a message.
4. Sets up the rest like `worktree:setup`.

The main checkout's data goes into the main database. Its user has the name of the main database, and its Redis numbers are the reserved ones. The removal of the main checkout's old containers can also remove shared containers in the same compose project: after the old volume is read, the start command runs again to bring them back.

A conversion that stops can run again: the first run records the old compose project and database name in the state file. When the `.env` already names a shared database, write `old_database` and `old_project` into the state file by hand; the error message says so. A shared database that exists already without a recorded import stops the conversion, so old data is not lost without a message.

"Delete" in the question runs `worktree:delete`. For a worktree that was never set up with shared services, it keeps the old volume, like `--keep-db`.

**The old volumes stay as backup.** `worktree:volumes` lists the volumes that match `shared.convert.backup_volumes`, with their size. `--remove=NAME` (repeatable) removes volumes from that list only.

### Safe delete

`worktree:delete` checks the state file before it stops anything:

- The state file must exist and belong to this worktree.
- Its database list and Redis numbers must be complete and valid.
- Every database must be one of the names derived from the worktree name, and neither the main database nor a template.
- The user must be the worktree's own user.
- No other worktree or the main checkout may use the same Redis numbers.

When one check fails, the delete stops and nothing is changed. Without a state file nothing is guessed: drop the databases by hand, or use `--keep-db`. After the checks, the delete reaches Redis first (so a Redis problem leaves the worktree complete), empties the Redis numbers with `FLUSHDB`, drops the databases and the user, and then removes the folder.

## Files the package writes

In a worktree:

| File | Content |
|---|---|
| `storage/worktree-bootstrap.json` | The bootstrap record: name, branch, first commit, completed stages, status. |
| `storage/worktree-bootstrap.lock` | Lock of a running setup. |
| `storage/worktree-bootstrap.clone` | Marker of a running database clone (`native`, `sail`). |
| `storage/logs/worktree-setup.log` | Log of each setup. |
| `storage/app/worktree/state.json` | `shared`: the state file. |

In the main checkout (`shared`): `storage/app/worktrees/templates/*.ready` (template markers), `storage/app/worktrees/template.lock` and `storage/app/worktrees/allocate.lock`.

## ProcessManager contract

The package binds a `NullProcessManager`. To stop a worktree's processes (for example screen or tmux sessions) on delete and cleanup, and to show them in `worktree:list`, implement `Woda\Worktrees\Contracts\ProcessManager`:

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

## Development

```bash
composer install
composer test    # Pest
composer check   # Pint, PHPStan, Rector, copy-paste detector
```

The integration tests run against real MariaDB and PostgreSQL servers; see [tests/Integration/README.md](tests/Integration/README.md).

## License

MIT
