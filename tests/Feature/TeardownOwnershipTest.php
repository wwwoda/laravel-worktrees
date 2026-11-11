<?php

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Woda\Worktrees\Contracts\BootstrapStrategy;
use Woda\Worktrees\SailBootstrapStrategy;
use Woda\Worktrees\WorktreeManager;

/*
 * A worktree that carries another worktree's compose project must neither
 * start nor stop it: `docker compose down -v` would remove that worktree's
 * volumes. Every registered worktree and the main checkout are protected,
 * by their compose config and by the owner labels of the containers.
 */
beforeEach(function () {
    $this->base = sys_get_temp_dir().'/worktrees-owner-'.uniqid();
    File::ensureDirectoryExists($this->base);
    config()->set('worktrees.base_path', $this->base);
    config()->set('worktrees.bootstrap.strategy', 'sail');
    $this->a = $this->base.'/'.basename(base_path()).'-a';
    $this->b = $this->base.'/'.basename(base_path()).'-b';
    File::ensureDirectoryExists($this->a);
    File::ensureDirectoryExists($this->b);
    // A's .env was copied from B: it carries B's compose project.
    $this->projects = [base_path() => 'app', $this->a => 'app-b', $this->b => 'app-b'];
    $this->owners = '';
    $this->fake = function (array $extra = []) {
        Process::fake($extra + [
            'git worktree list --porcelain' => fn () => Process::result('worktree '.base_path()."\nHEAD 000\nbranch refs/heads/master\n\n"
                ."worktree {$this->a}\nHEAD aaa\nbranch refs/heads/a\n\nworktree {$this->b}\nHEAD bbb\nbranch refs/heads/b\n\n"),
            'docker compose config --format json' => fn (PendingProcess $p) => isset($this->projects[$p->path])
                ? Process::result(json_encode(['name' => $this->projects[$p->path], 'services' => []]))
                : Process::result(errorOutput: 'no configuration file provided', exitCode: 1),
            'docker ps -a --filter *' => fn () => Process::result($this->owners),
            "docker compose exec -T 'laravel.test' sh -c *echo*" => Process::result('ready'),
            '*' => Process::result(),
        ]);
    };
    app()->forgetInstance(BootstrapStrategy::class);
    app()->forgetInstance(WorktreeManager::class);
});

afterEach(function () {
    File::deleteDirectory($this->base);
});

it('does not tear down a worktree that carries another worktree\'s compose project', function () {
    ($this->fake)();

    expect(fn () => app(WorktreeManager::class)->tearDown('a'))
        ->toThrow(RuntimeException::class, "same compose project as {$this->b}");
    Process::assertNotRan(fn (PendingProcess $p) => str_contains((string) $p->command, ' down'));
});

it('passes a registered worktree whose compose config cannot be read and whose project is another', function () {
    // B has a compose file but no .env, so its config fails: an old, unrelated worktree.
    $this->projects = [base_path() => 'app', $this->a => 'app-a'];
    File::put($this->b.'/compose.yaml', "name: \${COMPOSE_PROJECT_NAME:-app}\nservices: {}\n");
    ($this->fake)();

    app(WorktreeManager::class)->tearDown('a');

    Process::assertRan('docker compose --profile "*" down -v');
});

it('refuses when the .env of an unreadable worktree names the compose project', function () {
    $this->projects = [base_path() => 'app', $this->a => 'app-a'];
    File::put($this->b.'/compose.yaml', "services: {}\n");
    File::put($this->b.'/.env', "COMPOSE_PROJECT_NAME=app-a\n");
    ($this->fake)();

    expect(fn () => app(WorktreeManager::class)->tearDown('a'))
        ->toThrow(RuntimeException::class, "{$this->b} could have the same compose project ('app-a')");
    Process::assertNotRan(fn (PendingProcess $p) => str_contains((string) $p->command, ' down'));
});

it('refuses when the compose file of an unreadable worktree names the compose project', function (string $name) {
    $this->projects = [base_path() => 'app', $this->a => 'app-a'];
    File::ensureDirectoryExists($this->b.'/docker');
    File::put($this->b.'/.env', "COMPOSE_FILE=docker/compose.yml\n");
    File::put($this->b.'/docker/compose.yml', "name: {$name}\nservices: {}\n");
    ($this->fake)();

    expect(fn () => (new SailBootstrapStrategy('laravel.test', 'pnpm', false))->bringUp($this->a))
        ->toThrow(RuntimeException::class, "{$this->b} could have the same compose project ('app-a')");
    Process::assertNotRan(fn (PendingProcess $p) => str_contains((string) $p->command, ' up '));
})->with([
    'literal' => 'app-a',
    'quoted' => '"app-a"',
    'default of a variable' => '${COMPOSE_PROJECT_NAME:-app-a}',
    'default of an unset variable' => "'\${PROJECT-app-a}'",
]);

