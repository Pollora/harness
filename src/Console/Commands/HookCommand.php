<?php

declare(strict_types=1);

namespace Pollora\Harness\Console\Commands;

use Pollora\Harness\Config\HarnessConfig;
use Pollora\Harness\Console\Command;
use Pollora\Harness\Console\Io;
use Pollora\Harness\Support\Paths;

/**
 * The agent hooks (G0): what a coding agent runs before and after each edit,
 * and before it hands back.
 *
 * Wired in .claude/settings.json (see `pollora-qa init`). Each event reads
 * the agent's JSON on stdin. Exit code 2 blocks the action and hands stderr
 * back to the agent, which then fixes what the message names.
 */
final class HookCommand implements Command
{
    /** @var resource */
    private $stdin;

    /**
     * @param  resource|null  $stdin
     */
    public function __construct($stdin = null)
    {
        $this->stdin = $stdin ?? STDIN;
    }

    public function name(): string
    {
        return 'hook';
    }

    public function description(): string
    {
        return 'Agent hooks: pre-edit (protects the harness files), post-edit (style and analysis), stop (fast gates)';
    }

    public function usage(): string
    {
        return "              pre-edit | post-edit | stop    The event, with the agent's JSON on stdin";
    }

    public function run(Io $io): int
    {
        $payload = json_decode((string) stream_get_contents($this->stdin), true);
        $payload = is_array($payload) ? $payload : [];

        return match ($io->arguments[0] ?? '') {
            'pre-edit' => $this->preEdit($io, $payload),
            'post-edit' => $this->postEdit($io, $payload),
            'stop' => $this->stop($io, $payload),
            default => self::SUCCESS,
        };
    }

    /**
     * QA-02: the files that hold the thresholds are changed by a human.
     *
     * @param  array<mixed>  $payload
     */
    private function preEdit(Io $io, array $payload): int
    {
        $file = $this->editedFile($io, $payload);

        if ($file === null) {
            return self::SUCCESS;
        }

        $protected = HarnessConfig::exists($io->cwd) ? HarnessConfig::load($io->cwd)->protected : HarnessConfig::DEFAULT_PROTECTED;

        if (! in_array($file, $protected, true)) {
            return self::SUCCESS;
        }

        $io->error(
            "[QA-02] {$file} is protected: it holds the thresholds the harness enforces, and only a person changes them. ".
            'Fix the code so the check passes instead; if the threshold itself is wrong, stop and explain why to the user.'
        );

        return self::BLOCK;
    }

    /**
     * @param  array<mixed>  $payload
     */
    private function postEdit(Io $io, array $payload): int
    {
        $file = $this->editedFile($io, $payload);

        if ($file === null || ! str_ends_with($file, '.php') || ! is_file($io->cwd.'/'.$file)) {
            return self::SUCCESS;
        }

        // Style is fixed, not reported: the agent should not spend a turn on spaces.
        if (is_file($io->cwd.'/vendor/bin/pint')) {
            $this->exec($io->cwd, ['vendor/bin/pint', '--quiet', '--config', Paths::preset('pint.json'), $file]);
        }

        if (! is_file($io->cwd.'/vendor/bin/phpstan')) {
            return self::SUCCESS;
        }

        [$exitCode, $output] = $this->exec($io->cwd, ['vendor/bin/phpstan', 'analyse', '--no-progress', '--error-format=raw', '--memory-limit=1G', $file]);

        if ($exitCode === 0) {
            return self::SUCCESS;
        }

        $io->error("PHPStan reports problems in {$file}. Fix them before going on:\n".trim($output));

        return self::BLOCK;
    }

    /**
     * @param  array<mixed>  $payload
     */
    private function stop(Io $io, array $payload): int
    {
        // The agent is already continuing because of this hook: let it stop
        // rather than loop. CI still runs every gate on the pull request.
        if (($payload['stop_hook_active'] ?? false) === true || ! HarnessConfig::exists($io->cwd)) {
            return self::SUCCESS;
        }

        [$exitCode, $output] = $this->exec($io->cwd, [PHP_BINARY, Paths::package().'/bin/pollora-qa', 'check', '--fast', '--enforce', '--no-ansi']);

        if ($exitCode === 0) {
            return self::SUCCESS;
        }

        $io->error("The fast gates fail. Fix what they report before handing back:\n".$this->tail($output, 60));

        return self::BLOCK;
    }

    /**
     * The edited file, relative to the repository root.
     *
     * @param  array<mixed>  $payload
     */
    private function editedFile(Io $io, array $payload): ?string
    {
        $input = is_array($payload['tool_input'] ?? null) ? $payload['tool_input'] : [];
        $path = $input['file_path'] ?? $input['path'] ?? null;

        if (! is_string($path) || $path === '') {
            return null;
        }

        $root = rtrim($io->cwd, '/').'/';

        if (str_starts_with($path, $root)) {
            return substr($path, strlen($root));
        }

        return str_starts_with($path, './') ? substr($path, 2) : $path;
    }

    /**
     * @param  list<string>  $command
     * @return array{int, string}
     */
    private function exec(string $cwd, array $command): array
    {
        // @phpstan-ignore argument.type (PHP accepts ['redirect', 1] since 7.4; the stub does not)
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $cwd);

        if (! is_resource($process)) {
            return [1, 'Could not start '.$command[0]];
        }

        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        return [proc_close($process), $output];
    }

    private function tail(string $output, int $lines): string
    {
        return implode("\n", array_slice(explode("\n", rtrim($output)), -$lines));
    }
}
