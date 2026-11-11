<?php

use Composer\InstalledVersions;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Woda\Worktrees\Commands\WorktreeListCommand;
use Woda\Worktrees\Shared\MainCheckout;

/*
 * worktree:create, worktree:delete and worktree:list name and find worktrees
 * from the main checkout. Run in a linked worktree, they run again there.
 */
beforeEach(function () {
    $this->main = sys_get_temp_dir().'/worktrees-main-'.uniqid().'/app';
    File::ensureDirectoryExists($this->main.'/.git');
    File::put($this->main.'/artisan', '<?php');
    File::put($this->main.'/.env', "APP_URL=https://app.test\n");
    // The main checkout has the same version of the package as the code that runs here.
    $this->installMain = function (?string $version, ?string $reference = null) {
        $packages = $version === null ? [] : [['name' => 'woda/laravel-worktrees', 'version' => $version, 'source' => ['reference' => $reference]]];
        File::ensureDirectoryExists($this->main.'/vendor/composer');
        File::put($this->main.'/vendor/composer/installed.json', json_encode(['packages' => $packages]));
    };
    ($this->installMain)(InstalledVersions::getPrettyVersion('woda/laravel-worktrees'), InstalledVersions::getReference('woda/laravel-worktrees'));
    MainCheckout::forget();
    Process::fake([
        // artisan runs in a linked worktree: git names another folder as the main checkout.
        'git rev-parse --path-format=absolute --git-common-dir' => Process::result($this->main."/.git\n"),
        "*'artisan' 'worktree:*" => Process::result('done', exitCode: 3),
        '*' => Process::result(),
    ]);
});

afterEach(function () {
    File::deleteDirectory(dirname($this->main));
    MainCheckout::forget();
});

it('runs create, delete and list again in the main checkout', function (string $command, array $parameters, string $expected) {
    $this->artisan($command, [...$parameters, '--no-interaction' => true])->assertExitCode(3);

    Process::assertRan(fn (PendingProcess $p) => $p->path === $this->main
        && str_ends_with((string) $p->command, "'artisan' ".$expected)
        && ($p->environment['WORKTREES_RUN_IN_MAIN_CHECKOUT'] ?? null) === '1'
        && ($p->environment['APP_URL'] ?? null) === false);
    Process::assertNotRan(fn (PendingProcess $p) => str_starts_with((string) $p->command, 'git worktree add'));
})->with([
    'create' => ['worktree:create', ['name' => 'fix-x', '--skip-deps' => true], "'worktree:create' 'fix-x' '--skip-deps' '--no-interaction'"],
    'delete' => ['worktree:delete', ['name' => 'fix-x', '--force' => true], "'worktree:delete' 'fix-x' '--force' '--no-interaction'"],
    'list' => ['worktree:list', ['--json' => true], "'worktree:list' '--json' '--no-interaction'"],
]);

it('runs in place when artisan runs in the main checkout', function () {
    Process::fake(['git rev-parse --path-format=absolute --git-common-dir' => Process::result(base_path()."/.git\n"), '*' => Process::result()]);
    MainCheckout::forget();

    $this->artisan('worktree:list', ['--json' => true])->assertSuccessful();
    Process::assertNotRan(fn (PendingProcess $p) => str_contains((string) $p->command, "'artisan' 'worktree:"));
});

it('keeps redirected output when it runs in the main checkout', function () {
    // An interactive call whose stdout goes to a file or a pipe: no terminal for the child.
    app()->bind(WorktreeListCommand::class, fn () => new class extends WorktreeListCommand
    {
        protected function ttySupported(): bool
        {
            return true;
        }

        protected function streamsAreTerminals(): bool
        {
            return false;
        }
    });

    $this->artisan('worktree:list', ['--json' => true])->assertExitCode(3);
    Process::assertRan(fn (PendingProcess $p) => str_contains((string) $p->command, "'worktree:list'") && $p->tty === false);
});

it('passes --env on and clears the keys of the selected environment file', function () {
    File::put($this->main.'/.env.staging', "STAGING_DB=main_staging\n");
    File::put(base_path('.env.staging'), "WORKTREE_STAGING_DB=wt_staging\n");
    try {
        $this->artisan('worktree:list', ['--json' => true, '--env' => 'staging', '--no-interaction' => true])->assertExitCode(3);
    } finally {
        File::delete(base_path('.env.staging'));
    }

    Process::assertRan(fn (PendingProcess $p) => $p->path === $this->main
        && str_contains((string) $p->command, "'worktree:list' '--json' '--env=staging'")
        && ($p->environment['STAGING_DB'] ?? null) === false
        && ($p->environment['WORKTREE_STAGING_DB'] ?? null) === false);
});

it('refuses to hand over to a main checkout with another version of the package', function () {
    ($this->installMain)('v0.1.0', 'abc1234');

    $this->artisan('worktree:delete', ['name' => 'fix-x', '--force' => true, '--no-interaction' => true])
        // One message: it names both versions and the update of the main checkout.
        ->expectsOutputToContain('The main checkout has woda/laravel-worktrees v0.1.0, this checkout has')
        ->assertFailed();
    Process::assertNotRan(fn (PendingProcess $p) => str_contains((string) $p->command, "'artisan' 'worktree:"));
});

it('refuses to hand over when the main checkout does not have the package', function () {
    ($this->installMain)(null);

    $this->artisan('worktree:list', ['--json' => true, '--no-interaction' => true])
        ->expectsOutputToContain('Cannot read the version of woda/laravel-worktrees in the main checkout')
        ->assertFailed();
    Process::assertNotRan(fn (PendingProcess $p) => str_contains((string) $p->command, "'artisan' 'worktree:"));
});

it('passes an APP_ENV from outside on as --env', function () {
    // The environment variable selects .env.staging; the hand-over clears APP_ENV, so it must name it.
    File::put(base_path('.env'), "APP_ENV=local\n");
    putenv('APP_ENV=staging');
    try {
        $this->artisan('worktree:list', ['--json' => true, '--no-interaction' => true])->assertExitCode(3);
    } finally {
        putenv('APP_ENV');
        File::delete(base_path('.env'));
    }

    Process::assertRan(fn (PendingProcess $p) => str_contains((string) $p->command, "'worktree:list' '--json' '--env=staging'"));
});

it('does not pass on the APP_ENV of the worktree\'s own .env', function () {
    File::put(base_path('.env'), "APP_ENV=local\n");
    putenv('APP_ENV=local');
    try {
        $this->artisan('worktree:list', ['--json' => true, '--no-interaction' => true])->assertExitCode(3);
    } finally {
        putenv('APP_ENV');
        File::delete(base_path('.env'));
    }

    Process::assertRan(fn (PendingProcess $p) => str_ends_with((string) $p->command, "'worktree:list' '--json' '--no-interaction'"));
});