it('refuses when the folder name of an unreadable worktree gives the compose project', function () {
    // Compose names a project after its folder: lower case, only a-z, 0-9, _ and -.
    $this->b = $this->base.'/.My.App-A';
    File::ensureDirectoryExists($this->b);
    File::put($this->b.'/docker-compose.yml', "services: {}\n");
    $this->projects = [base_path() => 'app', $this->a => 'myapp-a'];
    ($this->fake)();

    expect(fn () => app(WorktreeManager::class)->tearDown('a'))
        ->toThrow(RuntimeException::class, "{$this->b} could have the same compose project ('myapp-a')");
    Process::assertNotRan(fn (PendingProcess $p) => str_contains((string) $p->command, ' down'));
});

it('refuses when a container of the compose project belongs to an unreadable worktree', function () {
    $this->projects = [base_path() => 'app', $this->a => 'app-a'];
    File::put($this->b.'/compose.yaml', "services: {}\n");
    $this->owners = "c1 {$this->a}\nc2 {$this->b}\n";
    ($this->fake)();

    expect(fn () => app(WorktreeManager::class)->tearDown('a'))
        ->toThrow(RuntimeException::class, "Containers of the compose project 'app-a' belong to {$this->b}");
    Process::assertNotRan(fn (PendingProcess $p) => str_contains((string) $p->command, ' down'));
});

it('passes a registered worktree without a compose file', function () {
    $this->projects = [base_path() => 'app', $this->a => 'app-a'];
    ($this->fake)();

    app(WorktreeManager::class)->tearDown('a');

    Process::assertRan('docker compose --profile "*" down -v');
});

it('refuses when containers of the compose project belong to another folder', function () {
    // An unregistered checkout (a copy, another clone) whose containers carry the project.
    $this->projects = [base_path() => 'app', $this->a => 'app-a', $this->b => 'app-b'];
    $this->owners = "c1 {$this->base}/elsewhere\n";
    ($this->fake)();

    expect(fn () => app(WorktreeManager::class)->tearDown('a'))
        ->toThrow(RuntimeException::class, "{$this->base}/elsewhere");
    Process::assertNotRan(fn (PendingProcess $p) => str_contains((string) $p->command, ' down'));
    Process::assertRan(fn (PendingProcess $p) => str_contains((string) $p->command, 'label=com.docker.compose.project=app-a'));
});

it('accepts containers whose compose file is in a subfolder of the worktree', function () {
    $this->projects = [base_path() => 'app', $this->a => 'app-a', $this->b => 'app-b'];
    $this->owners = "c1 {$this->a}/docker\nc2 {$this->a}\n";
    ($this->fake)();

    app(WorktreeManager::class)->tearDown('a');

    Process::assertRan('docker compose --profile "*" down -v');
});

it('refuses when the containers cannot be listed', function () {
    $this->projects = [base_path() => 'app', $this->a => 'app-a', $this->b => 'app-b'];
    ($this->fake)(['docker ps -a --filter *' => Process::result(errorOutput: 'Cannot connect to the Docker daemon', exitCode: 1)]);

    expect(fn () => (new SailBootstrapStrategy('laravel.test', 'pnpm', false))->tearDown($this->a))
        ->toThrow(RuntimeException::class, 'Cannot list the containers');
    Process::assertNotRan(fn (PendingProcess $p) => str_contains((string) $p->command, ' down'));
});

it('does not start a worktree that carries another worktree\'s compose project', function () {
    ($this->fake)();

    expect(fn () => (new SailBootstrapStrategy('laravel.test', 'pnpm', false))->bringUp($this->a))
        ->toThrow(RuntimeException::class, 'same compose project');
    Process::assertNotRan('docker compose up -d');
});

it('refuses to start or stop a worktree whose own compose project cannot be read', function (string $action) {
    unset($this->projects[$this->a]);
    File::put($this->a.'/compose.yaml', "services: {}\n");
    ($this->fake)();

    expect(fn () => (new SailBootstrapStrategy('laravel.test', 'pnpm', false))->{$action}($this->a))
        ->toThrow(RuntimeException::class, "Cannot read the compose project of {$this->a}");
    Process::assertNotRan(fn (PendingProcess $p) => str_contains((string) $p->command, ' down'));
    Process::assertNotRan(fn (PendingProcess $p) => str_contains((string) $p->command, ' up '));
})->with(['tearDown', 'tearDownKeepingVolumes', 'bringUp']);

it('refuses when a container of the compose project has no owner label', function () {
    $this->projects = [base_path() => 'app', $this->a => 'app-a', $this->b => 'app-b'];
    // One container of A, one without the working_dir label: docker prints an empty label.
    $this->owners = "c1 {$this->a}\nc2 \n";
    ($this->fake)();

    expect(fn () => (new SailBootstrapStrategy('laravel.test', 'pnpm', false))->tearDown($this->a))
        ->toThrow(RuntimeException::class, "Container c2 of the compose project 'app-a' has no owner label");
    Process::assertNotRan(fn (PendingProcess $p) => str_contains((string) $p->command, ' down'));
});

it('passes a compose project without containers', function () {
    $this->projects = [base_path() => 'app', $this->a => 'app-a', $this->b => 'app-b'];
    $this->owners = "\n";
    ($this->fake)();

    (new SailBootstrapStrategy('laravel.test', 'pnpm', false))->tearDown($this->a);

    Process::assertRan('docker compose --profile "*" down -v');
});
