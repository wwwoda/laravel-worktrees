<?php

namespace Woda\Worktrees;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Woda\Worktrees\Commands\WorktreeCleanupCommand;
use Woda\Worktrees\Commands\WorktreeCreateCommand;
use Woda\Worktrees\Commands\WorktreeDeleteCommand;
use Woda\Worktrees\Commands\WorktreeListCommand;
use Woda\Worktrees\Commands\WorktreeOpenCommand;
use Woda\Worktrees\Contracts\ProcessManager;

class WorktreesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/worktrees.php', 'worktrees');

        $this->app->bindIf(ProcessManager::class, NullProcessManager::class);

        $this->app->singleton(DatabaseCloner::class, function (): DatabaseCloner {
            /** @var string $strategy */
            $strategy = config('worktrees.database.strategy');
            /** @var bool $sqliteCopy */
            $sqliteCopy = config('worktrees.database.sqlite_copy');
            /** @var string|null $mysqlDockerContainer */
            $mysqlDockerContainer = config('worktrees.database.mysql_docker_container');
            /** @var string|null $pgsqlDockerContainer */
            $pgsqlDockerContainer = config('worktrees.database.pgsql_docker_container');
            /** @var string $dockerHost */
            $dockerHost = config('worktrees.database.docker_host');

            return new DatabaseCloner(
                strategy: $strategy,
                sqliteCopy: $sqliteCopy,
                mysqlDockerContainer: $mysqlDockerContainer,
                pgsqlDockerContainer: $pgsqlDockerContainer,
                dockerHost: $dockerHost,
            );
        });

        $this->app->singleton(WorktreeManager::class, function (Application $app): WorktreeManager {
            /** @var string|null $basePath */
            $basePath = config('worktrees.base_path');
            /** @var string $branchPrefix */
            $branchPrefix = config('worktrees.branch_prefix');
            /** @var string $baseBranch */
            $baseBranch = config('worktrees.base_branch');
            /** @var list<string> $copyFiles */
            $copyFiles = config('worktrees.copy_files');
            /** @var string $nodePackageManager */
            $nodePackageManager = config('worktrees.bootstrap.node_package_manager');
            /** @var bool $buildFrontend */
            $buildFrontend = config('worktrees.bootstrap.build_frontend');
            /** @var bool $runMigrations */
            $runMigrations = config('worktrees.bootstrap.run_migrations');

            return new WorktreeManager(
                basePath: $basePath ?? dirname(base_path()),
                branchPrefix: $branchPrefix,
                baseBranch: $baseBranch,
                copyFiles: $copyFiles,
                databaseCloner: $app->make(DatabaseCloner::class),
                nodePackageManager: $nodePackageManager,
                buildFrontend: $buildFrontend,
                runMigrations: $runMigrations,
            );
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/worktrees.php' => config_path('worktrees.php'),
        ], 'worktrees-config');

        if ($this->app->runningInConsole()) {
            $this->commands([
                WorktreeCreateCommand::class,
                WorktreeListCommand::class,
                WorktreeDeleteCommand::class,
                WorktreeCleanupCommand::class,
                WorktreeOpenCommand::class,
            ]);
        }
    }
}
