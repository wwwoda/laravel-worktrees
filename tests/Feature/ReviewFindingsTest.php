<?php

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Woda\Worktrees\WorktreeManager;

beforeEach(function () {
    $this->base = sys_get_temp_dir().'/worktrees-review-'.uniqid();
    File::ensureDirectoryExists($this->base);
    config()->set('worktrees.base_path', $this->base);
});

afterEach(function () {
    File::deleteDirectory($this->base);
});

test('delete of an unregistered directory fails with a message and keeps the directory', function () {
    $path = $this->base.'/'.basename(base_path()).'-orphan';
    File::ensureDirectoryExists($path);
    Process::fake(['git worktree list --porcelain' => Process::result()]);

    $this->artisan('worktree:delete', ['name' => 'orphan', '--force' => true])
        ->expectsOutputToContain('not a registered worktree')
        ->assertFailed();

    expect(is_dir($path))->toBeTrue();
});

test('bootstrap resume of an unregistered directory fails with a message', function () {
    Process::fake(['git worktree list --porcelain' => Process::result()]);

    $this->artisan('worktree:bootstrap', ['name' => 'orphan', '--resume' => true])
        ->expectsOutputToContain('not a registered worktree')
        ->assertFailed();
});

test('cleanup asks gh from the project directory', function () {
    $path = $this->base.'/'.basename(base_path()).'-ticket';
    File::ensureDirectoryExists($path);
    Process::fake([
        'git worktree list --porcelain' => Process::result("worktree {$path}\nHEAD abc\nbranch refs/heads/ticket\n\n"),
        'git rev-parse --abbrev-ref HEAD' => Process::result('ticket'),
        'gh pr list *' => Process::result('[]'),
        '*' => Process::result(),
    ]);

    $this->artisan('worktree:cleanup', ['--dry-run' => true])->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process) => str_starts_with((string) $process->command, 'gh pr list')
        && $process->path === base_path());
});

test('env_overrides values are written literally, also with dollar signs and backslashes', function () {
    $path = $this->base.'/wt';
    File::ensureDirectoryExists($path);
    File::put($path.'/.env', "APP_NAME=Test\nSECRET=old\n");
    config()->set('app.name', 'Te$1st');
    config()->set('app.url', '');
    config()->set('worktrees.env_overrides', ['SECRET' => 'pa$1ss\\0word', 'APP_URL' => '"https://${WORKTREE_HOST}"']);

    $manager = app(WorktreeManager::class);
    (new ReflectionMethod($manager, 'applyEnvReplacements'))->invoke($manager, $path, 'lane');

    expect(File::get($path.'/.env'))
        ->toContain('SECRET=pa$1ss\\0word')
        ->toContain('APP_NAME="Te$1st (lane)"')
        ->toContain('APP_URL="https://${WORKTREE_HOST}"');
});

test('the package\'s own bootstrap files do not make a worktree dirty', function () {
    $path = $this->base.'/'.basename(base_path()).'-lane';
    File::ensureDirectoryExists($path.'/storage');
    $git = fn (array $arguments) => Process::path($path)->run(['git', ...$arguments])->throw();
    $git(['init', '-b', 'lane']);
    $git(['config', 'user.email', 'test@example.invalid']);
    $git(['config', 'user.name', 'Test']);
    $git(['config', 'commit.gpgsign', 'false']);
    $git(['commit', '--allow-empty', '-m', 'Base']);
    File::put($path.'/storage/worktree-bootstrap.json', '{}');
    File::put($path.'/storage/worktree-bootstrap.lock', '');

    $manager = app(WorktreeManager::class);
    expect($manager->isDirty('lane'))->toBeFalse()
        ->and($manager->safetyCheck('lane')['clean'])->toBeTrue();

    File::put($path.'/edit.txt', 'work');
    expect($manager->isDirty('lane'))->toBeTrue()
        ->and($manager->safetyCheck('lane')['clean'])->toBeFalse();
});

test('delete of an unregistered directory without --force fails before the safety checks', function () {
    $path = $this->base.'/'.basename(base_path()).'-orphan';
    File::ensureDirectoryExists($path);
    Process::fake(['git worktree list --porcelain' => Process::result(), '*' => Process::result()]);

    $this->artisan('worktree:delete', ['name' => 'orphan'])
        ->expectsOutputToContain('not a registered worktree')
        ->assertFailed();

    Process::assertNotRan(fn (PendingProcess $process) => $process->path === $path);
    expect(is_dir($path))->toBeTrue();
});

test('delete and bootstrap print a broken bootstrap record as an error', function (string $command, array $options) {
    $path = $this->base.'/'.basename(base_path()).'-lane';
    File::ensureDirectoryExists($path.'/storage');
    File::put($path.'/storage/worktree-bootstrap.json', '{"stages":');
    Process::fake([
        'git worktree list --porcelain' => Process::result("worktree {$path}\nHEAD abc\nbranch refs/heads/lane\n\n"),
        '*' => Process::result(),
    ]);

    $this->artisan($command, ['name' => 'lane', ...$options])
        ->expectsOutputToContain('worktree-bootstrap.json is not valid JSON')
        ->assertFailed();

    expect(is_dir($path))->toBeTrue();
})->with([
    'delete' => ['worktree:delete', ['--force' => true]],
    'bootstrap --resume' => ['worktree:bootstrap', ['--resume' => true]],
    'bootstrap --status' => ['worktree:bootstrap', ['--status' => true]],
]);

test('bootstrap --resume prints a bootstrap record with the wrong shape as an error', function (string $record) {
    $path = $this->base.'/'.basename(base_path()).'-lane';
    File::ensureDirectoryExists($path.'/storage');
    File::put($path.'/storage/worktree-bootstrap.json', $record);
    Process::fake([
        // The first entry is the main checkout.
        'git worktree list --porcelain' => Process::result('worktree '.base_path()."\nHEAD abc\nbranch refs/heads/master\n\nworktree {$path}\nHEAD abc\nbranch refs/heads/lane\n\n"),
        '*' => Process::result(),
    ]);

    $this->artisan('worktree:bootstrap', ['name' => 'lane', '--resume' => true])
        ->expectsOutputToContain('worktree-bootstrap.json')
        ->assertFailed();
})->with(['null' => ['null'], 'completed is a string' => ['{"completed":"running"}'], 'list' => ['[1]']]);

test('delete of an invalid name fails with a message', function () {
    $this->artisan('worktree:delete', ['name' => '../lane', '--force' => true])
        ->expectsOutputToContain('name')
        ->assertFailed();
});
