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
