<?php

namespace Woda\Worktrees\Shared;

use Closure;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Woda\Worktrees\ComposeProcess;
use Woda\Worktrees\Contracts\TemplateRecipe;

/**
 * Builds a template with the main checkout's code: `php artisan migrate`
 * and then the configured seed command, connected as the template's builder
 * user. Runs either in the main checkout's app container (`container`) or
 * on the host (`host`).
 */
final readonly class ArtisanTemplateRecipe implements TemplateRecipe
{
    /**
     * @param  array<string, string>  $extraEnv
     */
    public function __construct(
        private string $mode,
        private string $appService,
        private string $databaseHost,
        private int $databasePort,
        private array $extraEnv = [],
    ) {}

    public function build(string $database, string $user, string $password, string $seedCommand, ?Closure $output = null): void
    {
        $env = [
            ...$this->extraEnv,
            'DB_HOST' => $this->databaseHost,
            'DB_PORT' => (string) $this->databasePort,
            'DB_DATABASE' => $database,
            'DB_USERNAME' => $user,
            'DB_PASSWORD' => $password,
        ];
        foreach (['php artisan migrate --force', $seedCommand] as $command) {
            if (trim($command) === '') {
                continue;
            }
            $result = $this->process($env)->run($this->command($command, $env), $output);
            if (! $result->successful()) {
                throw new RuntimeException("Template build step '{$command}' failed: {$result->errorOutput()}");
            }
        }
    }

    /**
     * @param  array<string, string>  $env
     */
    private function process(array $env): PendingProcess
    {
        return $this->mode === 'host'
            ? Process::path(MainCheckout::path())->timeout(1800)->env($env)
            : ComposeProcess::in(MainCheckout::path(), 1800);
    }

    /**
     * @param  array<string, string>  $env
     */
    private function command(string $command, array $env): string
    {
        if ($this->mode === 'host') {
            return $command;
        }
        $flags = '';
        foreach ($env as $key => $value) {
            $flags .= ' -e '.escapeshellarg($key.'='.$value);
        }

        return sprintf('docker compose exec -T%s %s sh -c %s', $flags, escapeshellarg($this->appService), escapeshellarg($command));
    }
}
