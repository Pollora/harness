<?php

declare(strict_types=1);

use Pollora\Harness\Check\Runner;
use Pollora\Harness\Check\Step;
use Pollora\Harness\Check\StepResult;
use Pollora\Harness\Console\Command;
use Pollora\Harness\Console\Commands\HookCommand;
use Pollora\Harness\Console\Io;

afterEach(function (): void {
    putenv('GITHUB_ACTIONS');
});

/**
 * Runs the steps with a runner whose output is captured.
 *
 * @param  list<Step>  $steps
 * @return array{list<array{step: Step, result: StepResult}>, string}
 */
function covRunSteps(array $steps, string $cwd): array
{
    $stdout = fopen('php://memory', 'w+');
    $results = (new Runner(new Io($cwd, [], [], $stdout, $stdout), $cwd))->run($steps);
    rewind($stdout);

    return [$results, (string) stream_get_contents($stdout)];
}

/**
 * Writes an executable script into the temporary repository.
 */
function covTool(string $cwd, string $path, string $body): void
{
    is_dir(dirname($cwd.'/'.$path)) || mkdir(dirname($cwd.'/'.$path), 0o755, true);
    file_put_contents($cwd.'/'.$path, "#!/bin/sh\n".$body."\n");
    chmod($cwd.'/'.$path, 0o755);
}

/**
 * @param  array<string, mixed>  $payload
 * @return array{int, string}
 */
function covHook(string $event, array $payload, string $cwd): array
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

it('passes a step whose command exits with 0 and shows its output', function (): void {
    [$results, $out] = covRunSteps([new Step('Echo', ['R-1'], ['sh', '-c', 'echo hello world'])], harnessTempDir());

    expect($results[0]['result']->status)->toBe(StepResult::PASSED)
        ->and($out)->toContain('▸ Echo')->toContain('hello world')->toContain('passed');
});

it('fails a step whose command exits with a code, and keeps the last 30 lines of its output', function (): void {
    [$results, $out] = covRunSteps([new Step('Seq', ['R-1'], ['sh', '-c', 'seq 1 100; exit 3'])], harnessTempDir());
    $detail = $results[0]['result']->detail;

    expect($results[0]['result']->isFailed())->toBeTrue()
        ->and($detail)->toContain('100')->toContain('71')->not->toContain("\n70\n")
        ->and(substr_count($detail, "\n") + 1)->toBe(30)
        ->and($out)->toContain('failed');
});

it('prints the command with quoted arguments before it runs', function (): void {
    [, $out] = covRunSteps([new Step('Quote', ['R-1'], ['sh', '-c', 'true # a b'])], harnessTempDir());

    expect($out)->toContain("\$ sh -c 'true # a b'");
});

it('says why a step is skipped, and when a tool is missing', function (): void {
    [$results, $out] = covRunSteps([
        Step::skipped('Skipped', ['R-1'], 'nothing changed'),
        Step::missingTool('Absent', ['R-2'], 'no semgrep', 'pipx install semgrep'),
    ], harnessTempDir());

    expect($results[0]['result']->isMissingTool())->toBeFalse()
        ->and($results[1]['result']->isMissingTool())->toBeTrue()
        ->and($out)->toContain('skipped: nothing changed')->toContain('not run, tool missing: no semgrep (pipx install semgrep)');
});

