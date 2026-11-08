<?php

namespace Woda\Worktrees\Shared;

use Illuminate\Support\Facades\File;

/**
 * The small state file of a worktree with shared services:
 * `storage/app/worktree/state.json`. `storage/app` is ignored by git in
 * Laravel projects, so the file never shows as a change.
 *
 * A missing or broken file is not fatal: callers rebuild it from the folder,
 * git and the derived names.
 */
final class WorktreeState
{
    public const SCHEMA = 1;

    public const RELATIVE_PATH = 'storage/app/worktree/state.json';

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(public array $data) {}

    public static function path(string $worktreePath): string
    {
        return rtrim($worktreePath, '/').'/'.self::RELATIVE_PATH;
    }

    public static function load(string $worktreePath): ?self
    {
        $path = self::path($worktreePath);
        if (! File::exists($path)) {
            return null;
        }
        $data = json_decode(File::get($path), true);
        if (! is_array($data) || ($data['schema'] ?? null) !== self::SCHEMA) {
            return null;
        }

        /** @var array<string, mixed> $data */
        return new self($data);
    }

    public function save(string $worktreePath): void
    {
        $path = self::path($worktreePath);
        File::ensureDirectoryExists(dirname($path));
        File::replace($path, json_encode(['schema' => self::SCHEMA, ...$this->data], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
        @chmod($path, 0600);
    }

    public function string(string $key): ?string
    {
        $value = $this->data[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * @return list<int>
     */
    public function redis(): array
    {
        $redis = $this->data['redis'] ?? null;
        if (! is_array($redis)) {
            return [];
        }

        return array_values(array_filter($redis, is_int(...)));
    }

    /**
     * @return list<string>
     */
    public function databases(): array
    {
        $databases = $this->data['databases'] ?? null;
        if (! is_array($databases)) {
            return [];
        }
        $names = [];
        array_walk_recursive($databases, function (mixed $value) use (&$names): void {
            if (is_string($value)) {
                $names[] = $value;
            }
        });

        return $names;
    }
}
