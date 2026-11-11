<?php

use Illuminate\Support\ServiceProvider;
use Woda\Worktrees\WorktreesServiceProvider;

/*
 * The worktree skill ships with the package and is published with
 * php artisan vendor:publish --tag=worktrees-skill.
 */
it('publishes the skill to .claude/skills/worktree', function () {
    $paths = ServiceProvider::pathsToPublish(WorktreesServiceProvider::class, 'worktrees-skill');

    expect($paths)->toHaveCount(1)
        ->and(array_values($paths)[0])->toBe(base_path('.claude/skills/worktree/SKILL.md'))
        ->and(is_file(array_keys($paths)[0]))->toBeTrue();
});

it('has the frontmatter that agent tools read', function () {
    $skill = (string) file_get_contents(__DIR__.'/../../resources/boost/skills/worktree/SKILL.md');

    expect($skill)->toStartWith("---\nname: worktree\ndescription: ")
        ->and(substr_count($skill, "\n---\n"))->toBeGreaterThanOrEqual(1);
});

it('names only commands the package registers', function () {
    $skill = (string) file_get_contents(__DIR__.'/../../resources/boost/skills/worktree/SKILL.md');
    preg_match_all('/worktree:[a-z-]+/', $skill, $matches);
    $known = array_keys(array_filter(Artisan::all(), fn ($command, string $name) => str_starts_with($name, 'worktree:'), ARRAY_FILTER_USE_BOTH));

    // Commands that other pull requests of issue 25 add; see the PR description.
    $pending = ['worktree:context', 'worktree:status', 'worktree:exec', 'worktree:doctor', 'worktree:services', 'worktree:wake', 'worktree:idle-stop'];
    expect(array_diff(array_unique($matches[0]), $known, $pending))->toBe([]);
});

it('writes -- before the command in every worktree:exec example', function () {
    $skill = (string) file_get_contents(__DIR__.'/../../resources/boost/skills/worktree/SKILL.md');
    preg_match_all('/`php artisan worktree:exec[^`]*`/', $skill, $matches);

    expect($matches[0])->not->toBeEmpty();
    foreach ($matches[0] as $example) {
        expect($example)->toContain(' -- ');
    }
});

it('names --delete-branch and keeps agents away from --force', function () {
    $skill = (string) file_get_contents(__DIR__.'/../../resources/boost/skills/worktree/SKILL.md');

    expect($skill)->toContain('--delete-branch')
        ->and($skill)->toContain('Never add `--force` on your own')
        ->and($skill)->toContain('stops with exit code 1')
        ->and($skill)->not->toContain('(`cd` there first)');
});
