<?php

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Woda\Worktrees\Contracts\BootstrapStrategy;
use Woda\Worktrees\DatabaseCloner;
use Woda\Worktrees\Fast\AppImage;
use Woda\Worktrees\Fast\BuildCache;
use Woda\Worktrees\Fast\ContentKey;
use Woda\Worktrees\Fast\DatabaseDump;
use Woda\Worktrees\Fast\FastSetup;
use Woda\Worktrees\SailBootstrapStrategy;
use Woda\Worktrees\Shared\DatabaseProvisioner;
use Woda\Worktrees\Shared\EnvFile;
use Woda\Worktrees\Shared\MainCheckout;
use Woda\Worktrees\SharedServicesBootstrapStrategy;
use Woda\Worktrees\WorktreeManager;
use Woda\Worktrees\WorktreesServiceProvider;

/*
 * The fast setup of the Sail strategy: shared app image, offline-first
 * Composer, the node install in parallel, the frontend build cache and the
 * database dump. Docker is faked; the build cache runs real git.
 */
beforeEach(function () {
    $this->base = sys_get_temp_dir().'/worktrees-fast-'.uniqid();
    File::ensureDirectoryExists($this->base);
    $this->envBefore = File::exists(base_path('.env')) ? File::get(base_path('.env')) : null;
    File::put(base_path('.env'), "APP_NAME=App\nAPP_URL=https://app.test\nCOMPOSE_PROJECT_NAME=app\nDB_DATABASE=app\n");
    config()->set('app.url', 'https://app.test');
    config()->set('worktrees.base_path', $this->base);
    config()->set('worktrees.copy_files', ['.env']);
    config()->set('worktrees.database.strategy', 'none');
    config()->set('worktrees.bootstrap.strategy', 'sail');
    config()->set('worktrees.bootstrap.build_frontend', false);
    config()->set('worktrees.env_overrides', ['COMPOSE_PROJECT_NAME' => 'app-{name}']);
    $this->path = $this->base.'/'.basename(base_path()).'-t9';
    $this->images = [];
    $this->composerOffline = Process::result();
    $this->migrateStatus = Process::result(errorOutput: 'Migration table not found.', exitCode: 1);
    $this->dbShow = Process::result('{"platform":{"name":"MariaDB"},"tables":[]}');
    $this->fake = function (array $extra = []) {
        // The extra handlers win, also over a base handler of the same pattern.
        Process::fake($extra + [
            'git show-ref *' => Process::result(exitCode: 1),
            'git worktree add *' => function () {
                File::ensureDirectoryExists($this->path);
                File::put($this->path.'/Dockerfile', 'FROM alpine');
                File::ensureDirectoryExists($this->path.'/database/migrations');
                File::put($this->path.'/database/migrations/0001_create.php', '<?php');

                return Process::result();
            },
            'git worktree list --porcelain' => fn () => Process::result('worktree '.base_path()."\nHEAD 000\nbranch refs/heads/master\n\nworktree {$this->path}\nHEAD abc\nbranch refs/heads/t9\n\n"),
            "docker compose exec -T 'laravel.test' sh -c *echo*" => Process::result('ready'),
            'docker image inspect *' => function (PendingProcess $p) {
                preg_match("/'([^']+)'$/", (string) $p->command, $m);

                return Process::result(exitCode: in_array($m[1], $this->images, true) ? 0 : 1);
            },
            "docker compose build 'laravel.test'" => function () {
                $this->images[] = EnvFile::get($this->path.'/.env', 'WORKTREE_APP_IMAGE');

                return Process::result();
            },
            '*COMPOSER_DISABLE_NETWORK=1*composer install*' => fn () => $this->composerOffline,
            '*migrate:status*' => fn () => $this->migrateStatus,
            '*db:show --json*' => fn () => $this->dbShow,
            '*' => Process::result(),
        ]);
    };
    MainCheckout::forget();
});

afterEach(function () {
    File::deleteDirectory($this->base);
    $this->envBefore === null ? File::delete(base_path('.env')) : File::put(base_path('.env'), $this->envBefore);
    File::deleteDirectory(base_path('storage/app/worktrees'));
    MainCheckout::forget();
});

$strategy = fn (FastSetup $fast) => app()->instance(BootstrapStrategy::class, new SailBootstrapStrategy('laravel.test', 'pnpm', false, fast: $fast));

it('builds the shared app image once and writes its tag to .env', function () use ($strategy) {
    ($this->fake)();
    $strategy(new FastSetup(image: new AppImage('app-image')));

    $this->artisan('worktree:create', ['name' => 't9', '--skip-deps' => true, '--skip-db' => true])->assertSuccessful();
    $tag = 'app-image:'.ContentKey::of($this->path, ['Dockerfile', 'docker']);
    expect(File::get($this->path.'/.env'))->toContain("WORKTREE_APP_IMAGE={$tag}");
    Process::assertRanTimes("docker compose build 'laravel.test'", 1);

    // A second start finds the image.
    app(BootstrapStrategy::class)->bringUp($this->path);
    Process::assertRanTimes("docker compose build 'laravel.test'", 1);
});

