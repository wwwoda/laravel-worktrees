<?php

return [
    'base_path' => env('WORKTREE_BASE_PATH', dirname(base_path())),
    'branch_prefix' => env('WORKTREE_BRANCH_PREFIX', ''),
    'base_branch' => env('WORKTREE_BASE_BRANCH', 'master'),
    'copy_files' => ['.env'],

    /*
    |--------------------------------------------------------------------------
    | Ports
    |--------------------------------------------------------------------------
    |
    | Base ports for deriving unique APP_PORT and VITE_PORT per worktree.
    | Each worktree gets an offset (crc32 of name mod 900) added to the base.
    | Only applied when the key already exists in the worktree's .env file.
    |
    */

    'ports' => [
        'app_base' => (int) env('WORKTREE_APP_PORT_BASE', 8100),
        'vite_base' => (int) env('WORKTREE_VITE_PORT_BASE', 5200),
    ],

    /*
    |--------------------------------------------------------------------------
    | Database Cloning
    |--------------------------------------------------------------------------
    */

    'database' => [
        'strategy' => env('WORKTREE_DB_STRATEGY', 'auto'),
        'sqlite_copy' => true,
        'mysql_docker_container' => env('WORKTREE_DB_MYSQL_DOCKER_CONTAINER'),
        'pgsql_docker_container' => env('WORKTREE_DB_PGSQL_DOCKER_CONTAINER'),
        'docker_host' => env('WORKTREE_DB_DOCKER_HOST', '127.0.0.1'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Bootstrap
    |--------------------------------------------------------------------------
    */

    'bootstrap' => [
        /*
        | strategy: how bootstrap steps run.
        |   'native' (default) → composer/npm/migrate executed directly on the host.
        |   'sail'             → `docker compose up -d` first, PHP/composer/artisan
        |                        commands run via `docker compose exec laravel.test ...`
        |                        Vite + node-pm stay on host. With a database
        |                        service per worktree, set database.strategy to
        |                        'none' (the clone strategies copy on the main
        |                        checkout's server).
        |   'shared'           → like 'sail', with the databases, user and Redis
        |                        numbers of each worktree on shared services
        |                        (see 'shared' below).
        */
        'strategy' => env('WORKTREE_BOOTSTRAP_STRATEGY', 'native'),

        'node_package_manager' => env('WORKTREE_NODE_PM', 'pnpm'),
        'build_frontend' => true,
        'run_migrations' => true,

        'sail' => [
            'app_service' => env('WORKTREE_SAIL_APP_SERVICE', 'laravel.test'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Shared services (bootstrap strategy 'shared')
    |--------------------------------------------------------------------------
    |
    | One database server and one Redis per machine, used by every worktree.
    | Each worktree gets its own databases, copied from a template database,
    | and its own database user with rights on its exact database names only.
    | Set 'database.strategy' to 'none' when you use this.
    |
    */

    'shared' => [
        // The machine's shared stack. Started with this command (run in the
        // main checkout) when the database server does not answer; never
        // stopped, reset or upgraded by a worktree command.
        'stack' => [
            'start_command' => env('WORKTREE_SHARED_STACK_START_COMMAND'),
            'wait_seconds' => (int) env('WORKTREE_SHARED_STACK_WAIT', 120),
        ],

        // The worktree's app container: the compose file (written to the
        // worktree's `.env` as COMPOSE_FILE) and the service from
        // 'bootstrap.sail.app_service'. The bundle is built inside it.
        // Empty: the project's default compose file, which must then be
        // app-only (no database or Redis service of its own).
        'app' => [
            'compose_file' => env('WORKTREE_APP_COMPOSE_FILE'),
        ],

        // Copy `vendor` and the host `node_modules` from the main checkout with
        // copy-on-write when the lock files are equal; otherwise install.
        'dependencies' => [
            'copy_on_write' => (bool) env('WORKTREE_COPY_ON_WRITE', true),
        ],

        // The queue worker of the app container: 'listen' (`queue:listen`, always
        // runs the current code) or 'horizon'. Written to `.env` for the
        // project's container to read, with the queues from config/horizon.php.
        'queue' => [
            'mode' => env('WORKTREE_QUEUE_MODE', 'listen'),
            'env' => [
                'mode' => 'WORKTREE_QUEUE_WORKER',
                'queues' => 'WORKTREE_QUEUES',
            ],
        ],

        'database' => [
            // mariadb | mysql | pgsql
            'driver' => env('WORKTREE_SHARED_DB_DRIVER', 'mariadb'),

            // How the package reaches the server as admin. With a container,
            // the client runs inside it (`docker exec`); without, the client
            // on this machine connects to host and port.
            'container' => env('WORKTREE_SHARED_DB_CONTAINER'),
            'host' => env('WORKTREE_SHARED_DB_ADMIN_HOST', '127.0.0.1'),
            // Empty: 3306 (MariaDB, MySQL) or 5432 (PostgreSQL).
            'port' => env('WORKTREE_SHARED_DB_ADMIN_PORT'),
            // Empty: root (MariaDB, MySQL) or postgres (PostgreSQL).
            'admin_username' => env('WORKTREE_SHARED_DB_ADMIN_USERNAME'),
            'admin_password' => env('WORKTREE_SHARED_DB_ADMIN_PASSWORD', ''),
            // Client commands, with options if needed (e.g. 'mariadb --skip-ssl').
            // Empty: mariadb/mariadb-dump, mysql/mysqldump or psql/pg_dump by driver.
            'client' => env('WORKTREE_SHARED_DB_CLIENT'),
            'dump_client' => env('WORKTREE_SHARED_DB_DUMP_CLIENT'),

            // How the app containers reach the server (written to `.env`).
            // Empty: mariadb, mysql or pgsql (the Sail service names) and the driver's port.
            'app_host' => env('WORKTREE_SHARED_DB_HOST'),
            'app_port' => env('WORKTREE_SHARED_DB_PORT'),

            // The main checkout's database. Never written by a worktree command.
            // Empty: read DB_DATABASE from the main checkout's `.env` (also when
            // artisan runs in a linked worktree, whose `.env` names its own database).
            'main' => env('WORKTREE_SHARED_DB_MAIN'),

            // Names: {prefix}_{name}, {prefix}_{name}_test, {prefix}_{name}_e2e,
            // and Laravel's parallel test databases {prefix}_{name}_test_test_{1..N}.
            'prefix' => env('WORKTREE_SHARED_DB_PREFIX', 'wt'),
            'parallel_workers' => (int) env('WORKTREE_SHARED_DB_PARALLEL_WORKERS', 8),

            // Which databases start as a copy of the template; the others start empty.
            'copy_from_template' => ['app', 'test', 'e2e'],

            // `worktree:create --from-main` copies the main database into the
            // worktree's app database. Allow it only where production-like data may go.
            'allow_from_main' => (bool) env('WORKTREE_ALLOW_FROM_MAIN', false),

            'template' => [
                // Files and folders of the main checkout whose content makes the template hash.
                'hash_paths' => ['database/migrations', 'database/seeders'],
                // Runs after `php artisan migrate --force` to fill the template.
                'seed_command' => env('WORKTREE_TEMPLATE_SEED_COMMAND', 'php artisan db:seed --force'),
                // 'container': in the main checkout's app container (connects to app_host and app_port);
                // 'host': on this machine (connects to host and port, like the admin connection).
                'runner' => env('WORKTREE_TEMPLATE_RUNNER', 'container'),
                // Extra environment for the template build, e.g. ['QUEUE_CONNECTION' => 'sync'].
                'env' => [],
            ],
        ],

        // worktree:convert and worktree:volumes (moving Sail worktrees).
        'convert' => [
            // Image of the temporary database that reads an old volume.
            'image' => env('WORKTREE_CONVERT_IMAGE', 'mariadb:10.11'),
            // The old database volume of a worktree; {compose_project} is the
            // old COMPOSE_PROJECT_NAME (else the folder name).
            'volume' => env('WORKTREE_CONVERT_VOLUME', '{compose_project}_sail-mariadb'),
            // Volumes that worktree:volumes lists as backups (a regular expression).
            'backup_volumes' => env('WORKTREE_BACKUP_VOLUMES', '/_sail-(mariadb|mysql|pgsql|redis)$/'),
        ],

        'redis' => [
            // How worktree:delete reaches the shared Redis to empty a deleted
            // worktree's numbers: inside its container, or host and port.
            'container' => env('WORKTREE_SHARED_REDIS_CONTAINER'),
            'host' => env('WORKTREE_SHARED_REDIS_ADMIN_HOST', '127.0.0.1'),
            'port' => (int) env('WORKTREE_SHARED_REDIS_ADMIN_PORT', 6379),
            'password' => env('WORKTREE_SHARED_REDIS_PASSWORD'),
            // Must not exceed the shared Redis `databases` setting.
            'databases' => (int) env('WORKTREE_SHARED_REDIS_DATABASES', 256),
            // Numbers no worktree gets (the main checkout's).
            'reserved' => [0, 1],
        ],

        // Written to each worktree's `.env`. Placeholders: {db_host}, {db_port},
        // {db_app}, {db_test}, {db_e2e}, {db_user}, {db_password},
        // {redis_default}, {redis_cache}, {name}.
        'env' => [
            'DB_HOST' => '{db_host}',
            'DB_PORT' => '{db_port}',
            'DB_DATABASE' => '{db_app}',
            'DB_USERNAME' => '{db_user}',
            'DB_PASSWORD' => '{db_password}',
            'REDIS_DB' => '{redis_default}',
            'REDIS_CACHE_DB' => '{redis_cache}',
        ],

        // Connection URLs that a worktree's `.env` took over from the main
        // checkout. Laravel gives a URL precedence over DB_* and REDIS_*, so
        // each key that has a value is emptied, unless 'env' above or
        // 'env_overrides' sets it. Empty list: nothing is emptied.
        'clear_env' => ['DB_URL', 'DATABASE_URL', 'REDIS_URL'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Env overrides
    |--------------------------------------------------------------------------
    |
    | Per-worktree .env upserts applied AFTER copy + standard rewrites
    | (APP_NAME / APP_URL / DB_DATABASE / APP_PORT / VITE_PORT).
    |
    | Map of `KEY => string|Closure(string $name, string $worktreePath): string`.
    | Existing lines are replaced; missing lines are appended. Use closures for
    | name-derived values:
    |
    |   'env_overrides' => [
    |       'WORKTREE_HOST' => fn(string $name) => "app-{$name}.hp.test",
    |       'COMPOSE_PROJECT_NAME' => fn(string $name) => "hp-{$name}",
    |   ],
    */

    'env_overrides' => [],

    /*
    |--------------------------------------------------------------------------
    | Idle stacks (worktree:idle-stop, worktree:wake)
    |--------------------------------------------------------------------------
    |
    | worktree:idle-stop stops the containers of worktrees unused for 'minutes'
    | (no traffic from the app container, no code change, no log write). Run it
    | from a host timer. An agent that
    | waits for CI or a review with a clean tree uses nothing for a while:
    | keep 'minutes' well above such a wait. worktree:exec and
    | worktree:doctor --fix start a stopped stack again.
    */

    'idle' => [
        'minutes' => (int) env('WORKTREE_IDLE_MINUTES', 120),
        'include_main' => (bool) env('WORKTREE_IDLE_INCLUDE_MAIN', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | IDE
    |--------------------------------------------------------------------------
    */

    'ide' => [
        'command' => env('WORKTREE_IDE_COMMAND', 'phpstorm'),
    ],
];
