<?php

namespace Woda\Worktrees\Shared;

use RuntimeException;

/**
 * The exact database and user names of one worktree on the shared server.
 *
 * Every name is derived from the worktree name, so a lost state file can be
 * rebuilt. The guard works on these exact names only, never on patterns: a
 * pattern for `t9` would also cover `t9-1`.
 */
final readonly class WorktreeNames
{
    /**
     * Name endings that would make one worktree's database equal another
     * worktree's test, e2e or parallel test database.
     */
    private const RESERVED_ENDINGS = '/(^|-)(test|e2e)(-\d+)?$|(^|-)test-test-\d+$/';

    /**
     * @param  list<string>  $parallel
     */
    private function __construct(
        public string $worktree,
        public string $app,
        public string $test,
        public string $e2e,
        public array $parallel,
        public string $user,
    ) {}

    public static function for(string $worktree, string $prefix, int $parallelWorkers): self
    {
        if (! preg_match('/^[a-zA-Z0-9-]+$/', $worktree)) {
            throw new RuntimeException('Invalid worktree name.');
        }
        if (! preg_match('/^[a-zA-Z0-9_]+$/', $prefix)) {
            throw new RuntimeException('Invalid database prefix.');
        }
        if (preg_match(self::RESERVED_ENDINGS, strtolower($worktree)) || str_starts_with(strtolower($worktree), 'tpl-')) {
            throw new RuntimeException("The worktree name '{$worktree}' is reserved: its databases could equal another worktree's test, e2e or template databases. Choose another name.");
        }

        $base = $prefix.'_'.strtolower(str_replace('-', '_', $worktree));
        $test = $base.'_test';
        $parallel = [];
        for ($token = 1; $token <= $parallelWorkers; $token++) {
            // Laravel's parallel testing appends `_test_{token}` to the test database.
            $parallel[] = $test.'_test_'.$token;
        }

        $names = new self($worktree, $base, $test, $base.'_e2e', $parallel, $base);
        foreach ($names->databases() as $database) {
            if (strlen($database) > 63) {
                throw new RuntimeException("The worktree name '{$worktree}' is too long: the database name '{$database}' has more than 63 characters.");
            }
        }

        return $names;
    }

    /**
     * @return list<string>
     */
    public function databases(): array
    {
        return [$this->app, $this->test, $this->e2e, ...$this->parallel];
    }

    /**
     * Refuse names that would touch the main database or a template.
     */
    public function assertSafe(string $mainDatabase, string $templatePrefix): void
    {
        foreach ($this->databases() as $database) {
            if (strtolower($database) === strtolower($mainDatabase)) {
                throw new RuntimeException("The worktree '{$this->worktree}' would use the main database '{$mainDatabase}'. Choose another name.");
            }
            if (str_starts_with(strtolower($database), strtolower($templatePrefix))) {
                throw new RuntimeException("The worktree '{$this->worktree}' would use a template database name. Choose another name.");
            }
        }
    }

    public function owns(string $database): bool
    {
        return in_array($database, $this->databases(), true);
    }
}
