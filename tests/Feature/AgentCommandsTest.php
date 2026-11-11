<?php

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Woda\Worktrees\Agent\ComposeStack;
use Woda\Worktrees\Agent\Doctor;
use Woda\Worktrees\Agent\WorktreeInspector;
use Woda\Worktrees\Agent\WorktreeTarget;
use Woda\Worktrees\Commands\WorktreeExecCommand;
use Woda\Worktrees\Contracts\BootstrapStrategy;
use Woda\Worktrees\SailBootstrapStrategy;
use Woda\Worktrees\SetupLock;
use Woda\Worktrees\Shared\MainCheckout;
use Woda\Worktrees\WorktreeManager;

/*
 * The agent commands: worktree:status, worktree:services, worktree:exec,
 * worktree:doctor and worktree:context. Docker and git are faked.
 */
beforeEach(function () {
    $this->base = sys_get_temp_dir().'/worktrees-agent-'.uniqid();
    File::ensureDirectoryExists($this->base);
    File::put(base_path('.env'), "APP_URL=https://app.test\nCOMPOSE_PROJECT_NAME=app\n");
    config()->set('worktrees.base_path', $this->base);
    config()->set('worktrees.bootstrap.strategy', 'sail');
    config()->set('worktrees.database.strategy', 'none');

    $this->path = $this->base.'/'.basename(base_path()).'-t9';
    File::ensureDirectoryExists($this->path.'/storage');
    File::ensureDirectoryExists($this->path.'/vendor');
    File::put($this->path.'/vendor/autoload.php', '<?php');
    File::put($this->path.'/.env', "APP_URL=https://app-t9.test\nCOMPOSE_PROJECT_NAME=app-t9\n");
    File::put($this->path.'/storage/worktree-bootstrap.json', json_encode(['completed' => ['config'], 'name' => 't9', 'status' => 'ready', 'branch' => 'feature']));

    $this->project = 'app-t9';
    $this->mainProject = 'app';
    $this->containers = [
        ['Service' => 'laravel.test', 'Name' => 'app-t9-laravel.test-1', 'State' => 'running', 'Health' => ''],
        ['Service' => 'mariadb', 'Name' => 'app-t9-mariadb-1', 'State' => 'running', 'Health' => 'healthy'],
    ];
    $this->migrateStatus = Process::result("  2014_01_01_000000_a ........ [1] Ran\n  2026_01_01_000000_b ........ Pending\n");
    $this->started = [];
    $this->imageIds = [];
    $this->owner = null;
    $this->execResult = Process::result();
    $this->fakeProcesses = function () {
        Process::fake([
            'git worktree list --porcelain' => fn () => Process::result(
                'worktree '.base_path()."\nHEAD aaa\nbranch refs/heads/master\n\n"
                ."worktree {$this->path}\nHEAD 0123456789abcdef\nbranch refs/heads/feature\n\n"
            ),
            'git status --porcelain *' => Process::result(" M app/User.php\n"),
            "docker compose --profile '*' config --format json" => fn (PendingProcess $p) => Process::result(json_encode([
                'name' => $p->path === $this->path ? $this->project : $this->mainProject,
                'services' => [
                    'laravel.test' => ['image' => 'app/app:1'],
                    'mariadb' => ['image' => 'mariadb:10.11'],
                    'horizon' => ['image' => 'app/app:1', 'profiles' => ['workers']],
                    'scheduler' => ['image' => 'app/app:1', 'profiles' => ['workers']],
                    'phpmyadmin' => ['image' => 'phpmyadmin', 'profiles' => ['tools']],
                ],
            ])),
            'docker compose ps --all --format json' => fn () => Process::result(implode("\n", array_map(json_encode(...), $this->containers))),
            'docker stats *' => Process::result("app-t9-laravel.test-1|37.5MiB / 1GiB\napp-t9-mariadb-1|36MiB / 256MiB\n"),
            'docker inspect *' => fn () => Process::result(implode("\n", array_map(
                fn (string $name, int $time) => "/{$name}|".gmdate('Y-m-d\TH:i:s.123456789\Z', $time).'|'.($this->imageIds[$name] ?? 'sha256:app'),
                array_keys($this->started), $this->started,
            ))),
            'docker ps -a --filter *' => fn () => Process::result($this->owner ?? $this->path),
            '*migrate:status*' => fn () => $this->migrateStatus,
            'docker compose exec *' => fn () => $this->execResult,
            '*' => Process::result(),
        ]);
    };
    ($this->fakeProcesses)();
    MainCheckout::forget();
});

