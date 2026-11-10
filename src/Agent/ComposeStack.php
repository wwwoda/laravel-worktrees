<?php

namespace Woda\Worktrees\Agent;

use Illuminate\Support\Facades\Process;
use RuntimeException;
use Woda\Worktrees\ComposeProcess;
use Woda\Worktrees\Shared\EnvFile;

/**
 * The compose stack of one checkout: its services, their compose profiles,
 * its containers and their memory. Every call runs `docker compose` in the
 * checkout with the inherited variables cleared (ComposeProcess), so compose
 * reads the checkout's own `.env`, `COMPOSE_PROFILES` included.
 */
final class ComposeStack
{
    /** @var array<mixed>|null */
    private ?array $config = null;

    public function __construct(
        public readonly string $path,
        public readonly string $appService,
    ) {}

    /**
     * Every service of every profile, with its profiles (empty: always on).
     *
     * @return array<string, list<string>>
     */
    public function serviceProfiles(): array
    {
        $services = $this->config()['services'] ?? null;
        $map = [];
        foreach (is_array($services) ? $services : [] as $service => $definition) {
            $profiles = is_array($definition) && is_array($definition['profiles'] ?? null) ? $definition['profiles'] : [];
            $map[(string) $service] = array_values(array_filter($profiles, is_string(...)));
        }

        return $map;
    }

    /**
     * The profiles that the compose file defines.
     *
     * @return list<string>
     */
    public function availableProfiles(): array
    {
        $profiles = array_merge([], ...array_values($this->serviceProfiles()));
        $profiles = array_values(array_unique($profiles));
        sort($profiles);

        return $profiles;
    }

    /**
     * The profiles that `COMPOSE_PROFILES` in the checkout's `.env` turns on.
     *
     * @return list<string>
     */
    public function activeProfiles(): array
    {
        return self::splitProfiles(EnvFile::resolved($this->path.'/.env', 'COMPOSE_PROFILES') ?? '');
    }

    /**
     * @return list<string>
     */
    public static function splitProfiles(string $value): array
    {
        return array_values(array_unique(array_filter(array_map(trim(...), explode(',', $value)), static fn (string $profile): bool => $profile !== '')));
    }

    /**
     * Services that run with the given profiles: those without a profile and
     * those with one of the given profiles.
     *
     * @param  list<string>  $profiles
     * @return list<string>
     */
    public function servicesFor(array $profiles): array
    {
        $services = [];
        foreach ($this->serviceProfiles() as $service => $serviceProfiles) {
            // `*` in COMPOSE_PROFILES turns on every profile.
            if ($serviceProfiles === [] || in_array('*', $profiles, true) || array_intersect($serviceProfiles, $profiles) !== []) {
                $services[] = $service;
            }
        }

        return $services;
    }

    /**
     * The image of a service, as compose resolves it.
     */
    public function image(string $service): ?string
    {
        $services = $this->config()['services'] ?? null;
        $definition = is_array($services) ? ($services[$service] ?? null) : null;

        return is_array($definition) && is_string($definition['image'] ?? null) ? $definition['image'] : null;
    }

    public function projectName(): ?string
    {
        $name = $this->config()['name'] ?? null;

        return is_string($name) && $name !== '' ? $name : null;
    }

    /**
     * A worktree whose `.env` names the compose project of the main checkout
     * would start and stop the main checkout's containers.
     */
    public function assertOwnProject(self $main): void
    {
        $ours = $this->projectName();
        if ($ours !== null && $ours === $main->projectName()) {
            throw new RuntimeException("This worktree has the compose project of the main checkout ('{$ours}'): changing its services would act on the main checkout's containers. Give worktrees their own COMPOSE_PROJECT_NAME (worktrees.env_overrides).");
        }
    }

    /**
     * The folder of another checkout whose containers carry this compose
     * project's name (compose writes the folder into the label
     * `com.docker.compose.project.working_dir`); null when none does.
     */
    public function foreignOwner(): ?string
    {
        $project = $this->projectName();
        if ($project === null) {
            return null;
        }
        $result = Process::timeout(30)->run(sprintf(
            'docker ps -a --filter %s --format %s',
            escapeshellarg('label=com.docker.compose.project='.$project),
            escapeshellarg('{{.Label "com.docker.compose.project.working_dir"}}'),
        ));
        if (! $result->successful()) {
            return null;
        }
        $self = rtrim(realpath($this->path) ?: $this->path, '/');
        foreach (array_unique(array_filter(array_map(trim(...), preg_split('/\r?\n/', $result->output()) ?: []))) as $folder) {
            if (rtrim(realpath($folder) ?: $folder, '/') !== $self) {
                return $folder;
            }
        }

        return null;
    }

    /**
     * The containers of the compose project, also stopped ones.
     *
     * @return list<array{service: string, name: string, state: string, health: string}>
     */
    public function containers(): array
    {
        $result = ComposeProcess::in($this->path, 30)->run('docker compose ps --all --format json');
        if (! $result->successful()) {
            throw new RuntimeException('docker compose ps failed: '.trim($result->errorOutput()));
        }

        return self::parsePs($result->output());
    }

