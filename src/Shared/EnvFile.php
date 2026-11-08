<?php

namespace Woda\Worktrees\Shared;

use Dotenv\Exception\InvalidFileException;
use Dotenv\Parser\Parser;
use Illuminate\Support\Facades\File;

/**
 * Reads and upserts `KEY=value` lines of a `.env` file. Reads parse the file as
 * Laravel's dotenv does (quotes, inline comments, `export`). Values are written
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

    /**
     * The value as dotenv reads it, without variable expansion. A file that dotenv
     * cannot parse has no values: a guess could name the wrong database.
     */
    public static function get(string $path, string $key): ?string
    {
        if (! File::exists($path)) {
            return null;
        }
        try {
            $entries = (new Parser)->parse(File::get($path));
        } catch (InvalidFileException) {
            return null;
        }
        $value = null;
        // The last line wins, as in dotenv.
        foreach ($entries as $entry) {
            if ($entry->getName() === $key) {
                $value = $entry->getValue()->map(static fn ($value): string => $value->getChars())->getOrElse('');
            }
        }

        return $value;
    }
}
