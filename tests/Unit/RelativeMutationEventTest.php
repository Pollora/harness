<?php

declare(strict_types=1);

use Pollora\Harness\Check\Plan;
use Pollora\Harness\Check\Step;
use Pollora\Harness\Config\HarnessConfig;
use Pollora\Harness\Config\MutationSettings;

/**
 * Which mutation pass the reusable workflow starts, per event: `changed-relative` compares each file of a change with
 * its base, so it only means something on a pull request. The two scripts that decide (the setup job and `Run every
 * gate`) are taken from the workflow as they are and run with bash, against a fake `pollora-qa` that prints what it
 * was called with. The wiring between them (job outputs, then the `env` of the step) is read from the workflow too,
 * as is the part of the GitHub context the step receives: a value is given by its expression, and reaches the script
 * under whatever name the workflow maps it to.
 */
function eventWorkflow(): string
{
    return (string) file_get_contents(dirname(__DIR__, 2).'/.github/workflows/php-package.yml');
}

/**
 * The `run:` block of the step that follows a marker line, without its indentation.
 */
function eventScript(string $marker): string
{
    $rest = substr(eventWorkflow(), (int) strpos(eventWorkflow(), $marker));
    preg_match('/^        run: \|\n((?:(?:          .*)?\n)+)/m', $rest, $block);

    return (string) preg_replace('/^          /m', '', $block[1] ?? '');
}

/**
 * Runs a script of the workflow with bash in a directory.
 *
 * @param  array<string, string>  $environment
 * @return array{int, string} The exit code and everything it printed
 */
function eventBash(string $root, string $script, array $environment): array
{
    file_put_contents($root.'/script.sh', "set -e\nexec 2>&1\n".$script);
    $process = proc_open(['bash', $root.'/script.sh'], [1 => ['pipe', 'w']], $pipes, $root, [...getenv(), ...$environment]);

    if (! is_resource($process)) {
        throw new RuntimeException('bash did not start');
    }

    $output = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    return [proc_close($process), $output];
}

/**
 * A temporary repository with this .harness.json, one commit (or a merge commit, what a pull request checks out) and a
 * fake `pollora-qa`.
 *
 * @param  array<mixed>  $harness  The content of .harness.json
 */
function eventRepository(array $harness, bool $mergeCommit): string
{
    $root = sys_get_temp_dir().'/pollora-harness-'.bin2hex(random_bytes(6));
    mkdir($root.'/vendor/bin', 0o755, true);
    file_put_contents($root.'/.harness.json', json_encode(['profile' => 'core', ...$harness], JSON_THROW_ON_ERROR));
    file_put_contents($root.'/vendor/bin/pollora-qa', "#!/bin/sh\necho \"called: \$*\"\n");
    chmod($root.'/vendor/bin/pollora-qa', 0o755);

    $git = 'git init -q -b main && git config user.email t@example.test && git config user.name T && git config commit.gpgsign false && git add -A && git commit -q -m init';
    $merge = 'git checkout -q -b change && git commit -q --allow-empty -m change && git checkout -q main && git merge -q --no-ff change -m merge';
    [$status, $output] = eventBash($root, $mergeCommit ? $git."\n".$merge : $git, []);

    if ($status !== 0) {
        throw new RuntimeException('git is not usable: '.$output);
    }

    return $root;
}

/**
 * What the quality job does for a repository holding this .harness.json, on this event.
 *
 * @param  array<mixed>  $harness  The content of .harness.json
 * @param  array<string, string>  $context  GitHub context values, keyed by expression (`github.head_ref`)
 * @return array{status: int, flags: string, log: string, summary: string}
 */
