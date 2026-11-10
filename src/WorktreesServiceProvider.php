<?php

namespace Woda\Worktrees;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Woda\Worktrees\Agent\AppRunner;
use Woda\Worktrees\Agent\Doctor;
use Woda\Worktrees\Agent\WorktreeInspector;
use Woda\Worktrees\Commands\WorktreeBootstrapCommand;
use Woda\Worktrees\Commands\WorktreeCleanupCommand;
use Woda\Worktrees\Commands\WorktreeContextCommand;
use Woda\Worktrees\Commands\WorktreeConvertCommand;
use Woda\Worktrees\Commands\WorktreeCreateCommand;
use Woda\Worktrees\Commands\WorktreeDeleteCommand;
use Woda\Worktrees\Commands\WorktreeDoctorCommand;
use Woda\Worktrees\Commands\WorktreeExecCommand;
use Woda\Worktrees\Commands\WorktreeListCommand;
use Woda\Worktrees\Commands\WorktreeOpenCommand;
use Woda\Worktrees\Commands\WorktreeServicesCommand;
use Woda\Worktrees\Commands\WorktreeSetupCommand;
use Woda\Worktrees\Commands\WorktreeStatusCommand;
use Woda\Worktrees\Commands\WorktreeTemplateCommand;
use Woda\Worktrees\Commands\WorktreeVolumesCommand;
use Woda\Worktrees\Commands\WorktreeWorkerCommand;
use Woda\Worktrees\Contracts\BootstrapStrategy;
use Woda\Worktrees\Contracts\ProcessManager;
use Woda\Worktrees\Contracts\TemplateRecipe;
use Woda\Worktrees\Shared\ArtisanTemplateRecipe;
use Woda\Worktrees\Shared\DatabaseProvisioner;
use Woda\Worktrees\Shared\DatabaseServer;
use Woda\Worktrees\Shared\EnvFile;
use Woda\Worktrees\Shared\MainCheckout;
use Woda\Worktrees\Shared\MariaDbServer;
use Woda\Worktrees\Shared\PostgresServer;
use Woda\Worktrees\Shared\RecreateDatabasesGuard;
use Woda\Worktrees\Shared\RedisAllocator;
use Woda\Worktrees\Shared\RedisServer;
use Woda\Worktrees\Shared\SailConverter;
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
        $this->registerConverter();
        $this->registerAgentServices();

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
                    port: $config->int('database.port', self::defaultPort()),
                    adminUser: $config->nullableString('database.admin_username') ?? 'root',
                    adminPassword: $config->string('database.admin_password', ''),
                    client: $config->nullableString('database.client') ?? ($driver === 'mysql' ? 'mysql' : 'mariadb'),
                    dumpClient: $config->nullableString('database.dump_client') ?? ($driver === 'mysql' ? 'mysqldump' : 'mariadb-dump'),
                ),
                'pgsql' => new PostgresServer(
                    container: $config->nullableString('database.container'),
                    host: $config->string('database.host', '127.0.0.1'),
                    port: $config->int('database.port', self::defaultPort()),
                    adminUser: $config->nullableString('database.admin_username') ?? 'postgres',
                    adminPassword: $config->string('database.admin_password', ''),
                    client: $config->nullableString('database.client') ?? 'psql',
                    dumpClient: $config->nullableString('database.dump_client') ?? 'pg_dump',
                ),
                default => throw new InvalidArgumentException("Unknown shared database driver '{$driver}'."),
            };
        });

        $this->app->bindIf(TemplateRecipe::class, function (): TemplateRecipe {
            $config = new SharedConfig;

            $mode = $config->string('database.template.runner', 'container');
            // On the host, artisan reaches the server like the package's admin connection;
            // in the app container, like the app (the compose service name).
            $onHost = $mode === 'host';

            return new ArtisanTemplateRecipe(
                mode: $mode,
                appService: is_string($service = config('worktrees.bootstrap.sail.app_service')) ? $service : 'laravel.test',
                databaseHost: $onHost ? $config->string('database.host', '127.0.0.1') : ($config->nullableString('database.app_host') ?? self::defaultAppHost()),
                databasePort: $onHost ? $config->int('database.port', self::defaultPort()) : $config->int('database.app_port', self::defaultPort()),
                extraEnv: $config->stringMap('database.template.env'),
            );
        });

        $this->app->singleton(WorktreeRegistry::class);

        $this->app->bindIf(RedisServer::class, function (): RedisServer {
            $config = new SharedConfig;

            return new RedisServer(
                container: $config->nullableString('redis.container'),
                host: $config->string('redis.host', '127.0.0.1'),
                port: $config->int('redis.port', 6379),
                password: $config->nullableString('redis.password'),
            );
        });

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
                    envMap: array_filter($config->stringMap('env'), static fn (string $value): bool => preg_match('/\{redis_(default|cache)\}/', $value) === 1),
                ),
                registry: $app->make(WorktreeRegistry::class),
                prefix: $config->string('database.prefix', 'wt'),
                mainDatabase: $config->nullableString('database.main') ?? self::mainDatabaseFromMainCheckout(),
                parallelWorkers: $config->int('database.parallel_workers', 8),
                allowFromMain: (bool) config('worktrees.shared.database.allow_from_main', false),
                copyFromTemplate: $config->stringList('database.copy_from_template'),
                appHost: $config->nullableString('database.app_host') ?? self::defaultAppHost(),
                appPort: $config->int('database.app_port', self::defaultPort()),
                envMap: $config->stringMap('env'),
                redisServer: $app->make(RedisServer::class),
                clearEnv: self::clearEnv(),
                worktreeEnvKeys: self::envOverrideKeys(),
            );
        });
    }

    /**
     * `php artisan test --parallel --recreate-databases` passes the option to
     * its workers in the environment; the app boots in each worker before
     * Laravel's database setup runs.
     */
    public static function guardRecreateDatabases(): void
    {
        $key = 'LARAVEL_PARALLEL_TESTING_RECREATE_DATABASES';
        $value = $_SERVER[$key] ?? $_ENV[$key] ?? getenv($key);
        if (! filter_var($value, FILTER_VALIDATE_BOOLEAN)) {
            return;
        }
        $connection = config('database.default');
        $driver = config('database.connections.'.(is_string($connection) ? $connection : '').'.driver');
        RecreateDatabasesGuard::check(true, is_string($driver) ? $driver : '', base_path());
    }

    private static function sharedDriver(): string
    {
        return (new SharedConfig)->string('database.driver', 'mariadb');
    }

    private static function defaultPort(): int
    {
        return self::sharedDriver() === 'pgsql' ? 5432 : 3306;
    }

    /**
     * The connection URL keys to empty in a `.env`. A config published before the
     * key existed has no `clear_env`: then the defaults apply.
     *
     * @return list<string>
     */
    private static function clearEnv(): array
    {
        return config('worktrees.shared.clear_env') === null
            ? DatabaseProvisioner::CLEAR_ENV
            : (new SharedConfig)->stringList('clear_env');
    }

    /**
     * @return list<string>
     */
    private static function envOverrideKeys(): array
    {
        $overrides = config('worktrees.env_overrides');

        return is_array($overrides) ? array_values(array_filter(array_keys($overrides), is_string(...))) : [];
    }

    private static function defaultAppHost(): string
    {
        return match (self::sharedDriver()) {
            'pgsql' => 'pgsql',
            'mysql' => 'mysql',
            default => 'mariadb',
        };
    }

    private function registerAgentServices(): void
    {
        $this->app->bind(AppRunner::class, fn (Application $app): AppRunner => new AppRunner(
            strategy: $app->make(BootstrapStrategy::class),
            appService: is_string($service = config('worktrees.bootstrap.sail.app_service')) ? $service : 'laravel.test',
        ));
        $this->app->bind(WorktreeInspector::class, fn (Application $app): WorktreeInspector => new WorktreeInspector(
            manager: $app->make(WorktreeManager::class),
            runner: $app->make(AppRunner::class),
        ));
        $this->app->bindIf(Doctor::class, fn (Application $app): Doctor => new Doctor(
            inspector: $app->make(WorktreeInspector::class),
            strategy: $app->make(BootstrapStrategy::class),
            buildFrontend: (bool) config('worktrees.bootstrap.build_frontend', true),
        ));
    }

    private function registerConverter(): void
    {
        $this->app->bindIf(SailConverter::class, function (Application $app): SailConverter {
            $config = new SharedConfig;

            return new SailConverter(
                manager: $app->make(WorktreeManager::class),
                provisioner: $app->make(DatabaseProvisioner::class),
                strategy: $app->make(BootstrapStrategy::class),
                image: $config->string('convert.image', 'mariadb:10.11'),
                volumePattern: $config->string('convert.volume', '{compose_project}_sail-mariadb'),
                baseBranch: is_string($base = config('worktrees.base_branch')) ? $base : 'master',
                keepContainers: array_filter([$config->nullableString('database.container')]),
            );
        });
    }

    private static function mainDatabaseFromMainCheckout(): string
    {
        $database = EnvFile::get(MainCheckout::file('.env'), 'DB_DATABASE');
        if ($database === null || $database === '') {
            throw new InvalidArgumentException('Cannot tell the main database: set WORKTREE_SHARED_DB_MAIN (worktrees.shared.database.main), or DB_DATABASE in the main checkout\'s .env.');
        }

        return $database;
    }

    public function boot(): void
    {
        // Before any test runs: Laravel's own setup callback would drop the first
        // parallel database before a later callback could object.
        self::guardRecreateDatabases();

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
                WorktreeConvertCommand::class,
                WorktreeVolumesCommand::class,
                WorktreeStatusCommand::class,
                WorktreeServicesCommand::class,
                WorktreeExecCommand::class,
                WorktreeDoctorCommand::class,
                WorktreeContextCommand::class,
            ]);
        }
    }
}