it('stops when the build does not make the tag the compose file should name', function () use ($strategy) {
    ($this->fake)(["docker compose build 'laravel.test'" => Process::result()]);
    $strategy(new FastSetup(image: new AppImage('app-image')));

    $this->artisan('worktree:create', ['name' => 't9', '--skip-deps' => true, '--skip-db' => true])
        ->expectsOutputToContain('must name the image as ${WORKTREE_APP_IMAGE}')
        ->assertFailed();
});

it('installs Composer packages offline first and with the network after a cache miss', function () use ($strategy) {
    ($this->fake)();
    $strategy(new FastSetup(composerCacheDir: '/composer-cache'));
    File::ensureDirectoryExists($this->path);

    app(BootstrapStrategy::class)->installComposerDependencies($this->path);
    Process::assertRan("docker compose exec -T -e 'COMPOSER_DISABLE_NETWORK=1' -e 'COMPOSER_CACHE_DIR=/composer-cache' 'laravel.test' sh -c 'composer install --no-interaction --prefer-dist'");
    Process::assertNotRan("docker compose exec -T -e 'COMPOSER_CACHE_DIR=/composer-cache' 'laravel.test' sh -c 'composer install --no-interaction --prefer-dist'");

    $this->composerOffline = Process::result(errorOutput: 'cache miss', exitCode: 1);
    app(BootstrapStrategy::class)->installComposerDependencies($this->path);
    Process::assertRan("docker compose exec -T -e 'COMPOSER_CACHE_DIR=/composer-cache' 'laravel.test' sh -c 'composer install --no-interaction --prefer-dist'");
});

it('runs Composer on the host when configured', function () use ($strategy) {
    ($this->fake)();
    $strategy(new FastSetup(composerRunner: 'host'));
    File::ensureDirectoryExists($this->path);

    app(BootstrapStrategy::class)->installComposerDependencies($this->path);
    Process::assertRan(fn (PendingProcess $p) => $p->command === 'COMPOSER_DISABLE_NETWORK=1 composer install --no-interaction --prefer-dist --ignore-platform-reqs' && $p->path === $this->path);
});

it('installs node packages while the containers start when no container mounts node_modules', function () use ($strategy) {
    ($this->fake)(['docker compose config --format json' => Process::result(json_encode(['services' => ['laravel.test' => ['volumes' => [['type' => 'bind', 'target' => '/var/www/html']]]]]))]);
    $strategy(new FastSetup);

    $this->artisan('worktree:create', ['name' => 't9', '--skip-db' => true])->assertSuccessful();
    $record = json_decode(File::get($this->path.'/storage/worktree-bootstrap.json'), true);
    expect($record['completed'])->toContain('node');
    // Into a file: a pipe that nothing reads would block the install at 64 KiB.
    Process::assertRan(fn (PendingProcess $p) => $p->command === "pnpm install --prefer-offline > 'storage/logs/worktree-node-install.log' 2>&1" && $p->quietly);
    expect(File::get($this->path.'/storage/logs/worktree-setup.log'))->toContain('node (in parallel)');
});

it('installs node packages first when the strategy has its own node install', function () {
    ($this->fake)(['docker compose config --format json' => Process::result(json_encode(['services' => ['laravel.test' => ['volumes' => []]]]))]);
    app()->instance(BootstrapStrategy::class, new class('laravel.test', 'pnpm', false, fast: new FastSetup) extends SailBootstrapStrategy
    {
        public function installNodeDependencies(string $worktreePath, ?Closure $output = null): void
        {
            Process::run('custom-node-install');
        }
    });

    $this->artisan('worktree:create', ['name' => 't9', '--skip-db' => true])->assertSuccessful();
    Process::assertRan('custom-node-install');
    expect(File::get($this->path.'/storage/logs/worktree-setup.log'))->not->toContain('node (in parallel)');
});

it('fails the node stage of a parallel install with the end of its log', function () {
    File::ensureDirectoryExists($this->base.'/n/storage/logs');
    File::put($this->base.'/n/'.SailBootstrapStrategy::NODE_INSTALL_LOG, "resolving\nERR_PNPM_FETCH_404 left-pad\n");
    ($this->fake)(['pnpm install *' => Process::result(exitCode: 1)]);
    $strategy = new SailBootstrapStrategy('laravel.test', 'pnpm', false, fast: new FastSetup);
    $process = Process::path($this->base.'/n')->start('pnpm install --prefer-offline');
    $seen = '';

    $collect = function (string $type, string $buffer) use (&$seen): void {
        $seen .= $buffer;
    };

    expect(fn () => $strategy->finishNodeInstall($process, $this->base.'/n', $collect))->toThrow(RuntimeException::class, 'ERR_PNPM_FETCH_404');
    expect($seen)->toContain('resolving');
});

