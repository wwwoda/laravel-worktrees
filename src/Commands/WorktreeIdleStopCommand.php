<?php

namespace Woda\Worktrees\Commands;

use Illuminate\Console\Command;
use Woda\Worktrees\Contracts\BootstrapStrategy;
use Woda\Worktrees\Idle\IdleStacks;
use Woda\Worktrees\SailBootstrapStrategy;

/**
 * Stops the stacks of worktrees that nobody used for a while. Meant for a
 * host timer (cron, systemd) on agent hosts; where a router starts stacks on
 * the first request (Sablier), it is the fallback.
 */
class WorktreeIdleStopCommand extends Command
{
    protected $signature = 'worktree:idle-stop
        {--minutes= : Stop stacks unused for this many minutes (default: idle.minutes)}
        {--include-main : Also stop the main checkout\'s stack}
        {--dry-run : Show what would stop}
        {--json : Output as JSON}';

    protected $description = 'Stop the containers of worktrees that were not used for a while (docker compose stop; their data stays)';

    public function handle(IdleStacks $stacks, BootstrapStrategy $strategy): int
    {
        if (! $strategy instanceof SailBootstrapStrategy) {
            $this->components->error('Idle stacks need the sail or shared strategy.');

            return self::FAILURE;
        }
        $minutesOption = $this->option('minutes');
        $minutes = is_numeric($minutesOption) ? (int) $minutesOption : (is_numeric($configured = config('worktrees.idle.minutes', 30)) ? (int) $configured : 30);
        if ($minutes < 1) {
            $this->components->error('--minutes must be 1 or more.');

            return self::FAILURE;
        }
        $includeMain = (bool) $this->option('include-main') || (bool) config('worktrees.idle.include_main', false);
        $checkouts = $stacks->checkouts($includeMain);
        $mainProject = null;
        foreach ($checkouts as $index => $checkout) {
            $checkouts[$index]['project'] = $stacks->project($checkout['path']);
            $checkouts[$index]['running'] = $stacks->running($checkout['path']);
        }
        // How many checkouts resolve to each project: a stop acts on all of them.
        $projectCount = [];
        foreach ($stacks->checkouts(true) as $checkout) {
            $project = $checkout['main'] ? $stacks->project($checkout['path']) : null;
            foreach ($checkouts as $listed) {
                if ($listed['path'] === $checkout['path']) {
                    $project = $listed['project'];
                }
            }
            if ($checkout['main']) {
                $mainProject = $project;
            }
            if ($project !== null) {
                $projectCount[$project] = ($projectCount[$project] ?? 0) + 1;
            }
        }
        $apps = [];
        foreach ($checkouts as $checkout) {
            if (is_array($checkout['running']) && isset($checkout['running'][$stacks->appService()])) {
                $apps[] = $checkout['running'][$stacks->appService()];
            }
        }
        $traffic = $stacks->traffic($apps);
        $now = time();
        $rows = [];
        $failed = false;
        foreach ($checkouts as $checkout) {
            $row = ['worktree' => $checkout['name'], 'path' => $checkout['path'], 'idle_minutes' => null, 'action' => 'none'];
            if ($checkout['running'] === null) {
                $failed = true;
                $rows[] = [...$row, 'action' => 'skipped: docker compose ps failed'];

                continue;
            }
            if ($checkout['running'] === []) {
                $rows[] = [...$row, 'action' => 'stopped already'];

                continue;
            }
            // A worktree with the main checkout's project would stop the main checkout's containers.
            if (! $checkout['main'] && $checkout['project'] !== null && $checkout['project'] === $mainProject) {
                $rows[] = [...$row, 'action' => "skipped: the main checkout's compose project"];

                continue;
            }
            // Two worktrees with one project (a copied .env): a stop would stop the other one too.
            if ($checkout['project'] !== null && ($projectCount[$checkout['project']] ?? 0) > 1) {
                $rows[] = [...$row, 'action' => "skipped: compose project {$checkout['project']} of {$projectCount[$checkout['project']]} checkouts"];

                continue;
            }
            $app = $checkout['running'][$stacks->appService()] ?? null;
            // A running app container without a traffic reading (docker stats failed): unknown, not idle.
            if ($app !== null && ! isset($traffic[$app])) {
                $rows[] = [...$row, 'action' => 'skipped: no traffic reading'];

                continue;
            }
            $idle = intdiv($now - $stacks->lastActivity($checkout['path'], $app === null ? null : $traffic[$app], $now), 60);
            $row['idle_minutes'] = $idle;
            if ($idle < $minutes) {
                $rows[] = [...$row, 'action' => 'in use'];

                continue;
            }
            if ($this->option('dry-run')) {
                $rows[] = [...$row, 'action' => 'would stop'];

                continue;
            }
            $result = $stacks->stopUnlessWoken($checkout['path'], $now);
            if ($result === null) {
                $rows[] = [...$row, 'action' => 'in use (woken)'];

                continue;
            }
            $failed = $failed || ! $result->successful();
            $rows[] = [...$row, 'action' => $result->successful() ? 'stopped' : 'stop failed: '.trim($result->errorOutput())];
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->table(['Worktree', 'Idle (min)', 'Action'], array_map(
                static fn (array $row): array => [$row['worktree'], $row['idle_minutes'] ?? '-', $row['action']],
                $rows,
            ));
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
