<?php

namespace Woda\Worktrees\Shared;

use Illuminate\Support\Facades\Process;

/**
 * The main checkout of the repository, also when artisan runs inside a
 * linked worktree: the folder that holds the common `.git` directory.
 * Templates, their markers and the shared locks live there, so every
 * worktree of the project uses the same ones.
 */
final class MainCheckout
{
    private static ?string $cached = null;

    private static ?string $cachedFor = null;

    public static function path(): string
    {
        $base = base_path();
        if (self::$cachedFor === $base && self::$cached !== null) {
            return self::$cached;
        }
        $result = Process::path($base)->timeout(10)->run('git rev-parse --path-format=absolute --git-common-dir');
        $commonDir = trim($result->output());
        $path = $result->successful() && $commonDir !== '' && basename($commonDir) === '.git' && is_dir(dirname($commonDir))
            ? dirname($commonDir)
            : $base;
        self::$cachedFor = $base;

        return self::$cached = $path;
    }

    public static function file(string $relative): string
    {
        return rtrim(self::path(), '/').'/'.ltrim($relative, '/');
    }

    public static function forget(): void
    {
        self::$cached = null;
        self::$cachedFor = null;
    }
}
