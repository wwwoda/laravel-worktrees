<?php

namespace Woda\Worktrees\Fast;

use Closure;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Woda\Worktrees\Shared\MainCheckout;

/**
 * A lock file in the main checkout's `storage/app/worktrees/`, so two setups
 * of the same project at the same time do one shared job (an image build) once.
 */
final class MainLock
{
    /**
     * Run `$job` under the lock `$name`; a second caller waits for the first.
     *
     * @template T
     *
     * @param  Closure(): T  $job
     * @param  (Closure(string): void)|null  $onWait
     * @return T
     */
    public static function run(string $name, Closure $job, int $timeout = 900, ?Closure $onWait = null): mixed
    {
        $path = MainCheckout::file('storage/app/worktrees/'.$name.'.lock');
        File::ensureDirectoryExists(dirname($path));
        $handle = fopen($path, 'c');
        if ($handle === false) {
            throw new RuntimeException("Cannot open the lock {$path}.");
        }
        try {
            $deadline = time() + $timeout;
            $told = false;
            while (! flock($handle, LOCK_EX | LOCK_NB)) {
                if (time() >= $deadline) {
                    throw new RuntimeException("Timed out waiting for the lock {$path}.");
                }
                if (! $told && $onWait instanceof Closure) {
                    $onWait("Another setup holds {$name}; waiting...");
                    $told = true;
                }
                usleep(250_000);
            }

            return $job();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