it('installs node packages first when a container mounts a volume at node_modules', function () use ($strategy) {
    ($this->fake)(['docker compose config --format json' => Process::result(json_encode(['services' => ['laravel.test' => ['volumes' => [['type' => 'volume', 'target' => '/var/www/html/node_modules']]]]]))]);
    $strategy(new FastSetup);

    $this->artisan('worktree:create', ['name' => 't9', '--skip-db' => true])->assertSuccessful();
    expect(File::get($this->path.'/storage/logs/worktree-setup.log'))->not->toContain('node (in parallel)');
});

it('restores the dump into an empty database and skips the seed', function () use ($strategy) {
    ($this->fake)();
    $dump = new DatabaseDump(true);
    $strategy(new FastSetup(dump: $dump));
    // The worktree's key is known after the checkout exists; store the dump for it then.
    Process::fake(['git worktree add *' => function () use ($dump) {
        File::ensureDirectoryExists($this->path.'/database/migrations');
        File::put($this->path.'/database/migrations/0001_create.php', '<?php');
        $file = $dump->path($dump->key($this->path));
        File::ensureDirectoryExists(dirname($file));
        File::put($file, gzencode('-- dump'));

        return Process::result();
    }]);

    $this->artisan('worktree:create', ['name' => 't9'])->assertSuccessful();
    Process::assertRan(fn (PendingProcess $p) => str_contains((string) $p->command, "gunzip -c '".base_path('storage/app/worktrees/dumps/'))
        && str_contains((string) $p->command, "docker compose exec -T 'mariadb' sh -c"));
    Process::assertNotRan(fn (PendingProcess $p) => str_contains((string) $p->command, 'db:seed'));
    $record = json_decode(File::get($this->path.'/storage/worktree-bootstrap.json'), true);
    expect($record['completed'])->toContain('dump')->not->toContain('seed');
});

it('seeds and saves a dump when none exists for the key', function () use ($strategy) {
    ($this->fake)(["docker compose exec -T 'mariadb' sh -c * > *" => function (PendingProcess $p) {
        preg_match("/> '([^']+)'$/", (string) $p->command, $m);
        File::put($m[1], '-- dump');

        return Process::result();
    }, "'gzip' *" => function (PendingProcess $p) {
        $file = end($p->command);
        File::put($file.'.gz', gzencode(File::get($file)));
        File::delete($file);

        return Process::result();
    }]);
    $dump = new DatabaseDump(true, seedCommand: 'php artisan app:seed-test-data');
    $strategy(new FastSetup(dump: $dump));

    $this->artisan('worktree:create', ['name' => 't9'])->assertSuccessful();
    Process::assertRan("docker compose exec -T 'laravel.test' sh -c 'php artisan app:seed-test-data'");
    expect(File::exists($dump->path($dump->key($this->path))))->toBeTrue();
    $record = json_decode(File::get($this->path.'/storage/worktree-bootstrap.json'), true);
    expect($record['completed'])->toContain('dump', 'migrations', 'seed', 'dump-save');
});

it('saves the dump again on a resume without seeding again', function () use ($strategy) {
    $this->saveFails = true;
    ($this->fake)(["docker compose exec -T 'mariadb' sh -c * > *" => function (PendingProcess $p) {
        if ($this->saveFails) {
            return Process::result(errorOutput: 'disk full', exitCode: 1);
        }
        preg_match("/> '([^']+)'$/", (string) $p->command, $m);
        File::put($m[1], '-- dump');

        return Process::result();
    }, "'gzip' *" => function (PendingProcess $p) {
        $file = end($p->command);
        File::put($file.'.gz', gzencode(File::get($file)));
        File::delete($file);

        return Process::result();
    }]);
    $dump = new DatabaseDump(true, seedCommand: 'php artisan app:seed-test-data');
    $strategy(new FastSetup(dump: $dump));

    $this->artisan('worktree:create', ['name' => 't9'])->expectsOutputToContain('Saving the database dump failed')->assertFailed();
    Process::assertRanTimes("docker compose exec -T 'laravel.test' sh -c 'php artisan app:seed-test-data'", 1);
    $record = json_decode(File::get($this->path.'/storage/worktree-bootstrap.json'), true);
    expect($record['completed'])->toContain('seed')->not->toContain('dump-save');

    $this->saveFails = false;
    $this->artisan('worktree:bootstrap', ['name' => 't9', '--resume' => true])->assertSuccessful();
    Process::assertRanTimes("docker compose exec -T 'laravel.test' sh -c 'php artisan app:seed-test-data'", 1);
    expect(File::exists($dump->path($dump->key($this->path))))->toBeTrue();
    $record = json_decode(File::get($this->path.'/storage/worktree-bootstrap.json'), true);
    expect($record['completed'])->toContain('seed', 'dump-save');
});

