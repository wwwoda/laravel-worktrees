<?php

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
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
