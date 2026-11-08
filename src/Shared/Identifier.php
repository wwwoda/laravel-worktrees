<?php

namespace Woda\Worktrees\Shared;

use RuntimeException;

/**
 * Database and user names, and generated passwords, are restricted to safe
 * characters, so they can go into SQL and shell commands without quoting games.
 */
final class Identifier
{
    public static function check(string $name): string
    {
        if (! preg_match('/^[A-Za-z0-9_]{1,63}$/', $name)) {
            throw new RuntimeException("Unsafe database identifier '{$name}'.");
        }

        return $name;
    }

    public static function password(string $password): string
    {
        if (! preg_match('/^[A-Za-z0-9]{16,128}$/', $password)) {
            throw new RuntimeException('Unsafe generated password.');
        }

        return $password;
    }
}
