<?php

declare(strict_types=1);

use Pollora\Harness\Check\MutationRun;

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
