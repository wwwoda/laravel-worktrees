<?php

namespace Woda\Worktrees\Tests;

use Orchestra\Testbench\TestCase as BaseTestCase;
use Woda\Worktrees\WorktreesServiceProvider;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            WorktreesServiceProvider::class,
        ];
    }
}