afterEach(function () {
    File::deleteDirectory($this->base);
    File::delete(base_path('.env'));
    MainCheckout::forget();
});

it('shows services, memory, profiles, database state and git state as JSON', function () {
    $this->artisan('worktree:status', ['worktree' => 't9', '--json' => true])->assertSuccessful();

    $status = app(WorktreeInspector::class)->status(
        new WorktreeTarget($this->path, 't9', false, 'feature', '0123456789abcdef', null),
    );
    expect($status['url'])->toBe('https://app-t9.test')
        ->and($status['changes'])->toBe(1)
        ->and($status['compose_project'])->toBe('app-t9')
        ->and($status['profiles'])->toBe(['active' => [], 'available' => ['tools', 'workers']])
        ->and($status['services'][0])->toMatchArray(['service' => 'laravel.test', 'state' => 'running', 'memory' => 39321600])
        ->and($status['database'])->toMatchArray(['state' => 'pending', 'ran' => 1, 'pending' => 1]);
});

it('reports an empty database when the migrations table is missing', function () {
    $this->migrateStatus = Process::result(errorOutput: "ERROR  Migration table not found.\n", exitCode: 1);

    expect(app(WorktreeInspector::class)->migrations($this->path))->toMatchArray(['state' => 'empty']);
});

it('finds a worktree by its record name from inside another worktree', function () {
    $this->artisan('worktree:status', ['worktree' => 'nope'])->assertFailed();
    $this->artisan('worktree:status', ['worktree' => 't9', '--no-memory' => true, '--no-db' => true])
        ->expectsOutputToContain('https://app-t9.test')
        ->assertSuccessful();
});

it('turns profiles on, writes COMPOSE_PROFILES and removes the services of profiles that are off', function () {
    $this->artisan('worktree:services', ['profiles' => ['workers'], '--worktree' => 't9'])->assertSuccessful();

    expect(File::get($this->path.'/.env'))->toContain('COMPOSE_PROFILES=workers');
    Process::assertRan(fn (PendingProcess $p) => $p->command === 'docker compose up -d' && $p->path === $this->path);
    Process::assertRan(fn (PendingProcess $p) => $p->command === "docker compose --profile '*' rm --stop --force 'phpmyadmin'");

    $this->artisan('worktree:services', ['profiles' => ['phpmyadmin'], '--worktree' => 't9', '--add' => true])->assertSuccessful();
    expect(File::get($this->path.'/.env'))->toContain('COMPOSE_PROFILES=workers,tools');

    $this->artisan('worktree:services', ['profiles' => ['none'], '--worktree' => 't9'])->assertSuccessful();
    expect(File::get($this->path.'/.env'))->toMatch('/^COMPOSE_PROFILES=$/m');
    Process::assertRan(fn (PendingProcess $p) => $p->command === "docker compose --profile '*' rm --stop --force 'horizon' 'scheduler' 'phpmyadmin'");
});

it('refuses an unknown profile and a worktree with the main checkout\'s compose project', function () {
    $this->artisan('worktree:services', ['profiles' => ['nope'], '--worktree' => 't9'])
        ->expectsOutputToContain('Unknown profile nope. Available: tools, workers.')
        ->assertFailed();

    $this->project = 'app';
    $this->artisan('worktree:services', ['profiles' => ['workers'], '--worktree' => 't9'])->assertFailed();
    expect(File::get($this->path.'/.env'))->not->toContain('COMPOSE_PROFILES');
    Process::assertNotRan('docker compose up -d');
});

