<?php

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Woda\Worktrees\Contracts\BootstrapStrategy;
use Woda\Worktrees\SailBootstrapStrategy;

/*
 * worktree:create with the config shape HungryPort/app uses today: the Sail
 * strategy (replaced by a subclass in the app's provider), database strategy
 * 'none', and env_overrides with {name} placeholders.
 */
beforeEach(function () {
    $this->base = sys_get_temp_dir().'/worktrees-sail-'.uniqid();
    File::ensureDirectoryExists($this->base);
    // The test writes the application's .env; a .env that was there before is put back afterwards.
    $this->envBefore = File::exists(base_path('.env')) ? File::get(base_path('.env')) : null;
    File::put(base_path('.env'), "APP_NAME=App\nAPP_URL=https://app.hp.test\nCOMPOSE_PROJECT_NAME=hungryport\nWORKTREE_HOST=app.hp.test\n");
    config()->set('app.name', 'App');
    config()->set('app.url', 'https://app.hp.test');
    config()->set('worktrees.base_path', $this->base);
    config()->set('worktrees.copy_files', ['.env']);
    config()->set('worktrees.database.strategy', 'none');
    config()->set('worktrees.bootstrap.strategy', 'sail');
    config()->set('worktrees.env_overrides', [
        'COMPOSE_PROJECT_NAME' => 'hungryport-{name}',
        'WORKTREE_HOST' => 'app-{name}.hp.test',
    ]);
    // HungryPort binds a subclass of the Sail strategy in its own provider.
    app()->singleton(BootstrapStrategy::class, fn () => new class('laravel.test', 'pnpm', true) extends SailBootstrapStrategy {});
});

afterEach(function () {
    File::deleteDirectory($this->base);
    $this->envBefore === null ? File::delete(base_path('.env')) : File::put(base_path('.env'), $this->envBefore);
});

test('worktree:create with the Sail strategy sets up a worktree like HungryPort expects', function () {
    $path = $this->base.'/'.basename(base_path()).'-lane';
    Process::fake([
        'git show-ref *' => Process::result(exitCode: 1),
        'git worktree add *' => function () use ($path) {
            File::ensureDirectoryExists($path);

            return Process::result();
        },
        'git worktree list --porcelain' => fn () => Process::result("worktree {$path}\nHEAD abc\nbranch refs/heads/lane\n\n"),
        "docker compose exec -T 'laravel.test' sh -c *echo*" => Process::result('ready'),
        '*' => Process::result(),
    ]);

    $this->artisan('worktree:create', ['name' => 'lane'])->assertSuccessful();

    expect(File::get($path.'/.env'))
        ->toContain('COMPOSE_PROJECT_NAME=hungryport-lane')
        ->toContain('WORKTREE_HOST=app-lane.hp.test')
        ->toContain('APP_URL=https://app-lane.hp.test');
    Process::assertRan(fn (PendingProcess $p) => $p->command === 'docker compose up -d' && $p->path === $path
        && $p->environment['COMPOSE_PROJECT_NAME'] === false);
    Process::assertRan(fn (PendingProcess $p) => str_contains((string) $p->command, 'composer install'));
    Process::assertRan(fn (PendingProcess $p) => $p->command === 'pnpm run build' && $p->path === $path);
    Process::assertRan(fn (PendingProcess $p) => str_contains((string) $p->command, 'php artisan migrate --force'));
});