it('does not restore into a database with tables', function () use ($strategy) {
    ($this->fake)();
    $this->migrateStatus = Process::result('  0001_create ..... [1] Ran');
    $strategy(new FastSetup(dump: new DatabaseDump(true)));
    File::ensureDirectoryExists($this->path);

    expect(app(BootstrapStrategy::class)->restoreDatabaseDump($this->path))->toBe('existing');
    Process::assertNotRan(fn (PendingProcess $p) => str_contains((string) $p->command, 'gunzip'));
});

it('restores again after a restore that stopped half way, also over its tables', function () use ($strategy) {
    ($this->fake)();
    $this->migrateStatus = Process::result('  0001_create ..... [1] Ran');
    $dump = new DatabaseDump(true);
    $strategy(new FastSetup(dump: $dump));
    File::ensureDirectoryExists($this->path.'/database/migrations');
    File::ensureDirectoryExists($this->path.'/storage/app/worktree');
    File::put($this->path.'/storage/app/worktree/dump-restore.pending', 'stopped');
    File::put($this->path.'/.env', "DB_DATABASE=app_t9\n");
    $file = $dump->path($dump->key($this->path));
    File::ensureDirectoryExists(dirname($file));
    File::put($file, gzencode('-- dump'));

    expect(app(BootstrapStrategy::class)->restoreDatabaseDump($this->path))->toBe('restored')
        ->and(File::exists($this->path.'/storage/app/worktree/dump-restore.pending'))->toBeFalse();
    Process::assertRan(fn (PendingProcess $p) => str_contains((string) $p->command, 'gunzip -c'));
    Process::assertNotRan(fn (PendingProcess $p) => str_contains((string) $p->command, 'migrate:status'));
});

it('treats the missing migrations table as empty also when migrate:status exits with 0', function () use ($strategy) {
    ($this->fake)();
    $this->migrateStatus = Process::result('ERROR  Migration table not found.');
    $strategy(new FastSetup(dump: new DatabaseDump(true)));
    File::ensureDirectoryExists($this->path);

    expect(app(BootstrapStrategy::class)->restoreDatabaseDump($this->path))->toBe('missing');
});

it('neither restores nor seeds when a setup runs again on a database with tables', function () use ($strategy) {
    // A database with tables, as after an earlier setup: a run without --resume keeps it.
    $this->migrateStatus = Process::result('  0001_create ..... [1] Ran');
    ($this->fake)();
    $strategy(new FastSetup(dump: new DatabaseDump(true)));

    $this->artisan('worktree:create', ['name' => 't9'])->assertSuccessful();
    Process::assertNotRan(fn (PendingProcess $p) => str_contains((string) $p->command, 'db:seed'));
    expect(json_decode(File::get($this->path.'/storage/worktree-bootstrap.json'), true)['dump'])->toBe('existing');
});

it('lends Sail\'s build context from vendor before it builds the shared image', function () use ($strategy) {
    // Testbench's vendor is the package's own: create and remove only laravel/sail, which no dependency uses.
    $sail = base_path('vendor/laravel/sail');
    expect(is_dir($sail))->toBeFalse();
    $runtime = $sail.'/runtimes/8.4';
    File::ensureDirectoryExists($runtime);
    File::put($runtime.'/Dockerfile', 'FROM alpine');
    $this->contextAtBuild = null;
    $this->tagAtBuild = null;
    ($this->fake)([
        'docker compose config --format json' => fn (PendingProcess $p) => Process::result(json_encode($p->path === $this->path
            ? ['name' => 'app-t9', 'services' => ['laravel.test' => ['build' => ['context' => $this->path.'/vendor/laravel/sail/runtimes/8.4']]]]
            : ['name' => 'app', 'services' => []])),
        "docker compose build 'laravel.test'" => function () {
            $this->contextAtBuild = is_file($this->path.'/vendor/laravel/sail/runtimes/8.4/Dockerfile');
            // The tag covers the lent context's files.
            $this->tagAtBuild = (new AppImage('app-image'))->tag($this->path, ['context' => $this->path.'/vendor/laravel/sail/runtimes/8.4']);
            $this->images[] = EnvFile::get($this->path.'/.env', 'WORKTREE_APP_IMAGE');

            return Process::result();
        },
    ]);
    $strategy(new FastSetup(image: new AppImage('app-image')));

    try {
        $this->artisan('worktree:create', ['name' => 't9', '--skip-deps' => true, '--skip-db' => true])->assertSuccessful();
    } finally {
        File::deleteDirectory($sail);
    }
    expect($this->contextAtBuild)->toBeTrue()
        ->and(EnvFile::get($this->path.'/.env', 'WORKTREE_APP_IMAGE'))->toBe($this->tagAtBuild)
        ->and(is_dir($this->path.'/vendor/laravel'))->toBeFalse();
});

