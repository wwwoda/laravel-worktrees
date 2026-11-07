<?php

namespace Woda\Worktrees;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Woda\Worktrees\Commands\WorktreeBootstrapCommand;
use Woda\Worktrees\Commands\WorktreeCleanupCommand;
use Woda\Worktrees\Commands\WorktreeCreateCommand;
use Woda\Worktrees\Commands\WorktreeDeleteCommand;
use Woda\Worktrees\Commands\WorktreeListCommand;
use Woda\Worktrees\Commands\WorktreeOpenCommand;
use Woda\Worktrees\Commands\WorktreeSetupCommand;
use Woda\Worktrees\Commands\WorktreeTemplateCommand;
use Woda\Worktrees\Commands\WorktreeWorkerCommand;
use Woda\Worktrees\Contracts\BootstrapStrategy;
use Woda\Worktrees\Contracts\ProcessManager;
use Woda\Worktrees\Contracts\TemplateRecipe;
use Woda\Worktrees\Shared\ArtisanTemplateRecipe;
use Woda\Worktrees\Shared\DatabaseProvisioner;
use Woda\Worktrees\Shared\DatabaseServer;
use Woda\Worktrees\Shared\MariaDbServer;
use Woda\Worktrees\Shared\RedisAllocator;
use Woda\Worktrees\Shared\SharedConfig;
use Woda\Worktrees\Shared\SharedStack;
use Woda\Worktrees\Shared\TemplateManager;
use Woda\Worktrees\Shared\WorktreeRegistry;

class WorktreesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/worktrees.php', 'worktrees');

        $this->app->bindIf(ProcessManager::class, NullProcessManager::class);

        $this->app->singleton(BootstrapStrategy::class, function (): BootstrapStrategy {
            /** @var string $strategy */
            $strategy = config('worktrees.bootstrap.strategy', 'native');
            /** @var string $nodePackageManager */
            $nodePackageManager = config('worktrees.bootstrap.node_package_manager');
            /** @var bool $buildFrontend */
            $buildFrontend = config('worktrees.bootstrap.build_frontend');

            /** @var string $appService */
            $appService = config('worktrees.bootstrap.sail.app_service', 'laravel.test');

            return match ($strategy) {
                'native' => new NativeBootstrapStrategy(nodePackageManager: $nodePackageManager),
                'shared' => new SharedServicesBootstrapStrategy(
                    provisioner: $this->app->make(DatabaseProvisioner::class),
                    appService: $appService,
                    nodePackageManager: $nodePackageManager,
                    buildFrontend: $buildFrontend,
                    composeFile: (new SharedConfig)->nullableString('app.compose_file'),
                    queueMode: (new SharedConfig)->string('queue.mode', 'listen'),
                    queueEnvKeys: [
                        'mode' => (new SharedConfig)->string('queue.env.mode', 'WORKTREE_QUEUE_WORKER'),
                        'queues' => (new SharedConfig)->string('queue.env.queues', 'WORKTREE_QUEUES'),
                    ],
                    copyOnWrite: (bool) config('worktrees.shared.dependencies.copy_on_write', true),
                ),
                'sail' => new SailBootstrapStrategy(
                    appService: $appService,
                    nodePackageManager: $nodePackageManager,
                    buildFrontend: $buildFrontend,
                ),
                default => throw new InvalidArgumentException(
                    "Unknown worktrees bootstrap strategy '{$strategy}'. Expected 'native', 'sail' or 'shared'."
                ),
            };
        });

        $this->registerSharedServices();

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
            /** @var bool $buildFrontend */
            $buildFrontend = config('worktrees.bootstrap.build_frontend');
            /** @var bool $runMigrations */
            $runMigrations = config('worktrees.bootstrap.run_migrations');
            /** @var array<string, string|\Closure(string $name, string $worktreePath): string> $envOverrides */
            $envOverrides = config('worktrees.env_overrides', []);

            return new WorktreeManager(
                basePath: $basePath ?? dirname(base_path()),
                branchPrefix: $branchPrefix,
                baseBranch: $baseBranch,
                copyFiles: $copyFiles,
                databaseCloner: $app->make(DatabaseCloner::class),
                bootstrapStrategy: $app->make(BootstrapStrategy::class),
                buildFrontend: $buildFrontend,
                runMigrations: $runMigrations,
                envOverrides: $envOverrides,
            );
        });
    }

    private function registerSharedServices(): void
    {
        $this->app->bindIf(DatabaseServer::class, function (): DatabaseServer {
            $config = new SharedConfig;
            $driver = $config->string('database.driver', 'mariadb');

            return match ($driver) {
                'mariadb', 'mysql' => new MariaDbServer(
                    driver: $driver,
                    container: $config->nullableString('database.container'),
                    host: $config->string('database.host', '127.0.0.1'),
                    port: $config->int('database.port', 3306),
                    adminUser: $config->string('database.admin_username', 'root'),
                    adminPassword: $config->string('database.admin_password', ''),
                    client: $config->string('database.client', $driver === 'mysql' ? 'mysql' : 'mariadb'),
                    dumpClient: $config->string('database.dump_client', $driver === 'mysql' ? 'mysqldump' : 'mariadb-dump'),
                ),
                default => throw new InvalidArgumentException("Unknown shared database driver '{$driver}'."),
            };
        });

        $this->app->bindIf(TemplateRecipe::class, function (): TemplateRecipe {
            $config = new SharedConfig;

            return new ArtisanTemplateRecipe(
                mode: $config->string('database.template.runner', 'container'),
                appService: is_string($service = config('worktrees.bootstrap.sail.app_service')) ? $service : 'laravel.test',
                databaseHost: $config->string('database.app_host', 'mariadb'),
                databasePort: $config->int('database.app_port', 3306),
                extraEnv: $config->stringMap('database.template.env'),
            );
        });

        $this->app->singleton(WorktreeRegistry::class);

        $this->app->bindIf(SharedStack::class, function (Application $app): SharedStack {
            $config = new SharedConfig;

            return new SharedStack(
                server: $app->make(DatabaseServer::class),
                startCommand: $config->nullableString('stack.start_command'),
                waitSeconds: $config->int('stack.wait_seconds', 120),
            );
        });

        $this->app->bindIf(TemplateManager::class, function (Application $app): TemplateManager {
            $config = new SharedConfig;

            return new TemplateManager(
                server: $app->make(DatabaseServer::class),
                recipe: $app->make(TemplateRecipe::class),
                prefix: $config->string('database.prefix', 'wt'),
                hashPaths: $config->stringList('database.template.hash_paths'),
                seedCommand: $config->string('database.template.seed_command', ''),
            );
        });

        $this->app->bindIf(DatabaseProvisioner::class, function (Application $app): DatabaseProvisioner {
            $config = new SharedConfig;

            return new DatabaseProvisioner(
                server: $app->make(DatabaseServer::class),
                stack: $app->make(SharedStack::class),
                templates: $app->make(TemplateManager::class),
                redis: new RedisAllocator(
                    registry: $app->make(WorktreeRegistry::class),
                    databases: $config->int('redis.databases', 256),
                    reserved: $config->intList('redis.reserved'),
                ),
                registry: $app->make(WorktreeRegistry::class),
                prefix: $config->string('database.prefix', 'wt'),
                mainDatabase: $config->string('database.main', 'laravel'),
                parallelWorkers: $config->int('database.parallel_workers', 8),
                allowFromMain: (bool) config('worktrees.shared.database.allow_from_main', false),
                copyFromTemplate: $config->stringList('database.copy_from_template'),
                appHost: $config->string('database.app_host', 'mariadb'),
                appPort: $config->int('database.app_port', 3306),
                envMap: $config->stringMap('env'),
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
                WorktreeBootstrapCommand::class,
                WorktreeListCommand::class,
                WorktreeDeleteCommand::class,
                WorktreeCleanupCommand::class,
                WorktreeOpenCommand::class,
                WorktreeTemplateCommand::class,
                WorktreeSetupCommand::class,
                WorktreeWorkerCommand::class,
            ]);
        }
    }
}