function eventGate(array $harness, string $event, bool $mergeCommit = false, string $onPullRequest = 'changed', array $context = []): array
{
    $root = eventRepository($harness, $mergeCommit);

    [$status, $log] = eventBash($root, eventScript('name: Read profile, mode and downstream'), ['GITHUB_OUTPUT' => $root.'/outputs']);
    expect($status)->toBe(0, $log);

    preg_match_all('/^([a-z-]+)=(.*)$/m', (string) file_get_contents($root.'/outputs'), $written, PREG_SET_ORDER);
    $stepOutputs = array_column($written, 2, 1);

    // The job outputs of `setup`, then the environment `Run every gate` reads them through.
    preg_match_all('/^      ([a-z-]+): \$\{\{ steps\.config\.outputs\.([a-z-]+) \}\}$/m', eventWorkflow(), $declared, PREG_SET_ORDER);
    $jobOutputs = [];

    foreach ($declared as $output) {
        $jobOutputs[$output[1] ?? ''] = $stepOutputs[$output[2] ?? ''] ?? '';
    }

    preg_match_all('/^          ([A-Z_]+): \$\{\{ needs\.setup\.outputs\.([a-z-]+) \}\}$/m', eventWorkflow(), $mapped, PREG_SET_ORDER);
    $environment = ['MUTATION_ON_PR' => $onPullRequest, 'GITHUB_STEP_SUMMARY' => $root.'/summary'];

    // The GitHub context the step reads. What a test gives and the step does not read fails the test, and what the step
    // reads and a test does not give is empty, never inherited from the runner of the tests.
    $context = ['github.event_name' => $event, ...$context];
    preg_match_all('/^          ([A-Z_]+): \$\{\{ (github\.[a-z_.]+) \}\}$/m', eventWorkflow(), $read, PREG_SET_ORDER);

    if (array_diff(array_keys($context), array_column($read, 2)) !== []) {
        throw new RuntimeException('Run every gate does not read: '.implode(', ', array_diff(array_keys($context), array_column($read, 2))));
    }

    foreach ($read as $line) {
        $environment[$line[1] ?? ''] = $context[$line[2] ?? ''] ?? '';
    }

    foreach ($mapped as $line) {
        $environment[$line[1] ?? ''] = (string) ($jobOutputs[$line[2] ?? ''] ?? '');
    }

    [$status, $log] = eventBash($root, eventScript('name: Run every gate'), $environment);
    preg_match('/^called: check --coverage=\.harness\/coverage\.xml(.*)$/m', $log, $call);

    return [
        'status' => $status,
        'flags' => trim($call[1] ?? 'NOT CALLED'),
        'log' => $log,
        'summary' => is_file($root.'/summary') ? (string) file_get_contents($root.'/summary') : '',
    ];
}

$relative = ['mutation' => ['scope' => 'changed-relative', 'min' => 80]];

dataset('events that are not a pull request', ['push', 'schedule', 'workflow_dispatch', 'merge_group']);

it('does not run the relative gate outside a pull request, and says so in the log and the job summary', function (string $event) use ($relative): void {
    $gate = eventGate($relative, $event);

    expect($gate['status'])->toBe(0)
        ->and($gate['flags'])->toBe('')
        ->and($gate['log'])->toContain('::notice title=Relative mutation gate not run::')
        ->and($gate['summary'])->toContain("not run on a {$event} event")
        ->and($gate['summary'])->toContain('pull requests only')
        ->and($gate['summary'])->toContain('Nothing was mutated and no file was compared with its base')
        ->and(str_contains($gate['summary'], 'passed'))->toBeFalse();
})->with('events that are not a pull request');

it('mutates the whole repository outside a pull request when a msi floor is recorded, so that it stays checked', function (string $event) use ($relative): void {
    $gate = eventGate([...$relative, 'ratchet' => ['msi' => 75]], $event);

    expect($gate['status'])->toBe(0)
        ->and($gate['flags'])->toBe('--mutation --mutation-scope=all')
        ->and($gate['summary'])->toContain("not run on a {$event} event")
        ->and($gate['summary'])->toContain('mutates the whole repository instead')
        ->and($gate['summary'])->toContain('ratchet.msi')
        ->and(str_contains($gate['summary'], 'passed'))->toBeFalse();
})->with('events that are not a pull request');

it('runs the relative gate on a pull request as before, with or without a msi floor', function (array $ratchet, bool $mergeCommit) use ($relative): void {
    $gate = eventGate([...$relative, ...$ratchet], 'pull_request', $mergeCommit);

    expect($gate['flags'])->toBe('--mutation')
        ->and($gate['summary'])->toBe('')
        ->and(str_contains($gate['log'], 'not run'))->toBeFalse();
})->with([
    'no floor' => [[], true],
    'a floor' => [['ratchet' => ['msi' => 75]], true],
    'not a merge commit' => [[], false],
]);

it('leaves every other repository as it was, on every event', function (array $harness, string $event, string $flags): void {
    $gate = eventGate($harness, $event, true);

    expect($gate['status'])->toBe(0)
        ->and($gate['flags'])->toBe($flags)
        ->and($gate['summary'])->toBe('');
})->with(function (): iterable {
    $limited = static fn (string $scope): array => ['mutation' => ['scope' => $scope, 'min' => 80]];

    foreach (['push', 'schedule', 'workflow_dispatch', 'pull_request'] as $event) {
        yield "no mutation, {$event}" => [[], $event, ''];
        yield "changed, {$event}" => [$limited('changed'), $event, '--mutation'];
        yield "added, {$event}" => [$limited('added'), $event, '--mutation'];
        yield "msi, {$event}" => [['ratchet' => ['msi' => 75]], $event, $event === 'pull_request' ? '--mutation --mutation-scope=changed --mutation-base=HEAD^1' : '--mutation'];
    }
});

