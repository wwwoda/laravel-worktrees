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

    /**
     * Replace each line that sets `$key` with `$line`, also in the forms dotenv
     * accepts (`export KEY=`, spaces around `=`), or append `$line`.
     */
    public static function upsertLine(string $content, string $key, string $line): string
    {
        $pattern = '/^[ \t]*(?:export[ \t]+)?'.preg_quote($key, '/').'[ \t]*=.*/m';
        if (preg_match($pattern, $content)) {
            return (string) preg_replace_callback($pattern, static fn (): string => $line, $content);
        }

        return ($content === '' ? '' : rtrim($content, "\n")."\n").$line."\n";
    }

    /**
     * The value as dotenv reads it, without variable expansion. When dotenv cannot
     * parse the whole file, each line is read on its own, so a broken line hides
     * only its own key: a key whose line is broken has no value, as a guess could
     * name the wrong database.
     */
    public static function get(string $path, string $key): ?string
    {
        if (! File::exists($path)) {
            return null;
        }
        $parser = new Parser;
        $content = File::get($path);
        try {
            $entries = $parser->parse($content);
        } catch (InvalidFileException) {
            $entries = [];
            foreach (preg_split('/\r?\n/', $content) ?: [] as $line) {
                try {
                    $entries = [...$entries, ...$parser->parse($line)];
                } catch (InvalidFileException) {
                    // The broken line itself.
                    $entries = array_values(array_filter($entries, static fn ($entry): bool => $entry->getName() !== self::lineKey($line)));
                }
            }
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

    private static function lineKey(string $line): string
    {
        return preg_match('/^\s*(?:export\s+)?([A-Za-z0-9_.]+)\s*=/', $line, $match) ? $match[1] : '';
    }

    /**
     * The values the placeholders of a `.env` map value (`{db_app}`,
     * `{redis_default}`) have in a value rendered from it, by placeholder name.
     * Empty when the value no longer has the form of the map value.
     *
     * @return array<string, string>
     */
    public static function placeholderValues(string $template, string $value): array
    {
        $parts = preg_split('/\{([a-z_]+)\}/', $template, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $pattern = '';
        $names = [];
        foreach ($parts as $index => $part) {
            if ($index % 2 === 1) {
                $names[] = $part;
                $pattern .= '(.*?)';
            } else {
                $pattern .= preg_quote($part, '/');
            }
        }
        if (! preg_match('/^'.$pattern.'$/s', $value, $match)) {
            return [];
        }
        $values = [];
        foreach ($names as $index => $name) {
            $values[$name] = $match[$index + 1];
        }

        return $values;
    }
}
