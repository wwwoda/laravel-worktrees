<?php

namespace Woda\Worktrees\Tests\Support;

use Closure;
use Woda\Worktrees\Contracts\TemplateRecipe;

final class FakeTemplateRecipe implements TemplateRecipe
{
    /** @var list<string> */
    public array $built = [];

    public function __construct(private readonly FakeDatabaseServer $server) {}

    public function build(string $database, string $user, string $password, string $seedCommand, ?Closure $output = null): void
    {
        $this->built[] = $database;
        $this->server->databases[$database] = ['migrated', 'seeded'];
    }
}
