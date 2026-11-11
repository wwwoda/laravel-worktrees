<?php

namespace Woda\Worktrees;

use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * The lock that a worktree setup holds (`storage/worktree-bootstrap.lock`).
 * Commands that install, migrate or start containers in a worktree take it
 * too, so they never run at the same time as a setup.
 */
final class SetupLock
{
    /**
     * The lock, or null when a setup (or another fix) holds it.
     *
     * @return resource|null
     */
    public static function tryAcquire(string $path): mixed
    {
        File::ensureDirectoryExists($path.'/storage');
        $handle = fopen($path.'/storage/worktree-bootstrap.lock', 'c');
        if ($handle === false) {
            throw new RuntimeException('Cannot open the worktree bootstrap lock.');
        }
        if (! flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return null;
        }

        return $handle;
    }

    /**
     * @param  resource  $handle
     */
    public static function release(mixed $handle): void
    {
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    /**
     * Whether a setup holds the lock now. Without a lock file nothing holds it.
     */
    public static function isHeld(string $path): bool
    {
        if (! is_file($path.'/storage/worktree-bootstrap.lock')) {
            return false;
        }
        $handle = self::tryAcquire($path);
        if ($handle === null) {
            return true;
        }
        self::release($handle);

        return false;
    }
}
