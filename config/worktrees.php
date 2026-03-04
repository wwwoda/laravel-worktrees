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
        'node_package_manager' => env('WORKTREE_NODE_PM', 'pnpm'),
        'build_frontend' => true,
        'run_migrations' => true,
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
