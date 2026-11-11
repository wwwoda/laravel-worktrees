<?php

namespace Woda\Worktrees\Agent;

use Closure;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * The lock of a checkout's compose stack and the time it was last used by a
 * command. A command that starts a stopped stack takes the lock exclusively
 * (locked()); a command that runs something in the stack (worktree:exec, a
 * fix of worktree:doctor) holds it shared for the whole run (shared()) and
 * marks the use at its start and its end. A command that stops a stack must take
 * the lock exclusively, so it waits for every running command, and must skip
 * a stack used since its check, so it cannot follow a start unseen.
 */
final class StackLock
{
    public const LOCK = 'storage/app/worktree/idle.lock';

    public const USED = 'storage/app/worktree/woken';

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function locked(string $path, Closure $callback): mixed
    {
        return self::hold($path, LOCK_EX, $callback);
    }

    /**
     * Runs the callback with the lock held shared: other commands may run in
     * the stack at the same time, a stop (exclusive) waits until all ended.
     * With $markUse, the use is marked at the start and at the end.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function shared(string $path, Closure $callback, bool $markUse = true): mixed
    {
        return self::hold($path, LOCK_SH, static function () use ($path, $callback, $markUse): mixed {
            $markUse && self::markUsed($path);
            try {
                return $callback();
            } finally {
                $markUse && self::markUsed($path);
            }
        });
    }

    /**
     * @template T
     *
     * @param  LOCK_SH|LOCK_EX  $operation
     * @param  Closure(): T  $callback
     * @return T
     */
    private static function hold(string $path, int $operation, Closure $callback): mixed
    {
        File::ensureDirectoryExists(dirname($path.'/'.self::LOCK));
        $handle = fopen($path.'/'.self::LOCK, 'c');
        if ($handle === false) {
            throw new RuntimeException("Cannot open the stack lock of {$path}.");
        }
        try {
            if (! flock($handle, $operation)) {
                throw new RuntimeException("Cannot lock the stack of {$path}.");
            }

            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public static function markUsed(string $path): void
    {
        File::ensureDirectoryExists(dirname($path.'/'.self::USED));
        File::put($path.'/'.self::USED, date(DATE_ATOM)."\n");
    }

    /**
     * When a command last started or used the stack (Unix time); null when never.
     */
    public static function lastUsed(string $path): ?int
    {
        clearstatcache(true, $path.'/'.self::USED);

        return is_file($path.'/'.self::USED) ? (int) filemtime($path.'/'.self::USED) : null;
    }
}
