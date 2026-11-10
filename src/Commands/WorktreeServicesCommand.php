<?php

namespace Woda\Worktrees\Commands;

use Illuminate\Console\Command;
use RuntimeException;
use Woda\Worktrees\Agent\ComposeStack;
use Woda\Worktrees\Agent\WorktreeInspector;
use Woda\Worktrees\Commands\Concerns\ResolvesWorktree;
use Woda\Worktrees\ComposeProcess;
use Woda\Worktrees\Shared\EnvFile;

class WorktreeServicesCommand extends Command
{
    use ResolvesWorktree;

    protected $signature = 'worktree:services
        {profiles?* : Compose profiles to run, for example "workers"; "none" for none (omit to show them)}
        {--w|worktree= : Worktree name, folder or "main" (default: this checkout)}
        {--add : Add the profiles to the ones that run}
        {--remove : Remove the profiles from the ones that run}
        {--no-apply : Only write COMPOSE_PROFILES to .env; do not start or stop containers}';

    protected $description = 'Show or switch the optional services (compose profiles) of a worktree, such as queue workers or phpMyAdmin';

    public function handle(WorktreeInspector $inspector): int
    {
        try {
            $target = $this->resolveWorktree($inspector->manager(), is_string($this->option('worktree')) ? $this->option('worktree') : null);
            $stack = $inspector->stack($target->path);
            if ($stack === null) {
                throw new RuntimeException('Services are compose profiles: this needs the sail or shared strategy.');
            }
            /** @var list<string> $requested */
            $requested = $this->argument('profiles');
            if ($requested === []) {
                $this->show($stack);

                return self::SUCCESS;
            }
            $profiles = $this->wanted($stack, $requested);
            $inspector->assertOwnStack($target);
            EnvFile::upsert($target->path.'/.env', ['COMPOSE_PROFILES' => implode(',', $profiles)]);
            if ($this->option('no-apply')) {
                $this->components->info('COMPOSE_PROFILES='.implode(',', $profiles).' written; containers unchanged.');

                return self::SUCCESS;
            }
            $this->apply($stack, $profiles);
        } catch (RuntimeException|\JsonException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }
        $this->components->info('Profiles of '.$target->name.': '.($profiles === [] ? 'none' : implode(', ', $profiles)).'.');

        return self::SUCCESS;
    }

    private function show(ComposeStack $stack): void
    {
        $active = $stack->activeProfiles();
        $running = [];
        foreach ($stack->containers() as $container) {
            if ($container['state'] === 'running') {
                $running[] = $container['service'];
            }
        }
        $rows = [];
        foreach ($stack->availableProfiles() as $profile) {
            $services = array_keys(array_filter($stack->serviceProfiles(), static fn (array $profiles): bool => in_array($profile, $profiles, true)));
            $rows[] = [
                $profile,
                in_array($profile, $active, true) ? 'on' : 'off',
                implode(', ', array_map(static fn (string $s): string => $s.(in_array($s, $running, true) ? ' (running)' : ''), $services)),
            ];
        }
        if ($rows === []) {
            $this->components->info('The compose file defines no profiles.');

            return;
        }
        $this->table(['Profile', 'State', 'Services'], $rows);
        $aliases = $this->aliases();
        if ($aliases !== []) {
            $this->line('  Aliases: '.implode(', ', array_map(static fn (string $alias, string $profile): string => "{$alias} = {$profile}", array_keys($aliases), $aliases)));
        }
    }

    /**
     * The profiles after this call: the requested ones (aliases resolved), or
     * the active ones plus or minus them.
     *
     * @param  list<string>  $requested
     * @return list<string>
     */
    private function wanted(ComposeStack $stack, array $requested): array
    {
        $aliases = $this->aliases();
        $available = $stack->availableProfiles();
        $names = [];
        foreach ($requested as $name) {
            foreach (ComposeStack::splitProfiles($name) as $part) {
                $names[] = $aliases[$part] ?? $part;
            }
        }
        $names = array_values(array_unique($names));
        if ($names === ['none']) {
            if ($this->option('add') || $this->option('remove')) {
                throw new RuntimeException('"none" cannot be added or removed; give it alone.');
            }

            return [];
        }
        $unknown = array_values(array_diff($names, $available));
        if ($unknown !== []) {
            throw new RuntimeException('Unknown profile '.implode(', ', $unknown).'. Available: '.($available === [] ? 'none' : implode(', ', $available)).'.');
        }
        if ($this->option('add')) {
            return array_values(array_unique([...$stack->activeProfiles(), ...$names]));
        }
        if ($this->option('remove')) {
            return array_values(array_diff($stack->activeProfiles(), $names));
        }

        return $names;
    }

    /**
     * Start what the profiles turn on, then stop and remove the services of
     * profiles that are off.
     *
     * @param  list<string>  $profiles
     */
    private function apply(ComposeStack $stack, array $profiles): void
    {
        $output = $this->output->isVerbose() ? fn (string $type, string $buffer) => $this->output->write($buffer) : null;
        $result = ComposeProcess::in($stack->path, 600)->run('docker compose up -d', $output);
        if (! $result->successful()) {
            throw new RuntimeException('docker compose up -d failed: '.trim($result->errorOutput()));
        }
        $off = array_values(array_diff(array_keys($stack->serviceProfiles()), $stack->servicesFor($profiles)));
        if ($off === []) {
            return;
        }
        $result = ComposeProcess::in($stack->path, 120)->run(
            'docker compose --profile '.escapeshellarg('*').' rm --stop --force '.implode(' ', array_map(escapeshellarg(...), $off)),
            $output,
        );
        if (! $result->successful()) {
            throw new RuntimeException('Stopping '.implode(', ', $off).' failed: '.trim($result->errorOutput()));
        }
    }

    /**
     * @return array<string, string>
     */
    private function aliases(): array
    {
        $aliases = config('worktrees.services.aliases', []);

        return is_array($aliases) ? array_filter($aliases, static fn (mixed $value, mixed $key): bool => is_string($key) && is_string($value), ARRAY_FILTER_USE_BOTH) : [];
    }
}
