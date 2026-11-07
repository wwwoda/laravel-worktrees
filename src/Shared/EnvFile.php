<?php

namespace Woda\Worktrees\Shared;

use Illuminate\Support\Facades\File;

/**
 * Reads and upserts `KEY=value` lines of a `.env` file. Values are written
 * literally: `$1` or `\0` in a value is never read as a back-reference.
 */
final class EnvFile
{
    /**
     * @param  array<string, string>  $values
     */
    public static function upsert(string $path, array $values): void
    {
        $content = File::exists($path) ? File::get($path) : '';
        foreach ($values as $key => $value) {
            $content = self::upsertLine($content, $key, $key.'='.$value);
        }
        File::put($path, $content);
    }

    public static function upsertLine(string $content, string $key, string $line): string
    {
        $pattern = '/^'.preg_quote($key, '/').'=.*/m';
        if (preg_match($pattern, $content)) {
            return (string) preg_replace_callback($pattern, static fn (): string => $line, $content);
        }

        return ($content === '' ? '' : rtrim($content, "\n")."\n").$line."\n";
    }

    public static function get(string $path, string $key): ?string
    {
        if (! File::exists($path)) {
            return null;
        }
        if (! preg_match('/^'.preg_quote($key, '/').'=(.*)$/m', File::get($path), $match)) {
            return null;
        }

        return trim($match[1], " \t\"'");
    }
}