it('lists the profiles with their services', function () {
    $this->artisan('worktree:services', ['--worktree' => 't9'])
        ->expectsTable(['Profile', 'State', 'Services'], [
            ['tools', 'off', 'phpmyadmin'],
            ['workers', 'off', 'horizon, scheduler'],
        ])
        ->assertSuccessful();
});

it('parses the raw arguments of worktree:exec', function (array $tokens, ?string $worktree, ?string $service, array $words) {
    expect(WorktreeExecCommand::parseTokens($tokens))->toBe([$worktree, $service, $words]);
})->with([
    'options of the command stay' => [['worktree:exec', 'test', '--filter=X', '-w', 'x'], null, null, ['test', '--filter=X', '-w', 'x']],
    'own options first' => [['worktree:exec', '-w', 't9', '--service=mariadb', 'mariadb', '-e', 'select 1'], 't9', 'mariadb', ['mariadb', '-e', 'select 1']],
    'glued -w and --' => [['-v', 'worktree:exec', '-wt9', '--', '--version'], 't9', null, ['--version']],
    'long option with a space' => [['worktree:exec', '--worktree', 't9', 'artisan', 'migrate'], 't9', null, ['artisan', 'migrate']],
]);

it('runs aliases in the app container with quoted arguments and the host test processes', function () {
    config()->set('worktrees.exec.test_processes', 8);

    $this->artisan('worktree:exec', ['cmd' => ['test', '--parallel', '--filter=User Test'], '--worktree' => 't9'])->assertSuccessful();
    Process::assertRan(fn (PendingProcess $p) => $p->path === $this->path
        && $p->command === "docker compose exec -T 'laravel.test' sh -c 'php artisan test '\\''--parallel'\\'' '\\''--filter=User Test'\\'' '\\''--processes=8'\\'''");

    $this->artisan('worktree:exec', ['cmd' => ['php artisan migrate --force'], '--worktree' => 't9', '--service' => 'horizon'])->assertSuccessful();
    Process::assertRan(fn (PendingProcess $p) => $p->command === "docker compose exec -T 'horizon' sh -c 'php artisan migrate --force'");
});

it('passes the exit code of the command on', function () {
    $this->execResult = Process::result(errorOutput: 'service "laravel.test" is not running', exitCode: 3);

    $this->artisan('worktree:exec', ['cmd' => ['artisan', 'about'], '--worktree' => $this->path])->assertExitCode(3);
});

it('finds problems and fixes them in order', function () {
    $strategy = new class('laravel.test', 'pnpm', false) extends SailBootstrapStrategy
    {
        /** @var list<string> */
        public array $calls = [];

        public ?Closure $onBringUp = null;

        public function bringUp(string $worktreePath, ?Closure $output = null): void
        {
            $this->calls[] = 'bringUp';
            $this->onBringUp && ($this->onBringUp)();
        }

        public function installComposerDependencies(string $worktreePath, ?Closure $output = null): void
        {
            $this->calls[] = 'composer';
            File::put($worktreePath.'/vendor/autoload.php', '<?php');
        }

        public function runMigrations(string $worktreePath, ?Closure $output = null): void
        {
            $this->calls[] = 'migrate';
        }
    };
    app()->instance(BootstrapStrategy::class, $strategy);
    app()->forgetInstance(Doctor::class);
    app()->bind(Doctor::class, fn () => new Doctor(app(WorktreeInspector::class), $strategy, false, fn () => false));
    File::delete($this->path.'/vendor/autoload.php');
    File::ensureDirectoryExists($this->path.'/bootstrap/cache');
    File::put($this->path.'/bootstrap/cache/config.php', '<?php return [];');
    File::ensureDirectoryExists($this->path.'/public');
    File::put($this->path.'/public/hot', 'http://localhost:5173');
    $this->containers = [['Service' => 'mariadb', 'Name' => 'app-t9-mariadb-1', 'State' => 'running', 'Health' => '']];
    $strategy->onBringUp = function () {
        $this->containers[] = ['Service' => 'laravel.test', 'Name' => 'app-t9-laravel.test-1', 'State' => 'running', 'Health' => ''];
    };

    $this->artisan('worktree:doctor', ['worktree' => 't9'])->assertFailed();
    expect($strategy->calls)->toBe([]);

    $this->artisan('worktree:doctor', ['worktree' => 't9', '--fix' => true])->assertSuccessful();
    expect($strategy->calls)->toBe(['bringUp', 'composer', 'migrate'])
        ->and(File::exists($this->path.'/public/hot'))->toBeFalse();
    Process::assertRan(fn (PendingProcess $p) => str_contains((string) $p->command, 'php artisan config:clear && php artisan route:clear && php artisan event:clear'));
});