it('still refuses an unknown mutation-on-pull-request value, whatever the event', function (string $event) use ($relative): void {
    $gate = eventGate($relative, $event, onPullRequest: 'some');

    expect($gate['status'])->toBe(1)
        ->and($gate['flags'])->toBe('NOT CALLED');
})->with(['push', 'pull_request']);

it('turns the whole pass of that floor into the gate that feeds the msi of the ratchet', function () use ($relative): void {
    $config = HarnessConfig::fromArray(['profile' => 'core', ...$relative, 'ratchet' => ['msi' => 75]]);
    $names = static fn (HarnessConfig $config): array => array_map(
        static fn (Step $step): string => $step->name,
        (new Plan($config, sys_get_temp_dir()))->full(mutation: true),
    );

    expect($names($config))->toContain(Plan::MUTATION_RELATIVE)
        ->and($names($config->withMutation($config->mutation->withScope(MutationSettings::ALL))))->toContain(Plan::MUTATION);
});

/**
 * The context of a pull request in `acme/package`, whose default branch is `main`: by default the release one.
 *
 * @return array<string, string>
 */
function eventPullRequest(string $head = 'develop', string $base = 'main', string $headRepository = 'acme/package'): array
{
    return [
        'github.head_ref' => $head,
        'github.base_ref' => $base,
        'github.event.repository.default_branch' => 'main',
        'github.event.pull_request.head.repo.full_name' => $headRepository,
        'github.repository' => 'acme/package',
    ];
}

it('does not run the relative gate on a release pull request, and says so in the log and the job summary', function () use ($relative): void {
    $gate = eventGate($relative, 'pull_request', true, context: eventPullRequest());

    expect($gate['status'])->toBe(0)
        ->and($gate['flags'])->toBe('')
        ->and($gate['log'])->toContain('::notice title=Relative mutation gate not run::')
        ->and($gate['summary'])->toContain('not run on a release pull request (develop into main')
        ->and($gate['summary'])->toContain('Nothing was mutated and no file was compared with its base')
        ->and(str_contains($gate['summary'], 'ratchet.msi'))->toBeFalse()
        ->and(str_contains($gate['summary'], 'passed'))->toBeFalse();
});

it('mutates nothing on a release pull request even with a msi floor, and says where the floor is compared', function () use ($relative): void {
    $gate = eventGate([...$relative, 'ratchet' => ['msi' => 75]], 'pull_request', true, context: eventPullRequest());

    expect($gate['status'])->toBe(0)
        ->and($gate['flags'])->toBe('')
        ->and($gate['summary'])->toContain('Nothing was mutated')
        ->and($gate['summary'])->toContain('does not compare it here')
        ->and($gate['summary'])->toContain('The push to main after the merge mutates the whole repository')
        ->and(str_contains($gate['summary'], 'passed'))->toBeFalse();
});

it('keeps the gate on every pull request that is not a release one', function (string $head, string $base, string $headRepository) use ($relative): void {
    $gate = eventGate($relative, 'pull_request', true, context: eventPullRequest($head, $base, $headRepository));

    expect($gate['status'])->toBe(0)
        ->and($gate['flags'])->toBe('--mutation')
        ->and($gate['summary'])->toBe('')
        ->and(str_contains($gate['log'], 'not run'))->toBeFalse();
})->with([
    'a fork with a branch named develop' => ['develop', 'main', 'someone/package'],
    'a deleted fork' => ['develop', 'main', ''],
    'a feature into develop' => ['feature/x', 'develop', 'acme/package'],
    'a feature into main' => ['feature/x', 'main', 'acme/package'],
    'develop into a branch that is not the default one' => ['develop', 'release', 'acme/package'],
    'a branch that only starts like develop' => ['develop-2', 'main', 'acme/package'],
    'a branch name that is shell code' => ['develop";exit${IFS}3;"', 'main', 'acme/package'],
]);

it('leaves the other scopes as they were on a release pull request', function (array $harness, string $flags): void {
    $gate = eventGate($harness, 'pull_request', true, context: eventPullRequest());

    expect($gate['flags'])->toBe($flags)
        ->and($gate['summary'])->toBe('');
})->with([
    'changed' => [['mutation' => ['scope' => 'changed', 'min' => 80]], '--mutation'],
    'added' => [['mutation' => ['scope' => 'added', 'min' => 80]], '--mutation'],
    'msi' => [['ratchet' => ['msi' => 75]], '--mutation --mutation-scope=changed --mutation-base=HEAD^1'],
]);

it('never writes a GitHub context value into the script of the step', function (): void {
    expect(str_contains(eventScript('name: Run every gate'), '${{'))->toBeFalse();
});
