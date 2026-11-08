<?php

namespace Woda\Worktrees;

use Closure;
use RuntimeException;
use Woda\Worktrees\Contracts\ProvisionsDatabases;
use Woda\Worktrees\Shared\DatabaseProvisioner;
use Woda\Worktrees\Shared\MainCheckout;
use Woda\Worktrees\Shared\WorktreeState;

/**
 * Shared services: one database server and one Redis per machine, used by
 * all worktrees, and one app container per worktree.
 *
 * Each worktree gets its own databases (copied from a template), its own
 * database user with rights on its exact database names only, and its own
 * Redis database numbers. Containers are handled like the Sail strategy:
 * `docker compose up -d` in the worktree, PHP commands via `compose exec`.
 */
class SharedServicesBootstrapStrategy extends SailBootstrapStrategy implements ProvisionsDatabases
{
    public function __construct(
        private readonly DatabaseProvisioner $provisioner,
        string $appService,
        string $nodePackageManager,
        bool $buildFrontend,
        int $bringUpTimeout = 600,
    ) {
        parent::__construct($appService, $nodePackageManager, $buildFrontend, $bringUpTimeout);
    }

    /**
     * The shared stack runs in the main checkout's compose project: a worktree
     * must not share it, also when artisan runs in a linked worktree.
     */
    protected function protectedCheckouts(): array
    {
        return [...parent::protectedCheckouts(), MainCheckout::path()];
    }

    public function provisionDatabases(string $name, string $worktreePath, array $options, ?Closure $output = null, ?Closure $onStep = null): void
    {
        $this->provisioner->provision(
            $name,
            $worktreePath,
            ['branch' => $options['branch'] ?? null, 'base' => $options['base'] ?? null],
            (bool) ($options['from_main'] ?? false),
            $output,
            $onStep,
        );
    }

    /**
     * Migrations newer than the template run in each database that started
     * as a copy: the app, test and e2e databases.
     */
    public function runMigrations(string $worktreePath, ?Closure $output = null): void
    {
        $state = WorktreeState::load($worktreePath);
        $databases = $state?->data['databases'] ?? null;
        $targets = is_array($databases)
            ? array_values(array_filter([$databases['app'] ?? null, $databases['test'] ?? null, $databases['e2e'] ?? null], is_string(...)))
            : [];
        if ($targets === []) {
            parent::runMigrations($worktreePath, $output);

            return;
        }
        foreach ($targets as $database) {
            $result = $this->compose($worktreePath, 600)->run(
                // A config cache would ignore DB_DATABASE: read the config fresh.
                sprintf('docker compose exec -T -e %s -e %s %s sh -c %s',
                    escapeshellarg('DB_DATABASE='.$database),
                    escapeshellarg('APP_CONFIG_CACHE=/tmp/worktree-migrate-'.bin2hex(random_bytes(6)).'-config.php'),
                    escapeshellarg($this->appService()),
                    escapeshellarg('php artisan migrate --force'),
                ),
                $output,
            );
            if (! $result->successful()) {
                throw new RuntimeException("Migration of {$database} failed: {$result->errorOutput()}");
            }
        }
    }
}