it('skips a step whose required file is missing, and reports a missing vendor binary as a missing tool', function (): void {
    $cwd = harnessTempDir();

    [$results] = covRunSteps([
        new Step('Config', ['R-1'], ['true'], requires: ['rector.php']),
        new Step('Pint', ['R-2'], ['true'], requires: ['vendor/bin/pint']),
        new Step('Phpstan', ['R-3'], ['true'], requires: ['vendor/bin/phpstan']),
        new Step('Rector', ['R-4'], ['true'], requires: ['vendor/bin/rector']),
        new Step('Pest', ['R-5'], ['true'], requires: ['vendor/bin/pest']),
        new Step('Analyser', ['R-6'], ['true'], requires: ['vendor/bin/composer-dependency-analyser']),
        new Step('Other', ['R-7'], ['true'], requires: ['vendor/bin/other']),
    ], $cwd);

    expect($results[0]['result']->status)->toBe(StepResult::SKIPPED)->and($results[0]['result']->isMissingTool())->toBeFalse()
        ->and($results[1]['result']->install)->toBe('composer require --dev laravel/pint')
        ->and($results[2]['result']->install)->toBe('composer require --dev phpstan/phpstan')
        ->and($results[3]['result']->install)->toBe('composer require --dev rector/rector')
        ->and($results[4]['result']->install)->toBe('composer require --dev pestphp/pest')
        ->and($results[5]['result']->install)->toBe('composer require --dev shipmonk/composer-dependency-analyser')
        ->and($results[6]['result']->install)->toBe('composer install');
});

it('runs the command of a step in the repository once its required files exist', function (): void {
    $cwd = harnessTempDir();
    file_put_contents($cwd.'/rector.php', '<?php');

    [$results, $out] = covRunSteps([new Step('Here', ['R-1'], ['sh', '-c', 'pwd -P; test -f rector.php'], requires: ['rector.php'])], $cwd);

    expect($results[0]['result']->status)->toBe(StepResult::PASSED)
        ->and($out)->toContain(realpath($cwd));
});

it('runs an in-process step and prints its detail', function (): void {
    [$results, $out] = covRunSteps([
        new Step('Inline', ['R-1'], static fn (): StepResult => StepResult::passed('all fine here')),
        new Step('Quiet', ['R-2'], static fn (): StepResult => StepResult::failed('')),
    ], harnessTempDir());

    expect($results[0]['result']->status)->toBe(StepResult::PASSED)
        ->and($results[1]['result']->isFailed())->toBeTrue()
        ->and($out)->toContain('all fine here');
});

it('prints the notes of a step before it runs', function (): void {
    [, $out] = covRunSteps([new Step('Noted', ['R-1'], ['true'], note: 'running it a second way')], harnessTempDir());

    expect($out)->toContain('running it a second way');
});

it('writes a GitHub annotation for a failed step, with the control characters escaped', function (): void {
    putenv('GITHUB_ACTIONS=true');

    [, $out] = covRunSteps([new Step('Annotated', ['R-1', 'R-2'], ['sh', '-c', 'printf "100%% bad\n\033[31mred\033[0m\n"; exit 1'])], harnessTempDir());

    expect($out)->toContain('::error title=Pollora harness · Annotated (R-1 R-2)::100%25 bad%0Ared');
});

it('writes no annotation outside GitHub Actions', function (): void {
    putenv('GITHUB_ACTIONS');

    [, $out] = covRunSteps([new Step('Plain', ['R-1'], ['sh', '-c', 'exit 1'])], harnessTempDir());

    expect($out)->not->toContain('::error');
});

it('fixes the style of the edited file with the repository Pint, and passes when PHPStan is absent', function (): void {
    $cwd = harnessTempDir();
    covTool($cwd, 'vendor/bin/pint', 'echo "$@" > pint.args');
    file_put_contents($cwd.'/Thing.php', "<?php\n");

    [$code] = covHook('post-edit', ['tool_input' => ['file_path' => $cwd.'/Thing.php']], $cwd);

    expect($code)->toBe(Command::SUCCESS)
        ->and((string) file_get_contents($cwd.'/pint.args'))->toContain('--quiet')->toContain('Thing.php');
});