it('keys the shared image by the build section and the files of its build context', function () {
    $worktree = $this->base.'/wt';
    $other = $this->base.'/other';
    foreach ([$worktree, $other] as $root) {
        File::ensureDirectoryExists($root.'/vendor/laravel/sail/runtimes/8.4');
        File::put($root.'/vendor/laravel/sail/runtimes/8.4/Dockerfile', 'FROM php:8.4');
        File::ensureDirectoryExists($root.'/vendor/laravel/sail/runtimes/8.5');
        File::put($root.'/vendor/laravel/sail/runtimes/8.5/Dockerfile', 'FROM php:8.5');
    }
    $image = new AppImage('app-image');
    $build = fn (string $root, string $runtime = '8.4', string $node = '22') => ['context' => $root.'/vendor/laravel/sail/runtimes/'.$runtime, 'dockerfile' => 'Dockerfile', 'args' => ['WWWGROUP' => '1000', 'NODE_VERSION' => $node]];
    $tag = $image->tag($worktree, $build($worktree));

    // Without a Dockerfile in the hash paths, the build inputs still make the key.
    expect($tag)->not->toBe($image->tag($worktree))
        // Equal inputs in another worktree: the same image.
        ->and($image->tag($other, $build($other)))->toBe($tag)
        // Another runtime, another build argument.
        ->and($image->tag($worktree, $build($worktree, '8.5')))->not->toBe($tag)
        ->and($image->tag($worktree, $build($worktree, node: '24')))->not->toBe($tag);

    // A Sail update that changes the runtime's files.
    File::put($worktree.'/vendor/laravel/sail/runtimes/8.4/Dockerfile', 'FROM php:8.4\nRUN true');
    expect($image->tag($worktree, $build($worktree)))->not->toBe($tag);
});

it('downloads a missing dump and keeps a failed download out of the way', function () {
    config()->set('worktrees.base_path', $this->base);
    File::ensureDirectoryExists($this->path.'/database/migrations');
    $dump = new DatabaseDump(true, url: 'https://ci.example/dumps/{key}.sql.gz', urlHeader: 'Authorization: Bearer x');
    $key = $dump->key($this->path);
    Process::fake([
        "'curl' *" => function (PendingProcess $p) {
            $out = $p->command[array_search('-o', $p->command, true) + 1];
            File::put($out, gzencode('-- dump'));

            return Process::result();
        },
        '*' => Process::result(),
    ]);

    expect($dump->find($this->path))->toBe($dump->path($key));
    // The header (a token) goes through stdin, not the command line.
    Process::assertRan(fn (PendingProcess $p) => is_array($p->command) && in_array("https://ci.example/dumps/{$key}.sql.gz", $p->command, true)
        && in_array('@-', $p->command, true) && ! in_array('Authorization: Bearer x', $p->command, true) && $p->input === "Authorization: Bearer x\n");

    File::delete($dump->path($key));
    Process::fake(["'curl' *" => Process::result(exitCode: 22), '*' => Process::result()]);
    expect($dump->find($this->path))->toBeNull()
        ->and(glob(dirname($dump->path($key)).'/*'))->toBe([]);
});

it('keys templates as before', function () {
    File::ensureDirectoryExists($this->base.'/m/database/migrations');
    File::put($this->base.'/m/database/migrations/a.php', 'x');
    $context = hash_init('sha256');
    hash_update($context, "seed:s\n");
    hash_update($context, 'database/migrations/a.php'."\0".hash('sha256', 'x')."\n");

    expect(ContentKey::of($this->base.'/m', ['database/migrations'], "seed:s\n"))->toBe(substr(hash_final($context), 0, 12));
});

