<?php

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Woda\Worktrees\Idle\IdleStacks;

/*
 * worktree:idle-stop and worktree:wake. Docker and git are faked.
 */
beforeEach(function () {
    $this->base = sys_get_temp_dir().'/worktrees-idle-'.uniqid();
    config()->set('worktrees.base_path', $this->base);
    config()->set('worktrees.bootstrap.strategy', 'sail');
    config()->set('worktrees.database.strategy', 'none');
    $this->paths = [];
    $this->gitStatus = '';
    foreach (['busy', 'idle', 'off', 'twin'] as $name) {
        $this->paths[$name] = $this->base.'/'.basename(base_path()).'-'.$name;
        File::ensureDirectoryExists($this->paths[$name].'/storage');
    }
    // Traffic of the last check, 40 minutes ago.
    foreach (['busy', 'idle'] as $name) {
        File::ensureDirectoryExists($this->paths[$name].'/storage/app/worktree');
        File::put($this->paths[$name].'/storage/app/worktree/idle.json', json_encode(['traffic' => 1000, 'changed_at' => time() - 2400]));
    }
    $this->projects = ['busy' => 'app-busy', 'idle' => 'app-idle', 'off' => 'app-off', 'twin' => 'app'];
    $this->nameOf = fn (?string $path) => array_search($path, $this->paths, true) ?: 'main';
    Process::fake([
        'git worktree list --porcelain' => fn () => Process::result('worktree '.base_path()."\nHEAD 0\nbranch refs/heads/master\n\n"
            .implode('', array_map(fn (string $path) => "worktree {$path}\nHEAD a\nbranch refs/heads/".basename($path)."\n\n", $this->paths))),
        'docker compose config --format json' => fn (PendingProcess $p) => Process::result(json_encode(['name' => $this->projects[($this->nameOf)($p->path)] ?? 'app'])),
        'docker compose ps --format json' => function (PendingProcess $p) {
            $name = ($this->nameOf)($p->path);

            return Process::result($name === 'off' ? '' : json_encode(['Service' => 'laravel.test', 'Name' => "app-{$name}-app-1", 'State' => 'running']));
        },
        'docker stats *' => Process::result("app-busy-app-1|1.5kB / 2kB\napp-idle-app-1|600B / 400B\napp-twin-app-1|1kB / 1kB\n"),
        'git status *' => fn () => Process::result($this->gitStatus),
        '*' => Process::result(),
    ]);
});

afterEach(function () {
    File::deleteDirectory($this->base);
});

it('stops only the stacks without traffic, code changes or log writes for the given minutes', function () {
    $this->artisan('worktree:idle-stop', ['--minutes' => 30])
        ->expectsTable(['Worktree', 'Idle (min)', 'Action'], [
            ['busy', 0, 'in use'],
            ['idle', 40, 'stopped'],
            ['off', '-', 'stopped already'],
            ['twin', '-', "skipped: the main checkout's compose project"],
        ])
        ->assertSuccessful();

    Process::assertRan(fn (PendingProcess $p) => $p->command === "docker compose --profile '*' stop" && $p->path === $this->paths['idle']);
    Process::assertNotRan(fn (PendingProcess $p) => str_ends_with((string) $p->command, ' stop') && $p->path !== $this->paths['idle']);
    expect(json_decode(File::get($this->paths['busy'].'/storage/app/worktree/idle.json'), true)['traffic'])->toBe(3500);
});

it('counts a log write as use, and stops nothing in a dry run', function () {
    File::ensureDirectoryExists($this->paths['idle'].'/storage/logs');
    File::put($this->paths['idle'].'/storage/logs/laravel.log', 'request');

    $this->artisan('worktree:idle-stop', ['--minutes' => 30, '--dry-run' => true])->assertSuccessful();
    Process::assertNotRan(fn (PendingProcess $p) => str_ends_with((string) $p->command, ' stop'));
    $this->artisan('worktree:idle-stop', ['--minutes' => 1, '--json' => true])->assertSuccessful();
    Process::assertNotRan(fn (PendingProcess $p) => $p->path === $this->paths['idle'] && str_ends_with((string) $p->command, ' stop'));
});

it('does not stop a stack on its first check', function () {
    File::delete($this->paths['idle'].'/storage/app/worktree/idle.json');

    $this->artisan('worktree:idle-stop', ['--minutes' => 30])->assertSuccessful();
    Process::assertNotRan(fn (PendingProcess $p) => $p->path === $this->paths['idle'] && str_ends_with((string) $p->command, ' stop'));
});

it('wakes a stack and counts that as use', function () {
    $this->artisan('worktree:wake', ['worktree' => 'idle'])->assertSuccessful();

    Process::assertRan(fn (PendingProcess $p) => $p->command === 'docker compose up -d --wait' && $p->path === $this->paths['idle']);
    expect(File::exists($this->paths['idle'].'/storage/app/worktree/woken'))->toBeTrue()
        ->and(app(IdleStacks::class)->lastActivity($this->paths['idle'], 1000, time()))->toBeGreaterThan(time() - 5);
});

it('reads docker sizes', function (string $value, ?int $bytes) {
    expect(IdleStacks::bytes($value))->toBe($bytes);
})->with([['0B', 0], ['1.5kB', 1500], ['2MiB', 2097152], ['nonsense', null]]);

it('skips a running stack without a traffic reading', function () {
    Process::fake(['docker stats *' => Process::result(errorOutput: 'daemon busy', exitCode: 1)]);

    $this->artisan('worktree:idle-stop', ['--minutes' => 30])->assertSuccessful();
    Process::assertNotRan(fn (PendingProcess $p) => str_ends_with((string) $p->command, ' stop'));
});

it('reads each file inside untracked folders', function () {
    // An edit inside an untracked folder does not change the folder's time: git must list the files.
    app(IdleStacks::class)->lastActivity($this->paths['idle'], 1000, time());

    Process::assertRan(fn (PendingProcess $p) => $p->command === 'git status --porcelain -z --untracked-files=all' && $p->path === $this->paths['idle']);
});

it('reads the edit time of files with quoted names and of renamed files', function (string $file, string $status) {
    $path = $this->paths['idle'];
    File::put($path.'/'.$file, '<?php');
    $this->gitStatus = $status;

    // The traffic and the state are 40 minutes old; only the edited file is new.
    expect(app(IdleStacks::class)->lastActivity($path, 1000, time()))->toBeGreaterThan(time() - 5);
})->with([
    'non-ASCII name' => ['übersicht.php', " M übersicht.php\0"],
    'name with a space' => ['my file.php', "?? my file.php\0"],
    'staged rename' => ['new.php', "RM new.php\0old.php\0 M other.php\0"],
]);
