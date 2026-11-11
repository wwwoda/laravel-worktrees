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

    // The commands of PR 26; remove this list once it is merged (see the PR description).
    $pending = ['worktree:context', 'worktree:status', 'worktree:exec', 'worktree:doctor', 'worktree:services'];
    expect(array_diff(array_unique($matches[0]), $known, $pending))->toBe([]);
});

it('writes -- before the command in every worktree:exec example', function () {
    $skill = (string) file_get_contents(__DIR__.'/../../resources/boost/skills/worktree/SKILL.md');
    preg_match_all('/`php artisan (?:-n )?worktree:exec[^`]*`/', $skill, $matches);

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

it('names nothing of the idle stop, which is not in this release', function () {
    $files = [
        __DIR__.'/../../resources/boost/skills/worktree/SKILL.md',
        __DIR__.'/../../README.md',
        __DIR__.'/../../CHANGELOG.md',
    ];
    foreach ($files as $file) {
        $text = (string) file_get_contents($file);
        expect($text)->not->toContain('worktree:wake')
            ->and($text)->not->toContain('worktree:idle-stop')
            ->and($text)->not->toContain('Sablier');
    }
    foreach ([$files[0], $files[2]] as $file) {
        expect((string) file_get_contents($file))->not->toContain('idle stacks')->not->toContain('idle.minutes');
    }
});

it('gives -n to every call, as an agent has no terminal', function () {
    // Without -n and without a terminal, a question would get its default answer.
    $skill = (string) file_get_contents(__DIR__.'/../../resources/boost/skills/worktree/SKILL.md');
    expect($skill)->toContain('php artisan -n worktree:')
        ->and($skill)->not->toContain('php artisan worktree:');
});
