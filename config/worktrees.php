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
        |                        Vite + node-pm stay on host.
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
        'database' => [
            // mariadb | mysql (pgsql follows)
            'driver' => env('WORKTREE_SHARED_DB_DRIVER', 'mariadb'),

            // How the package reaches the server as admin. With a container,
            // the client runs inside it (`docker exec`); without, the client
            // on this machine connects to host and port.
            'container' => env('WORKTREE_SHARED_DB_CONTAINER'),
            'host' => env('WORKTREE_SHARED_DB_ADMIN_HOST', '127.0.0.1'),
            'port' => (int) env('WORKTREE_SHARED_DB_ADMIN_PORT', 3306),
            'admin_username' => env('WORKTREE_SHARED_DB_ADMIN_USERNAME', 'root'),
            'admin_password' => env('WORKTREE_SHARED_DB_ADMIN_PASSWORD', ''),
            // Client commands, with options if needed (e.g. 'mariadb --skip-ssl').
            'client' => env('WORKTREE_SHARED_DB_CLIENT', 'mariadb'),
            'dump_client' => env('WORKTREE_SHARED_DB_DUMP_CLIENT', 'mariadb-dump'),

            // How the app containers reach the server (written to `.env`).
            'app_host' => env('WORKTREE_SHARED_DB_HOST', 'mariadb'),
            'app_port' => (int) env('WORKTREE_SHARED_DB_PORT', 3306),

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
                // 'container': in the main checkout's app container; 'host': on this machine.
                'runner' => env('WORKTREE_TEMPLATE_RUNNER', 'container'),
                // Extra environment for the template build, e.g. ['QUEUE_CONNECTION' => 'sync'].
                'env' => [],
            ],
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
    | IDE
    |--------------------------------------------------------------------------
    */

    'ide' => [
        'command' => env('WORKTREE_IDE_COMMAND', 'phpstorm'),
    ],
];
