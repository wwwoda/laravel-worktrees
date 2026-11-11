<?php

namespace Woda\Worktrees\Fast;

use Closure;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * One app image per version of its build inputs (the Dockerfile and what it
 * copies), shared by every worktree of the project, instead of one build per
 * worktree.
 *
 * The key covers the hash paths, the build section of the building service in
 * the resolved compose file (context, dockerfile, args, target) and the files
 * of its build context. So Sail's runtimes under `vendor` count too: another
 * runtime folder or a Sail update that changes it gives a new tag.
 *
 * The setup writes the tag `{name}:{key}` into the worktree's `.env` (key
 * `env`, default WORKTREE_APP_IMAGE); the project's compose file names it:
 * `image: ${WORKTREE_APP_IMAGE:-myapp/app:latest}`. Before the containers
 * start, a missing tag is built once with `docker compose build`, under a
 * lock, so worktrees made at the same time do not build it twice.
 */
final readonly class AppImage
{
    // A waiting setup waits longer than the build may take.
    private const BUILD_TIMEOUT = 1800;

    /**
     * @param  list<string>  $hashPaths
     */
    public function __construct(
        private ?string $name,
        private string $envKey = 'WORKTREE_APP_IMAGE',
        private array $hashPaths = ['Dockerfile', 'docker'],
        private ?string $service = null,
    ) {}

    public function enabled(): bool
    {
        return $this->name !== null && $this->name !== '';
    }

    public function envKey(): string
    {
        return $this->envKey;
    }

    /**
     * The compose service that builds the image.
     */
    public function service(string $appService): string
    {
        return $this->service ?? $appService;
    }

    /**
     * @param  array<mixed>|null  $build  the service's `build` section of `docker compose config --format json`
     */
    public function tag(string $worktreePath, ?array $build = null): string
    {
        $paths = $this->hashPaths;
        $prefix = '';
        if ($build !== null) {
            $context = is_string($build['context'] ?? null) ? $build['context'] : null;
            $relative = $context === null ? null : self::relativeTo($worktreePath, $context);
            if ($relative !== null) {
                // The same context in every worktree: name it by its path in the worktree.
                $build['context'] = $relative;
                // A context of `.` is the whole worktree: its code would give a new image
                // for each change. Then the hash paths name what the Dockerfile copies.
                if ($relative !== '.') {
                    $paths[] = $relative;
                }
            } elseif ($context !== null && is_dir($context)) {
                $build['context_content'] = ContentKey::of($context, ['.']);
            }
            $prefix = 'build:'.json_encode(self::sorted($build), JSON_UNESCAPED_SLASHES)."\n";
        }

        return $this->name.':'.ContentKey::of($worktreePath, array_values(array_unique($paths)), $prefix);
    }

    /**
     * `$path` relative to the worktree (either spelling of it), or null when it is outside.
     */
    private static function relativeTo(string $worktreePath, string $path): ?string
    {
        foreach (array_unique([rtrim($worktreePath, '/'), realpath($worktreePath) ?: $worktreePath]) as $root) {
            if ($path === $root) {
                return '.';
            }
            if (str_starts_with($path, $root.'/')) {
                return substr($path, strlen($root) + 1);
            }
        }

        return null;
    }

    /**
     * @param  array<mixed>  $value
     * @return array<mixed>
     */
    private static function sorted(array $value): array
    {
        ksort($value);
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::sorted($item);
            }
        }

        return $value;
    }

    public function exists(string $tag): bool
    {
        return Process::timeout(30)->run('docker image inspect --format '.escapeshellarg('{{.Id}}').' '.escapeshellarg($tag))->successful();
    }

    /**
     * Build the tag when it is missing.
     *
     * @param  Closure(int): PendingProcess  $compose  a compose process in the worktree, by timeout
     * @param  (Closure(string, string): void)|null  $output
     * @param  (Closure(string): void)|null  $onStep
     * @return bool true when this call built it
     */
    public function ensure(string $tag, string $appService, Closure $compose, ?Closure $output = null, ?Closure $onStep = null): bool
    {
        if ($this->exists($tag)) {
            return false;
        }

        return MainLock::run('image-'.preg_replace('/[^A-Za-z0-9_.-]/', '_', $tag), function () use ($tag, $appService, $compose, $output, $onStep): bool {
            // Another setup may have built it while this one waited.
            if ($this->exists($tag)) {
                return false;
            }
            $onStep && $onStep("Building the app image {$tag} (once for all worktrees with this Dockerfile)...");
            $result = $compose(self::BUILD_TIMEOUT)->run('docker compose build '.escapeshellarg($this->service($appService)), $output);
            if (! $result->successful()) {
                throw new RuntimeException("Building the app image {$tag} failed: ".trim($result->errorOutput()));
            }
            if (! $this->exists($tag)) {
                throw new RuntimeException("docker compose build did not make {$tag}: the compose file must name the image as \${{$this->envKey}} for the service ".$this->service($appService).'.');
            }

            return true;
        }, timeout: self::BUILD_TIMEOUT + 300, onWait: $onStep);
    }
}