it('restarts worker containers that started before the last code change', function () {
    $this->containers[] = ['Service' => 'horizon', 'Name' => 'app-t9-horizon-1', 'State' => 'running', 'Health' => ''];
    $this->containers[] = ['Service' => 'phpmyadmin', 'Name' => 'app-t9-phpmyadmin-1', 'State' => 'running', 'Health' => ''];
    File::ensureDirectoryExists($this->path.'/app');
    File::put($this->path.'/app/User.php', '<?php');
    touch($this->path.'/app/User.php', time());
    $this->started = ['app-t9-horizon-1' => time() - 3600, 'app-t9-phpmyadmin-1' => time() - 3600, 'app-t9-laravel.test-1' => time() - 3600];
    $this->imageIds = ['app-t9-phpmyadmin-1' => 'sha256:pma'];
    $target = new WorktreeTarget($this->path, 't9', false, 'feature', 'abc', ['status' => 'ready']);
    $doctor = app(Doctor::class);

    expect($doctor->check('workers', $target))->toMatchArray(['state' => 'warn', 'message' => 'started before the last code change: horizon']);
    $doctor->fix('workers', $target);
    Process::assertRan("docker compose restart 'horizon'");
});

it('prints a short context with notes and the last log errors', function () {
    config()->set('worktrees.context.notes', ['Test login: see .claude/skills/app/login.md']);
    File::ensureDirectoryExists($this->path.'/storage/logs');
    File::put($this->path.'/storage/logs/laravel.log', implode("\n", [
        '[2026-11-10 10:00:00] local.INFO: fine',
        '[2026-11-10 10:00:01] local.ERROR: Boom {"exception":"[object] (RuntimeException(code: 0): Boom at /x.php:1)"}',
        '#0 /x.php(1): stack',
    ])."\n");

    $this->artisan('worktree:context', ['worktree' => 't9', '--no-db' => true])
        ->expectsOutputToContain('# Worktree t9')
        ->expectsOutputToContain('- URL: https://app-t9.test')
        ->expectsOutputToContain('- Test login: see .claude/skills/app/login.md')
        ->expectsOutputToContain('[2026-11-10 10:00:01] ERROR: Boom')
        ->assertSuccessful();
});

it('never fails in a session-start hook', function () {
    $this->artisan('worktree:context', ['worktree' => 'nope'])
        ->expectsOutputToContain('Worktree context unavailable')
        ->assertSuccessful();
});

it('reads compose ps output in both formats and memory units', function () {
    $line = ['Service' => 'a', 'Name' => 'p-a-1', 'State' => 'running', 'Health' => ''];
    expect(ComposeStack::parsePs(json_encode([$line])))->toHaveCount(1)
        ->and(ComposeStack::parsePs(json_encode($line)."\n".json_encode([...$line, 'Service' => 'b'])))->toHaveCount(2)
        ->and(ComposeStack::parseBytes('1.5GiB'))->toBe(1610612736)
        ->and(ComposeStack::parseBytes('512kB'))->toBe(512000)
        ->and(ComposeStack::parseBytes('0B'))->toBe(0);
});