    public function isRunning(string $service): bool
    {
        foreach ($this->containers() as $container) {
            if ($container['service'] === $service && $container['state'] === 'running') {
                return true;
            }
        }

        return false;
    }

    /**
     * `docker compose ps --format json` prints one object per line (Compose
     * 2.21 and later) or one array (earlier).
     *
     * @return list<array{service: string, name: string, state: string, health: string}>
     */
    public static function parsePs(string $output): array
    {
        $output = trim($output);
        if ($output === '') {
            return [];
        }
        $decoded = json_decode($output, true);
        $rows = is_array($decoded) && array_is_list($decoded) ? $decoded : [];
        if (! is_array($decoded)) {
            foreach (preg_split('/\r?\n/', $output) ?: [] as $line) {
                $row = json_decode($line, true);
                if (is_array($row)) {
                    $rows[] = $row;
                }
            }
        } elseif (! array_is_list($decoded)) {
            $rows = [$decoded];
        }
        $containers = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $containers[] = [
                'service' => is_string($row['Service'] ?? null) ? $row['Service'] : '',
                'name' => is_string($row['Name'] ?? null) ? $row['Name'] : '',
                'state' => is_string($row['State'] ?? null) ? strtolower($row['State']) : '',
                'health' => is_string($row['Health'] ?? null) ? strtolower($row['Health']) : '',
            ];
        }
        usort($containers, static fn (array $a, array $b): int => strcmp($a['service'], $b['service']));

        return $containers;
    }

    /**
     * Memory use of running containers in bytes, by container name.
     *
     * @param  list<string>  $names
     * @return array<string, int>
     */
    public function memory(array $names): array
    {
        if ($names === []) {
            return [];
        }
        $result = Process::timeout(30)->run('docker stats --no-stream --format '.escapeshellarg('{{.Name}}|{{.MemUsage}}').' '.implode(' ', array_map(escapeshellarg(...), $names)));
        if (! $result->successful()) {
            return [];
        }
        $memory = [];
        foreach (preg_split('/\r?\n/', trim($result->output())) ?: [] as $line) {
            [$name, $usage] = array_pad(explode('|', $line, 2), 2, '');
            $bytes = self::parseBytes(trim(explode('/', $usage)[0]));
            if ($name !== '' && $bytes !== null) {
                $memory[$name] = $bytes;
            }
        }

        return $memory;
    }

    /**
     * When each container started (Unix time) and the ID of the image it runs, by container name.
     *
     * @param  list<string>  $names
     * @return array<string, array{started: int|null, image: string}>
     */
    public function inspect(array $names): array
    {
        if ($names === []) {
            return [];
        }
        $result = Process::timeout(30)->run('docker inspect --format '.escapeshellarg('{{.Name}}|{{.State.StartedAt}}|{{.Image}}').' '.implode(' ', array_map(escapeshellarg(...), $names)));
        if (! $result->successful()) {
            return [];
        }
        $details = [];
        foreach (preg_split('/\r?\n/', trim($result->output())) ?: [] as $line) {
            [$name, $started, $image] = array_pad(explode('|', $line, 3), 3, '');
            // Docker prints nanoseconds; strtotime() reads up to microseconds.
            $time = strtotime((string) preg_replace('/(\.\d{6})\d+/', '$1', trim($started)));
            if ($name !== '') {
                $details[ltrim($name, '/')] = ['started' => $time === false ? null : $time, 'image' => trim($image)];
            }
        }

        return $details;
    }

    /**
     * `36.5MiB`, `1.2GiB`, `512kB` as bytes.
     */
    public static function parseBytes(string $value): ?int
    {
        if (! preg_match('/^([\d.]+)\s*([KMGT]?i?B)$/i', $value, $match)) {
            return null;
        }
        $units = ['b' => 0, 'kb' => 1, 'kib' => 1, 'mb' => 2, 'mib' => 2, 'gb' => 3, 'gib' => 3, 'tb' => 4, 'tib' => 4];
        $unit = strtolower($match[2]);
        $base = str_contains($unit, 'i') ? 1024 : 1000;

        return (int) round((float) $match[1] * $base ** ($units[$unit] ?? 0));
    }

    /**
     * `docker compose config` of every profile. Without it the commands cannot
     * tell the services; a failure says why.
     *
     * @return array<mixed>
     */
    private function config(): array
    {
        if ($this->config !== null) {
            return $this->config;
        }
        $result = ComposeProcess::in($this->path, 60)->run('docker compose --profile '.escapeshellarg('*').' config --format json');
        $config = $result->successful() ? json_decode($result->output(), true) : null;
        if (! is_array($config)) {
            throw new RuntimeException('docker compose config failed: '.trim($result->errorOutput() ?: $result->output()));
        }

        return $this->config = $config;
    }
}
