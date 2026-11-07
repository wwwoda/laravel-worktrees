<?php

namespace Woda\Worktrees\Shared;

use Closure;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Woda\Worktrees\Contracts\TemplateRecipe;

/**
 * Template databases: one per hash of their inputs (by default the main
 * checkout's migrations and seeders, plus the seed command), built once under
 * a lock and kept until a prune.
 *
 * A template counts as ready when its database exists and its marker file
 * `storage/app/worktrees/templates/<name>.ready` in the main checkout exists
 * (the main checkout also when artisan runs in a linked worktree).
 * A database without the marker is a build that stopped half way; it is
 * dropped and built again.
 */
class TemplateManager
{
    /**
     * @param  list<string>  $hashPaths
     */
    public function __construct(
        private readonly DatabaseServer $server,
        private readonly TemplateRecipe $recipe,
        private readonly string $prefix,
        private readonly array $hashPaths,
        private readonly string $seedCommand,
        private readonly int $lockTimeout = 1800,
    ) {}

    public function prefix(): string
    {
        return $this->prefix.'_tpl_';
    }

    public function hash(): string
    {
        $context = hash_init('sha256');
        hash_update($context, "seed:{$this->seedCommand}\n");
        $files = [];
        foreach ($this->hashPaths as $relative) {
            $path = MainCheckout::file($relative);
            if (is_file($path)) {
                $files[$relative] = $path;
            } elseif (is_dir($path)) {
                foreach (File::allFiles($path) as $file) {
                    $files[$relative.'/'.$file->getRelativePathname()] = $file->getPathname();
                }
            }
        }
        ksort($files);
        foreach ($files as $relative => $path) {
            hash_update($context, $relative."\0".hash_file('sha256', $path)."\n");
        }

        return substr(hash_final($context), 0, 12);
    }

    public function name(): string
    {
        return $this->prefix().$this->hash();
    }

    /**
     * Reads the server and the marker, which other processes change.
     *
     * @phpstan-impure
     */
    public function isReady(string $name): bool
    {
        return File::exists($this->markerPath($name)) && $this->server->databaseExists($name);
    }

    /**
     * Build the current template when it is not ready. A second caller waits
     * for the first one's build and then uses it.
     *
     * @param  (Closure(string, string): void)|null  $output
     * @param  (Closure(string): void)|null  $onStep
     */
    public function ensure(?Closure $output = null, ?Closure $onStep = null): string
    {
        $name = $this->name();
        if ($this->isReady($name)) {
            return $name;
        }

        $lock = $this->lock($onStep);
        try {
            if ($this->isReady($name)) {
                return $name;
            }
            $onStep && $onStep("Building template database {$name}...");
            $this->build($name, $output);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        return $name;
    }

    /**
     * Drop every template except the current one.
     *
     * @return list<string>
     */
    public function prune(): array
    {
        $current = $this->name();
        $dropped = [];
        foreach ($this->server->databasesStartingWith($this->prefix()) as $database) {
            if ($database === $current || ! preg_match('/^'.preg_quote($this->prefix(), '/').'[0-9a-f]{12}$/', $database)) {
                continue;
            }
            $this->server->dropDatabase($database);
            File::delete($this->markerPath($database));
            $dropped[] = $database;
        }

        return $dropped;
    }

    /**
     * @param  (Closure(string, string): void)|null  $output
     */
    private function build(string $name, ?Closure $output): void
    {
        // A database without a marker is a stopped build: start again.
        if ($this->server->databaseExists($name)) {
            $this->server->dropDatabase($name);
        }
        $builder = $this->prefix().'builder';
        $password = bin2hex(random_bytes(16));
        $this->server->createDatabase($name, $builder);
        $this->server->grantUser($builder, $password, [$name]);
        try {
            $this->recipe->build($name, $builder, $password, $this->seedCommand, $output);
        } catch (\Throwable $error) {
            $this->server->dropDatabase($name);

            throw $error instanceof RuntimeException ? $error : new RuntimeException($error->getMessage(), 0, $error);
        } finally {
            $this->server->dropUser($builder);
        }
        File::ensureDirectoryExists(dirname($this->markerPath($name)));
        File::put($this->markerPath($name), date(DATE_ATOM)."\n");
    }

    /**
     * @param  (Closure(string): void)|null  $onStep
     * @return resource
     */
    private function lock(?Closure $onStep)
    {
        $path = MainCheckout::file('storage/app/worktrees/template.lock');
        File::ensureDirectoryExists(dirname($path));
        $handle = fopen($path, 'c');
        if ($handle === false) {
            throw new RuntimeException("Cannot open the template lock {$path}.");
        }
        $deadline = time() + $this->lockTimeout;
        $told = false;
        while (! flock($handle, LOCK_EX | LOCK_NB)) {
            if (time() >= $deadline) {
                fclose($handle);

                throw new RuntimeException('Timed out waiting for another template build.');
            }
            if (! $told && $onStep) {
                $onStep('Another setup is building the template database; waiting...');
                $told = true;
            }
            usleep(250_000);
        }

        return $handle;
    }

    private function markerPath(string $name): string
    {
        return MainCheckout::file('storage/app/worktrees/templates/'.$name.'.ready');
    }
}
