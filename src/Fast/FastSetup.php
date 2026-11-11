<?php

namespace Woda\Worktrees\Fast;

/**
 * The speed settings of the container strategies (`bootstrap.fast`).
 */
final readonly class FastSetup
{
    public function __construct(
        public AppImage $image = new AppImage(null),
        // Copy `vendor` from the main checkout with copy-on-write when composer.lock is equal.
        // Off by default: an offline `composer install` from a mounted cache was faster
        // than the copy plus the install (6 s against 9.5 to 16.9 s on APFS).
        public bool $copyVendor = false,
        // Try `composer install` without network first: a warm cache needs none,
        // and a container without internet would wait for time-outs.
        public bool $composerOffline = true,
        // COMPOSER_CACHE_DIR in the app container: the folder where the project mounts the host's cache.
        public ?string $composerCacheDir = null,
        // 'container' (the app service) or 'host'.
        public string $composerRunner = 'container',
        public string $composerHostCommand = 'composer install --no-interaction --prefer-dist --ignore-platform-reqs',
        // Start the node install on the host while the containers start, when no
        // container mounts a volume at node_modules.
        public bool $parallelNode = true,
        public DatabaseDump $dump = new DatabaseDump(false),
    ) {}
}
