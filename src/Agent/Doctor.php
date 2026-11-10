<?php

namespace Woda\Worktrees\Agent;

use Closure;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Woda\Worktrees\ComposeProcess;
use Woda\Worktrees\Contracts\BootstrapStrategy;
use Woda\Worktrees\SharedServicesBootstrapStrategy;

/**
 * The checks of `worktree:doctor`, in the order in which their fixes must
 * run: the containers before Composer, Composer before the migrations.
 *
 * A check returns `ok`, `warn` (works, but not as it should), `fail` (does
 * not work) or `skip` (cannot tell, for example without a running container).
 */
final readonly class Doctor
{
    // `hot` before `build`: a stale hot file hides a missing build.
    public const CHECKS = ['setup', 'env', 'compose_project', 'app_url', 'containers', 'vendor', 'node_modules', 'hot', 'build', 'caches', 'database', 'workers'];

    /**
     * @param  (Closure(string): bool)|null  $devServerAnswers
     */
    public function __construct(private WorktreeInspector $inspector, private BootstrapStrategy $strategy, private bool $buildFrontend, private ?Closure $devServerAnswers = null, private string $nodePackageManager = 'pnpm') {}

    /**
     * @return array{state: string, message: string, fixable: bool}
     */
    public function check(string $id, WorktreeTarget $target): array
    {
        $path = $target->path;

        return match ($id) {
            'setup' => $this->checkSetup($target),
            'env' => is_file($path.'/.env')
                ? self::ok('.env exists')
                : self::result('fail', 'No .env: run php artisan worktree:setup '.$path, false),
            'compose_project' => ($problem = $this->inspector->ownStackProblem($target)) === null
                ? self::ok($this->inspector->runner()->usesCompose() ? 'own compose project' : 'native strategy')
                : self::result('fail', $problem, false),
            'app_url' => $this->checkUrl($target),
            'containers' => $this->checkContainers($target),
            'vendor' => is_file($path.'/vendor/autoload.php')
                ? self::ok('vendor is installed')
                : self::result('fail', 'vendor/autoload.php is missing', true),
            'node_modules' => ! is_file($path.'/package.json') || is_dir($path.'/node_modules')
                ? self::ok(is_file($path.'/package.json') ? 'node_modules is installed' : 'no package.json')
                : self::result('fail', 'node_modules is missing', true),
            'build' => $this->checkBuild($path),
            'hot' => $this->checkHot($path),
            'caches' => $this->checkCaches($path),
            'database' => $this->checkDatabase($target),
            'workers' => $this->checkWorkers($target),
            default => throw new RuntimeException("Unknown check '{$id}'."),
        };
    }

    /**
     * @param  (Closure(string, string): void)|null  $output
     */
    public function fix(string $id, WorktreeTarget $target, ?Closure $output = null): void
    {
        $path = $target->path;
        // A worktree with the main checkout's compose project would fix the main checkout's containers.
        $this->inspector->assertOwnStack($target);
        // The native strategy's own steps run with the environment of the checkout that
        // runs artisan; the runner clears it, so the fix reaches the target's database.
        $native = ! $this->inspector->runner()->usesCompose();
        $pm = $this->nodePackageManager;
        match ($id) {
            'containers' => $this->startContainers($target, $output),
            'vendor' => $native ? $this->artisan($path, 'composer install --no-interaction', $output) : $this->strategy->installComposerDependencies($path, $output),
            'node_modules' => $native ? $this->artisan($path, "{$pm} install", $output) : $this->installNode($target, $output),
            'build' => $native ? $this->artisan($path, "{$pm} run build", $output) : $this->strategy->buildFrontend($path, $output),
            'hot' => File::delete($path.'/public/hot'),
            'caches' => $this->artisan($path, 'php artisan config:clear && php artisan route:clear && php artisan event:clear', $output),
            'database' => $native ? $this->artisan($path, 'php artisan migrate --force', $output) : $this->strategy->runMigrations($path, $output),
            'workers' => $this->restartWorkers($target, $output),
            default => throw new RuntimeException("The check '{$id}' has no fix."),
        };
    }

    /**
     * @return array{state: string, message: string, fixable: bool}
     */
    private function checkSetup(WorktreeTarget $target): array
    {
        if ($target->main) {
            return self::result('skip', 'main checkout', false);
        }

        return match ($target->setupStatus()) {
            'ready' => self::ok('setup complete'),
            null => self::result('warn', 'no bootstrap record: set up by hand or by an older version', false),
            'running' => self::result('warn', 'a setup runs, or one stopped without a record; see storage/logs/worktree-setup.log', false),
            default => self::result('fail', 'the setup failed; continue it: php artisan worktree:setup '.$target->path, false),
        };
    }

    /**
     * @return array{state: string, message: string, fixable: bool}
     */
    private function checkUrl(WorktreeTarget $target): array
    {
        $url = $this->inspector->url($target->path);
        if ($url === null) {
            return self::result('warn', 'APP_URL is not set', false);
        }
        if (! $target->main && $url === $this->inspector->mainUrl()) {
            return self::result('fail', "APP_URL is the main checkout's URL ({$url})", false);
        }

        return self::ok($url);
    }

    /**
     * @return array{state: string, message: string, fixable: bool}
     */
    private function checkContainers(WorktreeTarget $target): array
    {
        $stack = $this->inspector->stack($target->path);
        if ($stack === null) {
            return self::result('skip', 'native strategy: no containers', false);
        }
        $expected = $stack->servicesFor($stack->activeProfiles());
        $running = [];
        foreach ($stack->containers() as $container) {
            if ($container['state'] === 'running') {
                $running[] = $container['service'];
            }
        }
        $missing = array_values(array_diff($expected, $running));
        if (in_array($stack->appService, $missing, true)) {
            return self::result('fail', 'the app container does not run', true);
        }

        return $missing === []
            ? self::ok(count($running).' containers run')
            : self::result('warn', 'not running: '.implode(', ', $missing), true);
    }

    /**
     * @return array{state: string, message: string, fixable: bool}
     */
    private function checkBuild(string $path): array
    {
        if (! $this->buildFrontend || ! is_file($path.'/package.json')) {
            return self::result('skip', 'no frontend build', false);
        }
        if (is_file($path.'/public/build/manifest.json') || is_file($path.'/public/hot')) {
            return self::ok(is_file($path.'/public/hot') ? 'dev server (public/hot)' : 'public/build/manifest.json exists');
        }

        return self::result('fail', 'no public/build/manifest.json: pages fail with a Vite manifest error', true);
    }

    /**
     * @return array{state: string, message: string, fixable: bool}
     */
    private function checkHot(string $path): array
    {
        $hot = $path.'/public/hot';
        if (! is_file($hot)) {
            return self::ok('no public/hot');
        }
        $url = trim((string) File::get($hot));
        $answers = $this->devServerAnswers ?? SharedServicesBootstrapStrategy::devServerAnswers(...);

        return $answers($url)
            ? self::ok("dev server answers at {$url}")
            : self::result('fail', "public/hot points at {$url}, where no dev server answers: pages load no assets", true);
    }

    /**
     * @return array{state: string, message: string, fixable: bool}
     */
    private function checkCaches(string $path): array
    {
        $cached = array_map(basename(...), array_values(array_filter([
            $path.'/bootstrap/cache/config.php',
            $path.'/bootstrap/cache/events.php',
            ...(glob($path.'/bootstrap/cache/routes-*.php') ?: []),
        ], is_file(...))));

        return $cached === []
            ? self::ok('no config, route or event cache')
            : self::result('warn', 'cached: '.implode(', ', $cached).' (changes to .env, config or routes have no effect)', true);
    }

    /**
     * @return array{state: string, message: string, fixable: bool}
     */
    private function checkDatabase(WorktreeTarget $target): array
    {
        $stack = $this->inspector->stack($target->path);
        if ($stack !== null && ! $stack->isRunning($stack->appService)) {
            return self::result('skip', 'the app container does not run', false);
        }
        if (! is_file($target->path.'/vendor/autoload.php')) {
            return self::result('skip', 'vendor is missing', false);
        }
        $state = $this->inspector->migrations($target->path);

        return match ($state['state']) {
            'ok' => self::ok($state['message']),
            'pending' => self::result('warn', $state['message'], true),
            'empty' => self::result('fail', $state['message'], true),
            default => self::result('skip', 'cannot tell: '.$state['message'], false),
        };
    }

    /**
     * Long-running PHP processes (Horizon, queue:work, schedule:work) keep the
     * code they started with. They run in services with the app's image.
     *
     * @return array{state: string, message: string, fixable: bool}
     */
    private function checkWorkers(WorktreeTarget $target): array
    {
        $stale = $this->staleWorkers($target);
        if ($stale === null) {
            return self::result('skip', 'no worker containers run', false);
        }

        return $stale === []
            ? self::ok('workers run the current code')
            : self::result('warn', 'started before the last code change: '.implode(', ', array_keys($stale)), true);
    }

    /**
     * Running worker containers that started before the last code change, by
     * service; null when no worker runs.
     *
     * @return array<string, string>|null
     */
    private function staleWorkers(WorktreeTarget $target): ?array
    {
        $stack = $this->inspector->stack($target->path);
        if ($stack === null) {
            return null;
        }
        $running = [];
        $app = null;
        foreach ($stack->containers() as $container) {
            if ($container['state'] !== 'running') {
                continue;
            }
            if ($container['service'] === $stack->appService) {
                $app = $container['name'];
            } else {
                $running[$container['service']] = $container['name'];
            }
        }
        if ($running === []) {
            return null;
        }
        // The image each container runs (also for services that only `build:`),
        // compared with the app container's; without a running app container,
        // with the image names of the compose file.
        $details = $stack->inspect([...array_values($running), ...($app === null ? [] : [$app])]);
        $appImage = $app === null ? null : ($details[$app]['image'] ?? null);
        $configImage = $stack->image($stack->appService);
        $workers = array_filter($running, static fn (string $name, string $service): bool => $appImage !== null
            ? ($details[$name]['image'] ?? null) === $appImage
            : $configImage !== null && $stack->image($service) === $configImage, ARRAY_FILTER_USE_BOTH);
        if ($workers === []) {
            return null;
        }
        $changed = $this->inspector->lastCodeChange($target->path);

        return array_filter($workers, static fn (string $name): bool => isset($details[$name]['started']) && $details[$name]['started'] < $changed);
    }

    /**
     * @param  (Closure(string, string): void)|null  $output
     */
    private function startContainers(WorktreeTarget $target, ?Closure $output): void
    {
        if ($target->main) {
            // The setup's bringUp guards worktrees; the main checkout only starts.
            $result = ComposeProcess::in($target->path, 600)->run('docker compose up -d', $output);
            if (! $result->successful()) {
                throw new RuntimeException('docker compose up -d failed: '.trim($result->errorOutput()));
            }

            return;
        }
        $this->strategy->bringUp($target->path, $output);
    }

    /**
     * @param  (Closure(string, string): void)|null  $output
     */
    private function installNode(WorktreeTarget $target, ?Closure $output): void
    {
        $this->strategy->installNodeDependencies($target->path, $output);
        // On Linux a host install can detach the app container's node_modules
        // volume; bringUp restarts the container when that happened.
        if ($this->inspector->runner()->usesCompose() && ! $target->main) {
            $this->strategy->bringUp($target->path, $output);
        }
    }

    /**
     * @param  (Closure(string, string): void)|null  $output
     */
    private function restartWorkers(WorktreeTarget $target, ?Closure $output): void
    {
        $stale = $this->staleWorkers($target) ?? [];
        if ($stale === []) {
            return;
        }
        $result = ComposeProcess::in($target->path, 300)->run(
            'docker compose restart '.implode(' ', array_map(escapeshellarg(...), array_keys($stale))),
            $output,
        );
        if (! $result->successful()) {
            throw new RuntimeException('Restarting the workers failed: '.trim($result->errorOutput()));
        }
    }

    /**
     * @param  (Closure(string, string): void)|null  $output
     */
    private function artisan(string $path, string $command, ?Closure $output): void
    {
        $result = $this->inspector->runner()->run($path, $command, 120, $output);
        if (! $result->successful()) {
            throw new RuntimeException("{$command} failed: ".trim($result->errorOutput() ?: $result->output()));
        }
    }

    /**
     * @return array{state: string, message: string, fixable: bool}
     */
    private static function ok(string $message): array
    {
        return self::result('ok', $message, false);
    }

    /**
     * @return array{state: string, message: string, fixable: bool}
     */
    private static function result(string $state, string $message, bool $fixable): array
    {
        return ['state' => $state, 'message' => $message, 'fixable' => $fixable];
    }
}
