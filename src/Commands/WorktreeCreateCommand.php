<?php

namespace Woda\Worktrees\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
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
        {--skip-db : Skip database cloning}';

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
            [$name, $branch] = $this->interactive();
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
            $name ??= $this->slugifyBranch($resolved);
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

        $worktreeManager->bootstrap($name, [
            'skip_deps' => (bool) $this->option('skip-deps'),
            'skip_build' => (bool) $this->option('skip-build'),
            'skip_db' => (bool) $this->option('skip-db'),
        ], $onStep, $processOutput);

        $this->components->info("Worktree '{$name}' is ready.");

        return self::SUCCESS;
    }

    /**
     * @return array{string, string|null}
     */
    private function interactive(): array
    {
        $mode = $this->promptMode();

        [$defaultName, $branch] = match ($mode) {
            'issue' => $this->promptIssue(),
            'pr' => $this->promptPullRequest(),
            'branch' => $this->promptBranch(),
            'fresh' => ['', null],
        };

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
     * @return array{string, string|null}
     */
    private function promptIssue(): array
    {
        $result = Process::run('gh issue list --state open --limit 100 --json number,title');

        if (! $result->successful()) {
            $this->components->error('Failed to fetch issues: '.$result->errorOutput());

            return ['', null];
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

        $branch = $this->resolveIssueBranch($issueNumber);
        $defaultName = (string) $issueNumber;

        return [$defaultName, $branch];
    }

    /**
     * @return array{string, string|null}
     */
    private function promptPullRequest(): array
    {
        $result = Process::run('gh pr list --state open --limit 100 --json number,title,headRefName');

        if (! $result->successful()) {
            $this->components->error('Failed to fetch PRs: '.$result->errorOutput());

            return ['', null];
        }

        /** @var list<array{number: int, title: string, headRefName: string}> $prs */
        $prs = json_decode($result->output(), true) ?: [];

        if ($prs === []) {
            $this->components->warn('No open pull requests found.');

            return ['', null];
        }

        $options = [];
        $prData = [];
        foreach ($prs as $pr) {
            $options[$pr['number']] = "#{$pr['number']} {$pr['title']}";
            $prData[$pr['number']] = $pr['headRefName'];
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

        $branch = $prData[$prNumber];
        $defaultName = $this->slugifyBranch($branch);

        return [$defaultName, $branch];
    }

    /**
     * @return array{string, string|null}
     */
    private function promptBranch(): array
    {
        $result = Process::path(base_path())->run(['git', 'branch', '-a', '--format=%(refname:short)']);

        if (! $result->successful()) {
            $this->components->error('Failed to list branches: '.$result->errorOutput());

            return ['', null];
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
            hint: 'Alphanumeric and hyphens only.',
        );
    }

    private function resolveIssueBranch(int $issueNumber): ?string
    {
        // Check for existing linked branches
        $result = Process::run(
            sprintf('gh issue develop --list %d', $issueNumber),
        );

        if (! $result->successful()) {
            $this->components->error("Failed to resolve issue #{$issueNumber}: ".$result->errorOutput());

            return null;
        }

        $branches = array_filter(array_map(trim(...), explode("\n", trim($result->output()))));

        if ($branches !== []) {
            $branch = $branches[0];
            $this->components->info("Found linked branch: {$branch}");

            // Ensure we have the remote branch locally
            Process::path(base_path())->timeout(30)->run('git fetch origin');

            return $branch;
        }

        // No linked branch — create one via gh issue develop
        /** @var string $baseBranch */
        $baseBranch = $this->option('base') ?? config('worktrees.base_branch');

        $result = Process::run(
            sprintf('gh issue develop %d --base %s', $issueNumber, escapeshellarg($baseBranch)),
        );

        if (! $result->successful()) {
            $this->components->error("Failed to create branch for issue #{$issueNumber}: ".$result->errorOutput());

            return null;
        }

        // gh issue develop outputs the branch name
        $branch = trim($result->output());

        if ($branch === '') {
            $this->components->error("gh issue develop returned empty branch name for #{$issueNumber}.");

            return null;
        }

        $this->components->info("Created linked branch: {$branch}");

        // Fetch so the branch is available locally
        Process::path(base_path())->timeout(30)->run('git fetch origin');

        return $branch;
    }

    private function resolvePrBranch(int $prNumber): ?string
    {
        $result = Process::run(
            sprintf('gh pr view %d --json headRefName', $prNumber),
        );

        if (! $result->successful()) {
            $this->components->error("Failed to resolve PR #{$prNumber}: ".$result->errorOutput());

            return null;
        }

        /** @var array{headRefName: string}|null $data */
        $data = json_decode($result->output(), true);

        if (! $data || empty($data['headRefName'])) {
            $this->components->error("Could not determine branch for PR #{$prNumber}.");

            return null;
        }

        $branch = $data['headRefName'];
        $this->components->info("PR #{$prNumber} branch: {$branch}");

        // Ensure we have the remote branch locally
        Process::path(base_path())->timeout(30)->run('git fetch origin');

        return $branch;
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
