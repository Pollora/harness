<?php

declare(strict_types=1);

use Pollora\Harness\Check\MutationRun;
use Pollora\Harness\Check\Plan;
use Pollora\Harness\Check\Step;
use Pollora\Harness\Check\StepResult;
use Pollora\Harness\Config\HarnessConfig;

it('reads the score and the count line of a Pest mutation report', function (): void {
    $report = "  Mutations: 10 untested, 38 tested\n  Score:     79.17%\n  Duration:  0.93s\n";

    expect(MutationRun::parse($report))->toBe(['score' => 79.17, 'summary' => '10 untested, 38 tested']);
});

it('reads a report coloured with ANSI codes', function (): void {
    $report = "\e[2m  Mutations:\e[22m 33 untested, 22 timeout, 369 tested\n\e[2m  Score:\e[22m     92.22%\n";

    expect(MutationRun::parse($report))->toBe(['score' => 92.22, 'summary' => '33 untested, 22 timeout, 369 tested']);
});

it('reads a whole score', function (): void {
    expect(MutationRun::parse("  Mutations: 16 tested\n  Score:     100.00%\n")['score'])->toBe(100.0);
});

it('keeps the last report when Pest prints several', function (): void {
    $output = "  Score:     10.00%\n...\n  Mutations: 1 tested\n  Score:     50.00%\n";

    expect(MutationRun::parse($output)['score'])->toBe(50.0);
});

it('has no score when Pest printed none', function (string $output): void {
    expect(MutationRun::parse($output))->toBe(['score' => null, 'summary' => '']);
})->with([
    'empty' => [''],
    'failing tests' => ["  Tests:    1 failed, 97 passed (242 assertions)\n"],
    'no covers()' => ["   ERROR  Mutation testing requires the usage of the `covers()` function.\n"],
]);

/**
 * A project whose vendor/bin/pest is a script printing what the test asks, so the run is exercised for real.
 *
 * @param  string  $parallel  What the script prints with --parallel
 * @param  string  $single  What it prints without
 */
function projectWithFakePest(string $parallel, string $single): string
{
    $root = harnessTempDir();
    mkdir($root.'/vendor/bin', 0777, true);
    mkdir($root.'/vendor/pestphp/pest-plugin-mutate', 0777, true);
    file_put_contents($root.'/vendor/bin/pest', sprintf(
        "<?php\n\$parallel = in_array('--parallel', \$argv, true);\nfwrite(STDOUT, \$parallel ? %s : %s);\n",
        var_export($parallel, true),
        var_export($single, true),
    ));

    return $root;
}

it('runs Pest with the mutation flags and reads its score', function (): void {
    $root = projectWithFakePest("  Mutations: 3 untested, 9 tested\n  Score:     75.00%\n", 'never used');

    $run = MutationRun::run($root);

    expect($run['score'])->toBe(75.0)
        ->and($run['summary'])->toBe('3 untested, 9 tested');
});

it('repeats the run in a single process when the parallel one prints no score', function (): void {
    $root = projectWithFakePest("  Tests:    1 failed, 4 passed\n", "  Mutations: 1 untested, 3 tested\n  Score:     75.00%\n");

    expect(MutationRun::run($root)['score'])->toBe(75.0);
});

it('has no score when both runs print none', function (): void {
    $root = projectWithFakePest("  ERROR  boom\n", "  ERROR  boom\n");

    $run = MutationRun::run($root);

    expect($run['score'])->toBeNull()
        ->and($run['output'])->toContain('ERROR  boom');
});

it('reports the score of the mutation step and hands it to the ratchet', function (): void {
    $root = projectWithFakePest("  Mutations: 1 untested, 3 tested\n  Score:     75.00%\n", '');
    $steps = (new Plan(HarnessConfig::fromArray(['profile' => 'core']), $root))->full(null, null, true);
    $step = array_values(array_filter($steps, static fn (Step $step): bool => $step->name === Plan::MUTATION))[0];

    $result = ($step->action)();

    expect($result->status)->toBe(StepResult::PASSED)
        ->and($result->detail)->toContain('75')
        ->and($result->detail)->toContain('1 untested, 3 tested');
});

it('fails the mutation step, saying why, when Pest prints no score', function (): void {
    $root = projectWithFakePest("  Tests:    2 failed, 1 passed\n", "  Tests:    2 failed, 1 passed\n");
    $steps = (new Plan(HarnessConfig::fromArray(['profile' => 'core']), $root))->full(null, null, true);
    $step = array_values(array_filter($steps, static fn (Step $step): bool => $step->name === Plan::MUTATION))[0];

    $result = ($step->action)();

    expect($result->status)->toBe(StepResult::FAILED)
        ->and($result->detail)->toContain('the tests must pass first')
        ->and($result->detail)->toContain('2 failed');
});