describe('the frontend build cache', function () {
    beforeEach(function () {
        $this->main = $this->base.'/main';
        $this->tree = $this->base.'/tree';
        $git = fn (string $dir, string $cmd) => Process::path($dir)->run('git -c user.email=t@example.invalid -c user.name=t '.$cmd)->throw();
        File::ensureDirectoryExists($this->main.'/resources/js');
        File::put($this->main.'/resources/js/app.js', 'console.log(1)');
        File::put($this->main.'/package.json', '{}');
        File::put($this->main.'/.gitignore', "/public/build\n/storage\n");
        $git($this->main, 'init -q -b main');
        $git($this->main, 'add -A');
        $git($this->main, 'commit -qm init');
        $git($this->main, 'worktree add -q '.escapeshellarg($this->tree).' -b t');
        $this->git = $git;
        $this->cache = new BuildCache(true, ['resources', 'package.json'], 'sail:pnpm', mainCheckout: $this->main);
    });

    it('keys ignored build inputs by their content on disk', function () {
        // Flux's CSS under an ignored vendor folder: git sees no change there.
        File::put($this->tree.'/.gitignore', "/public/build\n/storage\n/vendor\n");
        File::ensureDirectoryExists($this->tree.'/vendor/flux/dist');
        File::put($this->tree.'/vendor/flux/dist/flux.css', '.a{}');
        ($this->git)($this->tree, 'add .gitignore');
        ($this->git)($this->tree, 'commit -qm ignore');
        $cache = new BuildCache(true, ['resources', 'package.json', 'vendor/flux/dist'], 'sail:pnpm', mainCheckout: $this->main);
        $key = $cache->key($this->tree);

        File::put($this->tree.'/vendor/flux/dist/flux.css', '.b{}');
        expect($key)->not->toBeNull()
            ->and($cache->key($this->tree))->not->toBe($key);
    });

    it('installs the shared app container\'s node packages when it copies a build', function () {
        File::ensureDirectoryExists($this->main.'/public/build');
        File::put($this->main.'/public/build/manifest.json', '{"main":1}');
        touch($this->main.'/public/build/manifest.json', time() + 5);
        $strategy = Mockery::mock(SharedServicesBootstrapStrategy::class);
        $strategy->shouldReceive('installContainerNodeDependencies')->once()->with($this->tree, null);
        $strategy->shouldNotReceive('buildFrontend');
        $manager = new WorktreeManager(
            basePath: $this->base, branchPrefix: '', baseBranch: 'main', copyFiles: [],
            databaseCloner: new DatabaseCloner('none', false, null, null, '127.0.0.1'),
            bootstrapStrategy: $strategy, buildFrontend: true, runMigrations: false, buildCache: $this->cache,
        );

        (new ReflectionMethod($manager, 'restoreOrBuildFrontend'))->invoke($manager, $this->tree, null, fn () => null, fn () => null);
        expect(File::exists($this->tree.'/public/build/manifest.json'))->toBeTrue();
    });

    it('copies the main checkout\'s build when it is newer than its last checkout, and keeps it', function () {
        File::ensureDirectoryExists($this->main.'/public/build');
        File::put($this->main.'/public/build/manifest.json', '{"main":1}');
        touch($this->main.'/public/build/manifest.json', time() + 5);

        expect($this->cache->key($this->tree))->toBe($this->cache->key($this->main))
            ->and($this->cache->restore($this->tree))->toBe('main checkout')
            ->and(File::get($this->tree.'/public/build/manifest.json'))->toBe('{"main":1}');

        File::deleteDirectory($this->tree.'/public/build');
        File::deleteDirectory($this->main.'/public/build');
        expect($this->cache->restore($this->tree))->toBe('cache');
    });

    it('does not use a build older than one of the main checkout\'s inputs (a discarded change)', function () {
        File::ensureDirectoryExists($this->main.'/public/build');
        File::put($this->main.'/public/build/manifest.json', '{}');
        touch($this->main.'/public/build/manifest.json', time() + 5);
        touch($this->main.'/resources/js/app.js', time() + 10);

        expect($this->cache->restore($this->tree))->toBeNull();
    });

    it('does not use a build older than the main checkout\'s .env (its VITE_ values may differ)', function () {
        File::ensureDirectoryExists($this->main.'/public/build');
        File::put($this->main.'/public/build/manifest.json', '{}');
        touch($this->main.'/public/build/manifest.json', time() + 5);
        File::put($this->main.'/.env', "VITE_APP_HOST=new.test\n");
        File::put($this->tree.'/.env', "VITE_APP_HOST=new.test\n");
        touch($this->main.'/.env', time() + 10);

        expect($this->cache->key($this->tree))->toBe($this->cache->key($this->main))
            ->and($this->cache->restore($this->tree))->toBeNull();
    });

    it('does not use a build older than the main checkout\'s last checkout', function () {
        File::ensureDirectoryExists($this->main.'/public/build');
        File::put($this->main.'/public/build/manifest.json', '{}');
        touch($this->main.'/public/build/manifest.json', time() - 3600);

        expect($this->cache->restore($this->tree))->toBeNull();
    });

    it('has no key with uncommitted changes in the inputs, and a new key after a commit', function () {
        $before = $this->cache->key($this->tree);
        File::put($this->tree.'/resources/js/app.js', 'console.log(2)');
        expect($this->cache->key($this->tree))->toBeNull();

        ($this->git)($this->tree, 'commit -qam change');
        expect($this->cache->key($this->tree))->not->toBeNull()->not->toBe($before);
    });

    it('gives worktrees with other VITE_ values another key', function () {
        File::put($this->main.'/.env', "APP_NAME=Main\nVITE_APP_HOST=main.test\n");
        File::put($this->tree.'/.env', "APP_NAME=Tree\nVITE_APP_HOST=main.test\n");
        expect($this->cache->key($this->tree))->toBe($this->cache->key($this->main));

        File::put($this->tree.'/.env', "APP_NAME=Tree\nVITE_APP_HOST=tree.test\n");
        expect($this->cache->key($this->tree))->not->toBe($this->cache->key($this->main));
    });

    it('stores a fresh build and prunes old entries', function () {
        $cache = new BuildCache(true, ['resources', 'package.json'], 'sail:pnpm', keep: 1, mainCheckout: $this->main);
        File::ensureDirectoryExists($this->tree.'/public/build');
        File::put($this->tree.'/public/build/manifest.json', '{"tree":1}');
        $cache->store($this->tree);
        $key = $cache->key($this->tree);
        expect(File::exists($this->main.'/storage/app/worktrees/builds/'.$key.'/manifest.json'))->toBeTrue();

        File::put($this->tree.'/resources/js/app.js', 'console.log(3)');
        ($this->git)($this->tree, 'commit -qam change');
        touch($this->main.'/storage/app/worktrees/builds/'.$key, time() - 60);
        $cache->store($this->tree);
        expect(glob($this->main.'/storage/app/worktrees/builds/*'))->toHaveCount(1)
            ->and(File::exists($this->main.'/storage/app/worktrees/builds/'.$key))->toBeFalse();
    });
});