it('blocks the agent with the PHPStan errors of the edited file', function (): void {
    $cwd = harnessTempDir();
    covTool($cwd, 'vendor/bin/phpstan', 'echo "Thing.php:3:Undefined variable"; exit 1');
    file_put_contents($cwd.'/Thing.php', "<?php\n");

    [$code, $stderr] = covHook('post-edit', ['tool_input' => ['file_path' => 'Thing.php']], $cwd);

    expect($code)->toBe(Command::BLOCK)
        ->and($stderr)->toContain('PHPStan reports problems in Thing.php')->toContain('Undefined variable');
});

it('lets the agent go on when PHPStan is happy with the edited file', function (): void {
    $cwd = harnessTempDir();
    covTool($cwd, 'vendor/bin/phpstan', 'exit 0');
    file_put_contents($cwd.'/Thing.php', "<?php\n");

    [$code, $stderr] = covHook('post-edit', ['tool_input' => ['path' => 'Thing.php']], $cwd);

    expect($code)->toBe(Command::SUCCESS)->and($stderr)->toBe('');
});

it('ignores edits that are not an existing PHP file, or that name no file', function (array $payload): void {
    $cwd = harnessTempDir();
    covTool($cwd, 'vendor/bin/phpstan', 'exit 1');
    file_put_contents($cwd.'/notes.md', '# notes');

    expect(covHook('post-edit', $payload, $cwd)[0])->toBe(Command::SUCCESS);
})->with([
    'a markdown file' => [['tool_input' => ['file_path' => 'notes.md']]],
    'a php file that does not exist' => [['tool_input' => ['file_path' => 'gone.php']]],
    'no path' => [['tool_input' => []]],
    'an empty path' => [['tool_input' => ['file_path' => '']]],
    'no tool input' => [[]],
]);

it('reads the protected files of .harness.json, not the default ones, once it exists', function (): void {
    $cwd = harnessTempDir();
    file_put_contents($cwd.'/.harness.json', json_encode(['profile' => 'core', 'protected' => ['secret.json']]));

    expect(covHook('pre-edit', ['tool_input' => ['file_path' => 'secret.json']], $cwd)[0])->toBe(Command::BLOCK)
        ->and(covHook('pre-edit', ['tool_input' => ['file_path' => 'phpstan-baseline.neon']], $cwd)[0])->toBe(Command::SUCCESS);
});

it('does nothing for an unknown event or a payload that is not JSON', function (): void {
    $stdin = fopen('php://memory', 'r+');
    fwrite($stdin, 'not json');
    rewind($stdin);
    $stream = fopen('php://memory', 'r+');

    expect((new HookCommand($stdin))->run(new Io(harnessTempDir(), ['whatever'], [], $stream, $stream)))->toBe(Command::SUCCESS)
        ->and((new HookCommand)->name())->toBe('hook');
});

it('lets the agent stop when the hook already continued it, or when there is no .harness.json', function (): void {
    $cwd = harnessTempDir();

    expect(covHook('stop', [], $cwd)[0])->toBe(Command::SUCCESS);

    file_put_contents($cwd.'/.harness.json', json_encode(['profile' => 'core', 'mode' => 'enforce']));
    file_put_contents($cwd.'/composer.json', '{"require": {"laravel/framework": "^13.0"}}');

    expect(covHook('stop', ['stop_hook_active' => true], $cwd)[0])->toBe(Command::SUCCESS);
});

it('blocks the agent from stopping while a fast gate fails, and lets it stop once they pass', function (): void {
    $cwd = harnessTempDir();
    file_put_contents($cwd.'/.harness.json', json_encode(['profile' => 'core', 'mode' => 'enforce']));
    file_put_contents($cwd.'/composer.json', '{"require": {"laravel/framework": "^13.0"}}');

    [$blocked, $stderr] = covHook('stop', [], $cwd);

    expect($blocked)->toBe(Command::BLOCK)
        ->and($stderr)->toContain('The fast gates fail')->toContain('Dependency policy');

    file_put_contents($cwd.'/composer.json', '{"require": {"php": "^8.3"}}');

    expect(covHook('stop', [], $cwd)[0])->toBe(Command::SUCCESS);
});
