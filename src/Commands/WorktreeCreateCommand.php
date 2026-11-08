<?php

namespace Woda\Worktrees\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Woda\Worktrees\Shared\EnvFile;
use Woda\Worktrees\WorktreeManager;

use function Laravel\Prompts\search;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

class WorktreeCreateCommand extends Command
{
    protected $signature = 'worktree:create
        {name? : Worktree name (interactive if omitted)}
        {--branch= : Branch name (default: agent/{name})}
        {--base= : Base branch to create from}
        {--issue= : GitHub issue number}
        {--pr= : Pull request number}
        {--skip-deps : Skip dependency installation}
        {--skip-build : Skip frontend build}
        {--skip-db : Skip database cloning}
        {--from-main : Shared services: copy the main checkout\'s database instead of the template (only where config allows it)}
        {--horizon : Shared services: run Horizon as the queue worker instead of queue:listen}';

    protected $description = 'Create a git worktree with full environment isolation';

    public function handle(WorktreeManager $worktreeManager): int
    {
        /** @var string|null $name */
        $name = $this->argument('name');
        /** @var string|null $branch */
        $branch = $this->option('branch');
        /** @var string|null $issue */
        $issue = $this->option('issue');
        /** @var string|null $pr */
        $pr = $this->option('pr');

        if ($issue && $pr) {
            $this->components->error('--issue and --pr are mutually exclusive.');

            return self::FAILURE;
        }

        // Interactive mode: no name, no branch/issue/pr options
        if ($name === null && ! $branch && ! $issue && ! $pr) {
            $chosen = $this->interactive();
            if ($chosen === null) {
                return self::FAILURE;
            }
            [$name, $branch] = $chosen;
        }

        // Non-interactive --issue
        if ($issue) {
            $resolved = $this->resolveIssueBranch((int) $issue);
            if ($resolved === null) {
                return self::FAILURE;
            }
            $branch = $resolved;
            $name ??= $issue;
        }

        // Non-interactive --pr
        if ($pr) {
            $resolved = $this->resolvePrBranch((int) $pr);
            if ($resolved === null) {
                return self::FAILURE;
            }
            $branch = $resolved;
            $name ??= "pr-{$pr}";
        }

        // Derive name from --branch when no positional name
        if ($name === null && $branch) {
            $name = $this->slugifyBranch($branch);
        }

        /** @var string $name */
        if (! preg_match('/^[a-zA-Z0-9-]+$/', $name)) {
            $this->components->error('Name must be alphanumeric with hyphens only.');

            return self::FAILURE;
        }

        if ($worktreeManager->exists($name)) {
            $this->components->error("Worktree '{$name}' already exists.");

            return self::FAILURE;
        }

        /** @var string|null $baseBranch */
        $baseBranch = $this->option('base');

        $this->components->info("Creating worktree '{$name}'...");

        try {
            $path = $worktreeManager->create($name, $branch, $baseBranch);
        } catch (\RuntimeException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Worktree created at {$path}");

        $this->components->info('Bootstrapping...');

        $verbose = $this->output->isVerbose();

        $onStep = $verbose
            ? fn (string $msg) => $this->components->info($msg)
            : null;

        $processOutput = $verbose
            ? fn (string $type, string $buffer) => $this->output->write($buffer)
            : null;

        $timings = [];
        $started = microtime(true);
        try {
            $worktreeManager->bootstrap($name, [
                'skip_deps' => (bool) $this->option('skip-deps'),
                'skip_build' => (bool) $this->option('skip-build'),
                'skip_db' => (bool) $this->option('skip-db'),
                'from_main' => (bool) $this->option('from-main'),
                'horizon' => (bool) $this->option('horizon'),
                'base' => $baseBranch,
            ], $onStep, $processOutput, function (string $stage, float $seconds) use (&$timings): void {
                $timings[] = [$stage, number_format($seconds, 1).' s'];
            });
        } catch (\RuntimeException $e) {
            $this->components->error($e->getMessage());
            $this->components->warn("The checkout is kept. Continue with: php artisan worktree:bootstrap {$name} --resume");

            return self::FAILURE;
        }

        $timings[] = ['total', number_format(microtime(true) - $started, 1).' s'];
        $this->table(['Step', 'Time'], $timings);
        $this->components->info("Worktree '{$name}' is ready.");
        $url = EnvFile::get($path.'/.env', 'APP_URL');
        if ($url !== null && $url !== '') {
            $this->line('  '.$url);
        }

        return self::SUCCESS;
    }

    /**
     * Null when the chosen source could not be resolved; the error is already printed.
     *
     * @return array{string, string|null}|null
     */
    private function interactive(): ?array
    {
        $mode = $this->promptMode();

        $chosen = match ($mode) {
            'issue' => $this->promptIssue(),
            'pr' => $this->promptPullRequest(),
            'branch' => $this->promptBranch(),
            'fresh' => ['', null],
        };
        if ($chosen === null) {
            return null;
        }
        [$defaultName, $branch] = $chosen;

        $name = $this->promptName($defaultName);

        return [$name, $branch];
    }

    /**
     * @return 'issue'|'pr'|'branch'|'fresh'
     */
    private function promptMode(): string
    {
        /** @var 'issue'|'pr'|'branch'|'fresh' */
        return select(
            label: 'What should this worktree be based on?',
            options: [
                'issue' => 'GitHub issue',
                'pr' => 'Pull request',
                'branch' => 'Existing branch',
                'fresh' => 'Fresh (new branch)',
            ],
        );
    }

    /**
     * @return array{string, string|null}|null
     */
    private function promptIssue(): ?array
    {
        $result = Process::path(base_path())->run('gh issue list --state open --limit 100 --json number,title');

        if (! $result->successful()) {
            $this->components->error('Failed to fetch issues: '.$result->errorOutput());

            return null;
        }

        /** @var list<array{number: int, title: string}> $issues */
        $issues = json_decode($result->output(), true) ?: [];

        if ($issues === []) {
            $this->components->warn('No open issues found.');

            return ['', null];
        }

        $options = [];
        foreach ($issues as $issue) {
            $options[$issue['number']] = "#{$issue['number']} {$issue['title']}";
        }

        /** @var int $issueNumber */
        $issueNumber = search(
            label: 'Search for an issue',
            options: fn (string $value) => array_filter(
                $options,
                fn (string $label) => $value === '' || str_contains(Str::lower($label), Str::lower($value)),
            ),
            placeholder: 'Type to filter...',
            scroll: 10,
        );

        // An unresolved issue (several linked branches, gh failed) stops; it must not fall back to a fresh branch.
        $branch = $this->resolveIssueBranch($issueNumber);
        if ($branch === null) {
            return null;
        }

        return [(string) $issueNumber, $branch];
    }

    /**
     * @return array{string, string|null}|null
     */
    private function promptPullRequest(): ?array
    {
        $result = Process::path(base_path())->run('gh pr list --state open --limit 100 --json number,title');

        if (! $result->successful()) {
            $this->components->error('Failed to fetch PRs: '.$result->errorOutput());

            return null;
        }

        /** @var list<array{number: int, title: string}> $prs */
        $prs = json_decode($result->output(), true) ?: [];

        if ($prs === []) {
            $this->components->warn('No open pull requests found.');

            return ['', null];
        }

        $options = [];
        foreach ($prs as $pr) {
            $options[$pr['number']] = "#{$pr['number']} {$pr['title']}";
        }

        /** @var int $prNumber */
        $prNumber = search(
            label: 'Search for a pull request',
            options: fn (string $value) => array_filter(
                $options,
                fn (string $label) => $value === '' || str_contains(Str::lower($label), Str::lower($value)),
            ),
            placeholder: 'Type to filter...',
            scroll: 10,
        );

        $branch = $this->resolvePrBranch($prNumber);
        if ($branch === null) {
            return null;
        }

        return ["pr-{$prNumber}", $branch];
    }

    /**
     * @return array{string, string|null}|null
     */
    private function promptBranch(): ?array
    {
        $result = Process::path(base_path())->run(['git', 'branch', '-a', '--format=%(refname:short)']);

        if (! $result->successful()) {
            $this->components->error('Failed to list branches: '.$result->errorOutput());

            return null;
        }

        $checkedOut = $this->branchesCheckedOutByWorktrees();

        $branches = collect(explode("\n", trim($result->output())))
            ->map(fn (string $b) => (string) preg_replace('#^origin/#', '', trim($b)))
            ->filter(fn (string $b) => $b !== '' && $b !== 'HEAD' && ! in_array($b, $checkedOut, true))
            ->unique()
            ->values()
            ->all();

        if ($branches === []) {
            $this->components->warn('No branches found.');

            return ['', null];
        }

        /** @var string $branch */
        $branch = search(
            label: 'Search for a branch',
            options: fn (string $value) => array_values(array_filter(
                $branches,
                fn (string $b) => $value === '' || str_contains(Str::lower($b), Str::lower($value)),
            )),
            placeholder: 'Type to filter...',
            scroll: 10,
        );

        $defaultName = $this->slugifyBranch($branch);

        return [$defaultName, $branch];
    }

    private function promptName(string $default): string
    {
        $projectName = basename(base_path());

        return text(
            label: 'Worktree name',
            placeholder: 'e.g. 123-add-auth',
            default: $default,
            required: true,
            validate: function (string $value) {
                if (! preg_match('/^[a-zA-Z0-9-]+$/', $value)) {
                    return 'Name must be alphanumeric with hyphens only.';
                }

                return null;
            },
            hint: $default !== '' ? "Folder: {$projectName}-{$default}" : "Folder: {$projectName}-{name}",
        );
    }

    private function resolveIssueBranch(int $issueNumber): ?string
    {
        // Check for existing linked branches
        $result = Process::path(base_path())->run(
            sprintf('gh issue develop --list %d', $issueNumber),
        );

        if (! $result->successful()) {
            $this->components->error("Failed to resolve issue #{$issueNumber}: ".$result->errorOutput());

            return null;
        }

        $branches = array_values(array_filter(array_map(
            fn (string $line): string => trim(explode("\t", trim($line))[0]),
            explode("\n", trim($result->output())),
        )));
        if (count($branches) > 1) {
            $this->components->error('Issue has multiple linked branches. Select an explicit --branch.');

            return null;
        }

        if ($branches !== []) {
            $branch = $branches[0];
            $this->components->info("Found linked branch: {$branch}");

            return $this->fetchBranch($branch) ? $branch : null;
        }

        // No linked branch — create one via gh issue develop
        /** @var string $baseBranch */
        $baseBranch = $this->option('base') ?? config('worktrees.base_branch');

        $result = Process::path(base_path())->run(
            sprintf('gh issue develop %d --base %s', $issueNumber, escapeshellarg($baseBranch)),
        );

        if (! $result->successful()) {
            $this->components->error("Failed to create branch for issue #{$issueNumber}: ".$result->errorOutput());

            return null;
        }

        // gh issue develop outputs a URL like https://github.com/owner/repo/tree/branch-name
        $output = trim($result->output());
        $branch = preg_match('#/tree/(.+)$#', $output, $m) ? $m[1] : $output;

        if ($branch === '') {
            $this->components->error("gh issue develop returned empty branch name for #{$issueNumber}.");

            return null;
        }

        $this->components->info("Created linked branch: {$branch}");

        return $this->fetchBranch($branch) ? $branch : null;
    }

    private function resolvePrBranch(int $prNumber): ?string
    {
        $result = Process::path(base_path())->run(
            sprintf('gh pr view %d --json headRefName,isCrossRepository', $prNumber),
        );

        if (! $result->successful()) {
            $this->components->error("Failed to resolve PR #{$prNumber}: ".$result->errorOutput());

            return null;
        }

        /** @var array{headRefName: string, isCrossRepository?: bool}|null $data */
        $data = json_decode($result->output(), true);

        if (! $data || empty($data['headRefName'])) {
            $this->components->error("Could not determine branch for PR #{$prNumber}.");

            return null;
        }

        if (! empty($data['isCrossRepository'])) {
            // A fork's branch is not on origin, and its name may equal a local one
            // (often `master`): take GitHub's pull ref into its own local branch.
            $branch = "pr-{$prNumber}";
            $fetch = Process::path(base_path())->timeout(60)->run(
                sprintf('git fetch origin %s', escapeshellarg("pull/{$prNumber}/head:refs/heads/{$branch}")),
            );
            if (! $fetch->successful()) {
                $this->components->error("Fetching PR #{$prNumber} from its fork failed: ".$fetch->errorOutput());

                return null;
            }
            $this->components->info("PR #{$prNumber} is from a fork; its head is in branch {$branch}");

            return $branch;
        }

        $branch = $data['headRefName'];
        $this->components->info("PR #{$prNumber} branch: {$branch}");

        return $this->fetchBranch($branch) ? $branch : null;
    }

    /**
     * Fetch origin and check that the branch is now local or on origin. Without
     * this, create would cut a new branch with that name from the base.
     */
    private function fetchBranch(string $branch): bool
    {
        $fetch = Process::path(base_path())->timeout(30)->run('git fetch origin');
        if (! $fetch->successful()) {
            $this->components->error('git fetch origin failed: '.$fetch->errorOutput());

            return false;
        }
        foreach (['refs/heads/'.$branch, 'refs/remotes/origin/'.$branch] as $ref) {
            if (Process::path(base_path())->run('git show-ref --verify --quiet '.escapeshellarg($ref))->successful()) {
                return true;
            }
        }
        $this->components->error("Branch {$branch} is neither local nor on origin after the fetch.");

        return false;
    }

    /**
     * @return list<string>
     */
    private function branchesCheckedOutByWorktrees(): array
    {
        $result = Process::path(base_path())->timeout(10)->run(['git', 'worktree', 'list', '--porcelain']);

        if (! $result->successful()) {
            return [];
        }

        $branches = [];
        foreach (explode("\n", $result->output()) as $line) {
            if (str_starts_with($line, 'branch refs/heads/')) {
                $branches[] = substr($line, strlen('branch refs/heads/'));
            }
        }

        return $branches;
    }

    private function slugifyBranch(string $branch): string
    {
        /** @var string $branchPrefix */
        $branchPrefix = config('worktrees.branch_prefix');

        $slug = $branch;
        if ($branchPrefix !== '' && str_starts_with($slug, $branchPrefix)) {
            $slug = substr($slug, strlen($branchPrefix));
        }

        // Lowercase, replace non-alphanumeric with hyphens, collapse, trim
        $slug = Str::lower($slug);
        $slug = (string) preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim($slug, '-');

        return $slug;
    }
}