it('gives a subclass of a strategy the configured fast setup without a fast argument', function () {
    ($this->fake)();
    config()->set('worktrees.fast.composer.cache_dir', '/composer-cache');
    File::ensureDirectoryExists($this->path);
    $sail = new class('laravel.test', 'pnpm', false) extends SailBootstrapStrategy {};
    $shared = new class(app(DatabaseProvisioner::class), 'laravel.test', 'pnpm', false) extends SharedServicesBootstrapStrategy {};

    foreach ([$sail, $shared] as $subclass) {
        $subclass->installComposerDependencies($this->path);
    }

    Process::assertRanTimes("docker compose exec -T -e 'COMPOSER_DISABLE_NETWORK=1' -e 'COMPOSER_CACHE_DIR=/composer-cache' 'laravel.test' sh -c 'composer install --no-interaction --prefer-dist'", 2);
});

it('keeps an explicit fast argument over the configured fast setup', function () {
    ($this->fake)();
    config()->set('worktrees.fast.composer.cache_dir', '/composer-cache');
    File::ensureDirectoryExists($this->path);

    (new SailBootstrapStrategy('laravel.test', 'pnpm', false, fast: new FastSetup(composerCacheDir: '/explicit')))->installComposerDependencies($this->path);
    (new SharedServicesBootstrapStrategy(app(DatabaseProvisioner::class), 'laravel.test', 'pnpm', false, fast: new FastSetup(composerCacheDir: '/explicit')))->installComposerDependencies($this->path);

    Process::assertRanTimes("docker compose exec -T -e 'COMPOSER_DISABLE_NETWORK=1' -e 'COMPOSER_CACHE_DIR=/explicit' 'laravel.test' sh -c 'composer install --no-interaction --prefer-dist'", 2);
    Process::assertNotRan(fn (PendingProcess $p) => str_contains((string) $p->command, '/composer-cache'));
});

it('does not copy vendor from the main checkout by default', function () {
    $published = require __DIR__.'/../../config/worktrees.php';
    config()->set('worktrees.fast.composer', []);

    expect((new FastSetup)->copyVendor)->toBeFalse()
        ->and($published['fast']['composer']['copy_vendor'])->toBeFalse()
        ->and(app(FastSetup::class)->copyVendor)->toBeFalse();

    config()->set('worktrees.fast.composer.copy_vendor', true);
    expect(app(FastSetup::class)->copyVendor)->toBeTrue();
});

it('counts hidden files of the build context and the hash paths in the image key', function () {
    $worktree = $this->base.'/wt';
    $outside = $this->base.'/outside-context';
    File::ensureDirectoryExists($worktree.'/docker');
    File::put($worktree.'/docker/Dockerfile', 'FROM php');
    File::ensureDirectoryExists($outside);
    File::put($outside.'/Dockerfile', 'FROM php');
    $image = new AppImage('app-image', hashPaths: ['docker']);
    $inside = $image->tag($worktree, ['context' => $worktree.'/docker']);
    $external = $image->tag($worktree, ['context' => $outside]);

    File::put($worktree.'/docker/.dockerignore', 'vendor');
    File::put($outside.'/.dockerignore', 'vendor');

    expect($image->tag($worktree, ['context' => $worktree.'/docker']))->not->toBe($inside)
        ->and($image->tag($worktree, ['context' => $outside]))->not->toBe($external);
});

it('keeps hidden files out of the template and dump keys', function () {
    File::ensureDirectoryExists($this->base.'/m/database/migrations');
    File::put($this->base.'/m/database/migrations/a.php', 'x');
    $key = ContentKey::of($this->base.'/m', ['database/migrations']);
    File::put($this->base.'/m/database/migrations/.gitkeep', '');

    expect(ContentKey::of($this->base.'/m', ['database/migrations']))->toBe($key);
});

it('has every node lock file in the default build inputs', function () {
    $published = require __DIR__.'/../../config/worktrees.php';

    expect($published['fast']['build_cache']['paths'])->toContain('pnpm-lock.yaml', 'package-lock.json', 'yarn.lock', 'bun.lock', 'bun.lockb');
});

it('has the frontend build cache off by default', function () {
    $published = require __DIR__.'/../../config/worktrees.php';
    config()->set('worktrees.fast', []);

    expect($published['fast']['build_cache']['enabled'])->toBeFalse()
        ->and(WorktreesServiceProvider::buildCache('pnpm')->enabled())->toBeFalse();
});

