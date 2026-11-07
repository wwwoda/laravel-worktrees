<?php

namespace Woda\Worktrees\Shared;

use Closure;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Woda\Worktrees\ComposeProcess;
use Woda\Worktrees\WorktreeManager;

/**
 * Moves worktrees of the Sail layout (one database container and volume per
 * worktree) to shared services, keeping their data.
 *
 * For each worktree: remove its old containers (by compose project label, so
 * the old compose file is not needed), start a temporary database container
 * straight from its old volume, dump the old database, import the dump into
 * the worktree's new app database on the shared server, and set up the rest
 * like `worktree:setup`. The old volume is never removed here.
 */
class SailConverter
{
    public function __construct(
        private readonly WorktreeManager $manager,
        private readonly DatabaseProvisioner $provisioner,
        private readonly string $image,
        private readonly string $volumePattern,
        private readonly string $baseBranch,
    ) {}

    /**
     * Every worktree and the main checkout, the main checkout first.
     *
     * @return list<array{name: string, path: string, branch: string|null, last_commit: string, merged: bool, converted: bool, main: bool}>
     */
    public function candidates(): array
    {
        $main = realpath(MainCheckout::path()) ?: MainCheckout::path();
        $list = [];
        foreach ($this->manager->registered() as $worktree) {
            $path = is_string($worktree['path'] ?? null) ? $worktree['path'] : '';
            if ($path === '' || ! is_dir($path)) {
                continue;
            }
            $isMain = (realpath($path) ?: $path) === $main;
            $branch = is_string($worktree['branch'] ?? null) ? $worktree['branch'] : null;
            $list[] = [
                'name' => $isMain ? 'main' : ($this->manager->nameFromPath($path) ?? trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower(basename($path))), '-')),
                'path' => $path,
                'branch' => $branch,
                'last_commit' => trim(Process::path($path)->timeout(10)->run('git log -1 --format=%cs')->output()),
                'merged' => ! $isMain && $branch !== null && $branch !== $this->baseBranch
                    && Process::path($main)->timeout(10)->run(['git', 'merge-base', '--is-ancestor', $branch, $this->baseBranch])->successful(),
                'converted' => self::isConverted($path),
                'main' => $isMain,
            ];
        }
        usort($list, static fn (array $a, array $b): int => $b['main'] <=> $a['main']);

        return $list;
    }

    public static function isConverted(string $path): bool
    {
        $state = WorktreeState::load($path);

        return $state !== null && empty($state->data['converting']) && $state->string('source') !== null;
    }

    /**
     * @param  array{name: string, path: string, main: bool}  $candidate
     * @param  (Closure(string): void)|null  $onStep
     * @param  (Closure(string, string): void)|null  $output
     */
    public function convert(array $candidate, ?Closure $onStep = null, ?Closure $output = null): void
    {
        $step = $onStep ?? static fn (string $message) => null;
        $path = $candidate['path'];
        // The first run records the old names: a later run finds `.env` rewritten.
        [$project, $oldDatabase] = $this->markConverting($path);
        $step("Removing the old containers of compose project '{$project}'...");
        $this->removeOldContainers($project, $output);

        $dump = $this->dumpOldVolume($project, $oldDatabase, $step, $output);
        $fill = $dump === null ? null : function (string $database) use ($dump, $output, $step): void {
            $step("Importing the old data into {$database}...");
            $this->provisioner->server()->importFile($database, $dump, $output);
        };

        try {
            if ($candidate['main']) {
                $this->provisioner->provisionMain($path, $fill, $output, $step);
                $step('Starting the main checkout\'s app container...');
                $result = ComposeProcess::in($path, 600)->run('docker compose up -d', $output);
                if (! $result->successful()) {
                    throw new RuntimeException("Starting the main checkout's container failed: {$result->errorOutput()}");
                }
            } else {
                // The old bootstrap record says "done" for the Sail stages: start over.
                $record = $path.'/storage/worktree-bootstrap.json';
                if (File::exists($record) && ! File::exists($path.'/storage/worktree-bootstrap.sail.json')) {
                    File::move($record, $path.'/storage/worktree-bootstrap.sail.json');
                }
                $this->manager->bootstrapPath($candidate['name'], $path, [
                    'resume' => true,
                    'setup' => true,
                    'fill_app' => $fill,
                ], $step, $output);
            }
        } finally {
            if ($dump !== null) {
                File::delete($dump);
            }
        }

        $state = WorktreeState::load($path) ?? new WorktreeState([]);
        unset($state->data['converting']);
        $state->data['converted_at'] = date(DATE_ATOM);
        $state->data['old_volume'] = $this->volumeName($project);
        $state->save($path);
    }

    /**
     * Old volumes that match the backup pattern, with their size.
     *
     * @return list<array{name: string, size: string}>
     */
    public function backupVolumes(string $pattern): array
    {
        $result = Process::timeout(120)->run('docker system df -v --format json');
        if (! $result->successful()) {
            throw new RuntimeException("Cannot list Docker volumes: {$result->errorOutput()}");
        }
        $data = json_decode($result->output(), true);
        $volumes = is_array($data) && is_array($data['Volumes'] ?? null) ? $data['Volumes'] : [];
        $list = [];
        foreach ($volumes as $volume) {
            $name = is_array($volume) && is_string($volume['Name'] ?? null) ? $volume['Name'] : '';
            if ($name !== '' && preg_match($pattern, $name)) {
                $list[] = ['name' => $name, 'size' => is_string($volume['Size'] ?? null) ? $volume['Size'] : '?'];
            }
        }
        usort($list, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $list;
    }

    public function volumeName(string $project): string
    {
        return strtr($this->volumePattern, ['{compose_project}' => $project]);
    }

    private function oldComposeProject(string $path): string
    {
        $project = EnvFile::get($path.'/.env', 'COMPOSE_PROJECT_NAME');
        if ($project === null || $project === '' || str_contains($project, '$')) {
            // Compose's default: the folder name, lower case, letters, digits, dash and underscore.
            $project = (string) preg_replace('/[^a-z0-9_-]/', '', strtolower(basename($path)));
        }
        if (! preg_match('/^[a-z0-9][a-z0-9_-]*$/', $project)) {
            throw new RuntimeException("Unexpected compose project name '{$project}'.");
        }

        return $project;
    }

    /**
     * @return array{string, string|null}
     */
    private function markConverting(string $path): array
    {
        $state = WorktreeState::load($path) ?? new WorktreeState([]);
        $state->data['converting'] = true;
        $state->data['old_project'] ??= $this->oldComposeProject($path);
        $state->data['old_database'] ??= EnvFile::get($path.'/.env', 'DB_DATABASE');
        $state->save($path);

        return [(string) $state->string('old_project'), $state->string('old_database')];
    }

    /**
     * @param  (Closure(string, string): void)|null  $output
     */
    private function removeOldContainers(string $project, ?Closure $output): void
    {
        $ids = Process::timeout(30)->run(['docker', 'ps', '-aq', '--filter', 'label=com.docker.compose.project='.$project]);
        if (! $ids->successful()) {
            throw new RuntimeException("Cannot list the old containers: {$ids->errorOutput()}");
        }
        $list = array_values(array_filter(array_map(trim(...), explode("\n", $ids->output()))));
        if ($list === []) {
            return;
        }
        $result = Process::timeout(120)->run(['docker', 'rm', '-f', ...$list], $output);
        if (! $result->successful()) {
            throw new RuntimeException("Removing the old containers failed: {$result->errorOutput()}");
        }
    }

    /**
     * Dump the old database from its volume into a temporary file; null when
     * the volume does not exist or holds no tables.
     *
     * @param  Closure(string): void  $step
     * @param  (Closure(string, string): void)|null  $output
     */
    private function dumpOldVolume(string $project, ?string $database, Closure $step, ?Closure $output): ?string
    {
        $volume = $this->volumeName($project);
        if (! Process::timeout(30)->run(['docker', 'volume', 'inspect', $volume])->successful()) {
            $step("No old volume '{$volume}': the worktree starts from the template.");

            return null;
        }
        if ($database === null || ! preg_match('/^[A-Za-z0-9_]{1,63}$/', $database)) {
            $step('The old .env names no database: the worktree starts from the template.');

            return null;
        }
        $container = 'worktree-convert-'.substr(md5($volume), 0, 10);
        $step("Starting a temporary database from volume '{$volume}'...");
        $started = Process::timeout(120)->run(['docker', 'run', '-d', '--rm', '--name', $container, '-v', $volume.':/var/lib/mysql', $this->image, '--skip-grant-tables'], $output);
        if (! $started->successful()) {
            throw new RuntimeException("Starting a database from volume '{$volume}' failed: {$started->errorOutput()}");
        }
        try {
            $deadline = time() + 90;
            while (! Process::timeout(10)->run(['docker', 'exec', $container, 'mariadb', '-N', '-e', 'SELECT 1'])->successful()) {
                if (time() >= $deadline) {
                    throw new RuntimeException("The database from volume '{$volume}' did not start.");
                }
                usleep(1_000_000);
            }
            $count = Process::timeout(30)->run(['docker', 'exec', $container, 'mariadb', '-N', '-e',
                "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '{$database}'"]);
            if (! $count->successful() || (int) trim($count->output()) === 0) {
                $step("The old volume '{$volume}' holds no data: the worktree starts from the template.");

                return null;
            }
            $file = (string) tempnam(sys_get_temp_dir(), 'worktree-convert-');
            $step("Dumping the old database '{$database}'...");
            $dump = Process::timeout(1800)->run(sprintf('docker exec %s mariadb-dump --single-transaction --routines --triggers --events %s > %s',
                escapeshellarg($container), escapeshellarg($database), escapeshellarg($file)), $output);
            if (! $dump->successful()) {
                File::delete($file);

                throw new RuntimeException("Dumping the old database failed: {$dump->errorOutput()}");
            }

            return $file;
        } finally {
            Process::timeout(60)->run(['docker', 'rm', '-f', $container]);
        }
    }
}
