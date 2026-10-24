<?php

declare(strict_types=1);

use Pollora\Harness\Console\Command;
use Pollora\Harness\Console\Commands\HookCommand;
use Pollora\Harness\Console\Io;

/**
 * @return array{int, string}
 */
function runHook(string $event, array $payload, string $cwd): array
{
    $stdin = fopen('php://memory', 'r+');
    fwrite($stdin, json_encode($payload));
    rewind($stdin);
    $stdout = fopen('php://memory', 'r+');
    $stderr = fopen('php://memory', 'r+');

    $code = (new HookCommand($stdin))->run(new Io($cwd, [$event], [], $stdout, $stderr));
    rewind($stderr);

    return [$code, (string) stream_get_contents($stderr)];
}

it('stops an agent from editing a file that holds the thresholds (QA-02)', function (string $path): void {
    $cwd = harnessTempDir();

    [$code, $stderr] = runHook('pre-edit', ['tool_input' => ['file_path' => $path === 'absolute' ? $cwd.'/.harness.json' : $path]], $cwd);

    expect($code)->toBe(Command::BLOCK)
        ->and($stderr)->toContain('[QA-02]');
})->with([
    'absolute path' => ['absolute'],
    'relative path' => ['phpstan-baseline.neon'],
    'dot-relative path' => ['./api-surface.json'],
]);

it('lets an agent edit the code', function (): void {
    [$code] = runHook('pre-edit', ['tool_input' => ['file_path' => 'src/Domain/Order.php']], harnessTempDir());

    expect($code)->toBe(Command::SUCCESS);
});

it('lets the agent stop when the stop hook already sent it back once', function (): void {
    $cwd = harnessTempDir();
    file_put_contents($cwd.'/.harness.json', '{"profile": "core"}');

    [$code] = runHook('stop', ['stop_hook_active' => true], $cwd);

    expect($code)->toBe(Command::SUCCESS);
});

it('ignores an event it does not know', function (): void {
    [$code] = runHook('session-start', [], harnessTempDir());

    expect($code)->toBe(Command::SUCCESS);
});