it('finds stale workers of services that only build their image', function () {
    // No image names in the compose file: the image IDs of the containers count.
    Process::fake([
        "docker compose --profile '*' config --format json" => Process::result(json_encode(['name' => 'app-t9', 'services' => [
            'laravel.test' => ['build' => ['context' => '.']], 'horizon' => ['build' => ['context' => '.']],
        ]])),
    ]);
    ($this->fakeProcesses)();
    $this->containers[] = ['Service' => 'horizon', 'Name' => 'app-t9-horizon-1', 'State' => 'running', 'Health' => ''];
    File::ensureDirectoryExists($this->path.'/app');
    File::put($this->path.'/app/User.php', '<?php');
    $this->started = ['app-t9-horizon-1' => time() - 3600, 'app-t9-laravel.test-1' => time() - 3600];
    $target = new WorktreeTarget($this->path, 't9', false, 'feature', 'abc', ['status' => 'ready']);

    expect(app(Doctor::class)->check('workers', $target))->toMatchArray(['state' => 'warn']);
});

it('refuses exec and doctor fixes in a worktree with the main checkout\'s compose project', function () {
    $this->project = 'app';
    File::delete($this->path.'/vendor/autoload.php');

    $this->artisan('worktree:exec', ['cmd' => ['artisan', 'migrate'], '--worktree' => 't9'])
        ->expectsOutputToContain('compose project of the main checkout')
        ->assertFailed();
    Process::assertNotRan(fn (PendingProcess $p) => str_starts_with((string) $p->command, 'docker compose exec'));

    $this->artisan('worktree:doctor', ['worktree' => 't9', '--fix' => true])->assertFailed();
    Process::assertNotRan(fn (PendingProcess $p) => str_contains((string) $p->command, 'composer install'));
});

it('fixes native worktrees with the environment of the target', function () {
    config()->set('worktrees.bootstrap.strategy', 'native');
    app()->forgetInstance(BootstrapStrategy::class);
    app()->forgetInstance(WorktreeManager::class);
    $target = new WorktreeTarget($this->path, 't9', false, 'feature', 'abc', ['status' => 'ready']);

    app(Doctor::class)->fix('database', $target);
    Process::assertRan(fn (PendingProcess $p) => $p->command === 'php artisan migrate --force' && $p->path === $this->path
        && ($p->environment['DB_DATABASE'] ?? null) === false);
});

it('refuses a worktree whose compose project another checkout\'s containers carry', function () {
    $this->owner = $this->base.'/elsewhere';

    $this->artisan('worktree:exec', ['cmd' => ['artisan', 'about'], '--worktree' => 't9'])
        ->expectsOutputToContain("belongs to the containers of {$this->base}/elsewhere")
        ->assertFailed();
    $this->artisan('worktree:services', ['profiles' => ['workers'], '--worktree' => 't9'])->assertFailed();
});

it('reads * in COMPOSE_PROFILES as every profile', function () {
    File::append($this->path.'/.env', "COMPOSE_PROFILES=*\n");

    $this->artisan('worktree:services', ['profiles' => ['tools'], '--worktree' => 't9', '--add' => true])->assertSuccessful();
    Process::assertNotRan(fn (PendingProcess $p) => str_contains((string) $p->command, ' rm --stop --force'));
});

it('counts an edit inside an untracked folder as a code change', function () {
    File::ensureDirectoryExists($this->path.'/app/NewFeature');
    File::put($this->path.'/app/NewFeature/Job.php', '<?php');
    touch($this->path.'/app/NewFeature/Job.php', time() + 200);
    // The key of the default handler, so this result replaces it.
    Process::fake(['git status --porcelain *' => Process::result("?? app/NewFeature/Job.php\n")]);

    expect(app(WorktreeInspector::class)->lastCodeChange($this->path))->toBe(time() + 200);
});

it('removes a profile also when COMPOSE_PROFILES is *', function () {
    File::append($this->path.'/.env', "COMPOSE_PROFILES=*\n");

    $this->artisan('worktree:services', ['profiles' => ['workers'], '--worktree' => 't9', '--remove' => true])->assertSuccessful();
    expect(File::get($this->path.'/.env'))->toContain('COMPOSE_PROFILES=tools');
});

