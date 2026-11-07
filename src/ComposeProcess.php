<?php

namespace Woda\Worktrees;

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * A process for `docker compose` in a worktree folder, with the variables
 * that the parent checkout's `.env` put into this PHP process cleared, so
 * compose reads the worktree's own `.env` (see SailBootstrapStrategy).
 */
final class ComposeProcess
{
    /**
     * Vars that are typically set per-worktree in `.env` and would otherwise
     * leak from the parent process and override the new worktree's values.
     */
    public const INHERITED_VARS_TO_CLEAR = [
        'COMPOSE_PROJECT_NAME',
        'COMPOSE_FILE',
        'COMPOSE_PROFILES',
        'WORKTREE_HOST',
        'APP_NAME',
        'APP_URL',
        'APP_KEY',
        'DB_DATABASE',
        'DB_HOST',
        'DB_PORT',
        'DB_USERNAME',
        'DB_PASSWORD',
        'REDIS_HOST',
        'REDIS_PORT',
        'MAIL_HOST',
        'MAIL_PORT',
    ];

    public static function in(string $worktreePath, int $timeout): PendingProcess
    {
        $keys = self::INHERITED_VARS_TO_CLEAR;
        if (File::exists($worktreePath.'/.env')) {
            preg_match_all('/^(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=/m', File::get($worktreePath.'/.env'), $matches);
            $keys = [...$keys, ...$matches[1]];
        }

        return Process::path($worktreePath)->timeout($timeout)->env(array_fill_keys($keys, false));
    }
}
