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
 * The setup writes the tag `{name}:{key}` into the worktree's `.env` (key
 * `env`, default WORKTREE_APP_IMAGE); the project's compose file names it:
 * `image: ${WORKTREE_APP_IMAGE:-myapp/app:latest}`. Before the containers
 * start, a missing tag is built once with `docker compose build`, under a
 * lock, so worktrees made at the same time do not build it twice.
 */
final readonly class AppImage
{
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

    public function tag(string $worktreePath): string
    {
        return $this->name.':'.ContentKey::of($worktreePath, $this->hashPaths);
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
            $result = $compose(1800)->run('docker compose build '.escapeshellarg($this->service ?? $appService), $output);
            if (! $result->successful()) {
                throw new RuntimeException("Building the app image {$tag} failed: ".trim($result->errorOutput()));
            }
            if (! $this->exists($tag)) {
                throw new RuntimeException("docker compose build did not make {$tag}: the compose file must name the image as \${{$this->envKey}} for the service ".($this->service ?? $appService).'.');
            }

            return true;
        }, onWait: $onStep);
    }
}
