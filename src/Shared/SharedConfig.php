<?php

namespace Woda\Worktrees\Shared;

/**
 * Typed reads of `config('worktrees.shared.*')`.
 */
final class SharedConfig
{
    public function string(string $key, string $default): string
    {
        $value = config('worktrees.shared.'.$key);

        return is_string($value) || is_int($value) ? (string) $value : $default;
    }

    public function nullableString(string $key): ?string
    {
        $value = config('worktrees.shared.'.$key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function int(string $key, int $default): int
    {
        $value = config('worktrees.shared.'.$key);

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * @return list<mixed>
     */
    public function list(string $key): array
    {
        $value = config('worktrees.shared.'.$key);

        return is_array($value) ? array_values($value) : [];
    }

    /**
     * @return list<string>
     */
    public function stringList(string $key): array
    {
        return array_values(array_filter($this->list($key), is_string(...)));
    }

    /**
     * @return list<int>
     */
    public function intList(string $key): array
    {
        return array_values(array_map(intval(...), array_filter($this->list($key), is_numeric(...))));
    }

    /**
     * @return array<string, string>
     */
    public function stringMap(string $key): array
    {
        $value = config('worktrees.shared.'.$key);
        $map = [];
        foreach (is_array($value) ? $value : [] as $k => $v) {
            if (is_string($k) && (is_string($v) || is_int($v))) {
                $map[$k] = (string) $v;
            }
        }

        return $map;
    }
}
