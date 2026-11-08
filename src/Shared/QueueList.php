<?php

namespace Woda\Worktrees\Shared;

/**
 * The queues a worktree's worker listens to: every queue that the Horizon
 * config names (its defaults and all its environments), or `default` when
 * the project has no Horizon.
 */
final class QueueList
{
    /**
     * @return list<string>
     */
    public static function for(string $worktreePath): array
    {
        $configPath = $worktreePath.'/config/horizon.php';
        if (! is_file($configPath) || ! self::requiresHorizon($worktreePath)) {
            return ['default'];
        }
        $config = (static fn (string $path): mixed => require $path)($configPath);
        if (! is_array($config)) {
            return ['default'];
        }

        $supervisors = [];
        foreach (is_array($config['defaults'] ?? null) ? $config['defaults'] : [] as $supervisor) {
            $supervisors[] = $supervisor;
        }
        foreach (is_array($config['environments'] ?? null) ? $config['environments'] : [] as $environment) {
            foreach (is_array($environment) ? $environment : [] as $supervisor) {
                $supervisors[] = $supervisor;
            }
        }

        $queues = [];
        foreach ($supervisors as $supervisor) {
            $queue = is_array($supervisor) ? ($supervisor['queue'] ?? null) : null;
            foreach (is_array($queue) ? $queue : [$queue] as $name) {
                if (is_string($name) && $name !== '') {
                    foreach (explode(',', $name) as $part) {
                        $queues[] = trim($part);
                    }
                }
            }
        }
        $queues = array_values(array_unique(array_filter($queues)));

        return $queues === [] ? ['default'] : $queues;
    }

    /**
     * Read from composer.json: the worktree's vendor may not exist yet.
     */
    private static function requiresHorizon(string $worktreePath): bool
    {
        $composer = json_decode((string) @file_get_contents($worktreePath.'/composer.json'), true);

        if (! is_array($composer)) {
            return false;
        }
        // Horizon is often a dev dependency, which the development install also installs.
        foreach (['require', 'require-dev'] as $section) {
            if (is_array($composer[$section] ?? null) && array_key_exists('laravel/horizon', $composer[$section])) {
                return true;
            }
        }

        return false;
    }
}
