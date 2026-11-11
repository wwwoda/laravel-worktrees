<?php

namespace Woda\Worktrees\Agent;

use Closure;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * The lock of a checkout's compose stack and the time it was last used by a
 * command. Every command that starts a stack or runs something in it takes
 * the lock and marks the use; a stop of an idle stack takes the lock too and
 * skips a stack used since its check. So a stop cannot follow a start unseen.
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
        File::ensureDirectoryExists(dirname($path.'/'.self::LOCK));
        $handle = fopen($path.'/'.self::LOCK, 'c');
        if ($handle === false) {
            throw new RuntimeException("Cannot open the stack lock of {$path}.");
        }
        try {
            flock($handle, LOCK_EX);

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