it('keys the shared image by a Dockerfile outside the hash paths', function () {
    $worktree = $this->base.'/wt-dockerfile';
    File::ensureDirectoryExists($worktree.'/.docker/php');
    File::put($worktree.'/.docker/php/Dockerfile', 'FROM php:8.4');
    $image = new AppImage('app-image');
    $build = ['context' => $worktree, 'dockerfile' => '.docker/php/Dockerfile'];
    $tag = $image->tag($worktree, $build);

    File::put($worktree.'/.docker/php/Dockerfile', 'FROM php:8.5');
    expect($image->tag($worktree, $build))->not->toBe($tag);

    // A Dockerfile outside the worktree counts by its content.
    $outside = $this->base.'/outside-dockerfile/Dockerfile';
    File::ensureDirectoryExists(dirname($outside));
    File::put($outside, 'FROM php:8.4');
    $external = $image->tag($worktree, ['context' => $worktree, 'dockerfile' => $outside]);
    File::put($outside, 'FROM php:8.5');
    expect($image->tag($worktree, ['context' => $worktree, 'dockerfile' => $outside]))->not->toBe($external);
});

it('reads the root password of Sail\'s MariaDB service in the default dump commands', function () {
    $published = require __DIR__.'/../../config/worktrees.php';

    foreach ([DatabaseDump::DUMP_COMMAND, DatabaseDump::RESTORE_COMMAND, $published['fast']['database_dump']['dump_command'], $published['fast']['database_dump']['restore_command']] as $command) {
        expect($command)->toContain('${MARIADB_ROOT_PASSWORD:-$MYSQL_ROOT_PASSWORD}')->not->toContain('-p"');
    }
    expect($published['fast']['database_dump']['dump_command'])->toBe(DatabaseDump::DUMP_COMMAND)
        ->and($published['fast']['database_dump']['restore_command'])->toBe(DatabaseDump::RESTORE_COMMAND);
});

it('waits for the database before it tells whether it is empty', function () {
    ($this->fake)(['*migrate:status*' => Process::sequence([
        Process::result(errorOutput: 'SQLSTATE[HY000] [2002] Connection refused', exitCode: 1),
        Process::result('  0001_create ..... [1] Ran'),
    ])]);
    app()->instance(BootstrapStrategy::class, new SailBootstrapStrategy('laravel.test', 'pnpm', false, fast: new FastSetup(dump: new DatabaseDump(true))));
    File::ensureDirectoryExists($this->path);

    expect(app(BootstrapStrategy::class)->restoreDatabaseDump($this->path))->toBe('existing');
    Process::assertRanTimes(fn (PendingProcess $p) => str_contains((string) $p->command, 'migrate:status'), 2);

    // A database that never answers fails the stage after the wait.
    ($this->fake)(['*migrate:status*' => Process::result(errorOutput: 'Connection refused', exitCode: 1)]);
    app()->instance(BootstrapStrategy::class, new class('laravel.test', 'pnpm', false, fast: new FastSetup(dump: new DatabaseDump(true))) extends SailBootstrapStrategy
    {
        protected int $databaseWaitSeconds = 0;
    });
    expect(fn () => app(BootstrapStrategy::class)->restoreDatabaseDump($this->path))->toThrow(RuntimeException::class, 'Connection refused');
});

it('does not restore into a database with tables but no migrations table', function () use ($strategy) {
    // An adopted database, or a first migrate that failed before its migrations table.
    ($this->fake)();
    $this->dbShow = Process::result('{"platform":{"name":"MariaDB"},"tables":[{"table":"users","schema":"app_t9"}]}');
    $dump = new DatabaseDump(true);
    $strategy(new FastSetup(dump: $dump));
    File::ensureDirectoryExists($this->path.'/database/migrations');
    File::put($this->path.'/.env', "DB_DATABASE=app_t9\n");
    $file = $dump->path($dump->key($this->path));
    File::ensureDirectoryExists(dirname($file));
    File::put($file, gzencode('-- dump'));

    $steps = [];
    expect(app(BootstrapStrategy::class)->restoreDatabaseDump($this->path, onStep: function (string $step) use (&$steps) {
        $steps[] = $step;
    }))->toBe('existing')
        ->and(implode("\n", $steps))->toContain('no migrations table');
    Process::assertNotRan(fn (PendingProcess $p) => str_contains((string) $p->command, 'gunzip'));
});

it('refuses a restore when it cannot list the tables of a database without a migrations table', function () use ($strategy) {
    ($this->fake)();
    $this->dbShow = Process::result(errorOutput: 'Access denied', exitCode: 1);
    $strategy(new FastSetup(dump: new DatabaseDump(true)));
    File::ensureDirectoryExists($this->path);

    expect(fn () => app(BootstrapStrategy::class)->restoreDatabaseDump($this->path))
        ->toThrow(RuntimeException::class, 'Cannot tell whether the database has tables');
    Process::assertNotRan(fn (PendingProcess $p) => str_contains((string) $p->command, 'gunzip'));
});