it('counts a deleted file as a code change', function () {
    File::ensureDirectoryExists($this->path.'/app');
    touch($this->path.'/app', time() + 100);
    // The key of the default handler, so this result replaces it.
    Process::fake(['git status --porcelain *' => Process::result(" D app/Gone.php\n")]);

    expect(app(WorktreeInspector::class)->lastCodeChange($this->path))->toBe(time() + 100);
});

it('refuses doctor fixes while a worktree setup holds its lock', function () {
    File::delete($this->path.'/vendor/autoload.php');
    $lock = SetupLock::tryAcquire($this->path);

    try {
        $this->artisan('worktree:doctor', ['worktree' => 't9', '--fix' => true])
            ->expectsOutputToContain('A worktree setup runs')
            ->assertFailed();
        Process::assertNotRan(fn (PendingProcess $p) => str_contains((string) $p->command, 'composer install'));

        File::put($this->path.'/storage/worktree-bootstrap.json', json_encode(['completed' => [], 'name' => 't9', 'status' => 'running']));
        $target = new WorktreeTarget($this->path, 't9', false, 'feature', 'abc', ['status' => 'running']);
        expect(app(Doctor::class)->check('setup', $target))->toMatchArray(['state' => 'warn']);
    } finally {
        SetupLock::release($lock);
    }
    expect(app(Doctor::class)->check('setup', $target))->toMatchArray(['state' => 'fail']);
});

it('fixes the main checkout only when it is named', function () {
    $this->artisan('worktree:doctor', ['--fix' => true])
        ->expectsOutputToContain('This is the main checkout')
        ->assertFailed();
    Process::assertNotRan(fn (PendingProcess $p) => str_contains((string) $p->command, 'migrate'));
});

it('finds the words of worktree:exec that artisan would read as its own options', function (array $argv, ?string $clash) {
    expect(WorktreeExecCommand::globalOptionClash($argv))->toBe($clash);
})->with([
    'redis-cli -h' => [['artisan', 'worktree:exec', 'redis-cli', '-h', 'redis', 'ping'], '-h'],
    'free -h' => [['artisan', 'worktree:exec', '-w', 't9', 'free', '-h'], '-h'],
    'composer --version' => [['artisan', 'worktree:exec', 'composer', '--version'], '--version'],
    'glued -q' => [['artisan', '-v', 'worktree:exec', 'test', '-qq'], '-qq'],
    'after --' => [['artisan', 'worktree:exec', '-w', 't9', '--', 'free', '-h'], null],
    'no global option' => [['artisan', 'worktree:exec', 'test', '--filter=X'], null],
    'another command' => [['artisan', 'about', '--version'], null],
    'artisan own -q before the name' => [['artisan', '-q', 'worktree:exec', 'test'], null],
]);

it('passes piped input on to the command', function () {
    $stdin = fopen('php://memory', 'r+');
    fwrite($stdin, "select 1;\n");
    rewind($stdin);
    app()->bind(WorktreeExecCommand::class, fn () => new class($stdin) extends WorktreeExecCommand
    {
        public function __construct(private readonly mixed $stdin)
        {
            parent::__construct();
        }

        protected function pipedInput(): mixed
        {
            return $this->stdin;
        }
    });

    $this->artisan('worktree:exec', ['cmd' => ['mariadb'], '--worktree' => 't9', '--service' => 'mariadb'])->assertSuccessful();
    Process::assertRan(fn (PendingProcess $p) => str_starts_with((string) $p->command, 'docker compose exec') && $p->input === $stdin);
});

it('accepts the containers of a worktree whose compose file is in a subfolder', function () {
    File::ensureDirectoryExists($this->path.'/docker');
    File::append($this->path.'/.env', "COMPOSE_FILE=docker/compose.yml:docker/compose.dev.yml\n");
    // Compose writes the folder of the first compose file into working_dir.
    $this->owner = $this->path.'/docker';

    $this->artisan('worktree:exec', ['cmd' => ['artisan', 'about'], '--worktree' => 't9'])->assertSuccessful();

    $this->owner = $this->base.'/elsewhere/docker';
    $this->artisan('worktree:exec', ['cmd' => ['artisan', 'about'], '--worktree' => 't9'])->assertFailed();
});
