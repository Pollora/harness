<?php

declare(strict_types=1);

use Pollora\Harness\Config\Ratchet;

it('reads "max" as the highest PHPStan level and writes it back as "max"', function (): void {
    $ratchet = Ratchet::fromArray(['phpstan_level' => 'max']);

    expect($ratchet->get('phpstan_level'))->toBe(10)
        ->and($ratchet->toArray())->toBe(['phpstan_level' => 'max']);
});

it('judges each metric in its own direction', function (string $metric, int|float $recorded, int|float $measured, string $verdict): void {
    $rows = (new Ratchet([$metric => $recorded]))->compare([$metric => $measured]);
    $row = array_values(array_filter($rows, fn (array $row): bool => $row['metric'] === $metric))[0];

    expect($row['verdict'])->toBe($verdict);
})->with([
    'a higher level improves' => ['phpstan_level', 5, 6, 'improved'],
    'a lower level regresses' => ['phpstan_level', 6, 5, 'regressed'],
    'a smaller baseline improves' => ['phpstan_baseline', 36, 30, 'improved'],
    'a bigger baseline regresses' => ['phpstan_baseline', 30, 31, 'regressed'],
    'less coverage regresses' => ['coverage', 71.4, 70.0, 'regressed'],
    'rounding noise is unchanged' => ['coverage', 71.40, 71.36, 'unchanged'],
]);

it('marks a metric never recorded as new and one not measured as unmeasured', function (): void {
    $rows = (new Ratchet(['msi' => 60.0]))->compare(['coverage' => 80.0]);
    $verdicts = array_column($rows, 'verdict', 'metric');

    expect($verdicts['coverage'])->toBe('new')
        ->and($verdicts['msi'])->toBe('unmeasured');
});

it('only ever tightens', function (): void {
    $ratchet = (new Ratchet(['phpstan_level' => 5, 'phpstan_baseline' => 36, 'coverage' => 70.0]))
        ->tighten(['phpstan_level' => 6, 'phpstan_baseline' => 40, 'coverage' => 72.5, 'msi' => 55.0]);

    expect($ratchet->toArray())->toBe([
        'phpstan_level' => 6,
        'phpstan_baseline' => 36,
        'coverage' => 72.5,
        'msi' => 55.0,
    ]);
});

it('treats a growing number of exempted rules as a regression and a smaller one as an improvement', function (string $metric, int $recorded, int $measured, string $verdict): void {
    $row = array_values(array_filter(
        (new Ratchet([$metric => $recorded]))->compare([$metric => $measured]),
        static fn (array $row): bool => $row['metric'] === $metric,
    ))[0];

    expect($row['verdict'])->toBe($verdict);
})->with([
    'one more exemption regresses' => ['exempted_rules', 1, 2, 'regressed'],
    'one less improves' => ['exempted_rules', 2, 1, 'improved'],
    'the same is unchanged' => ['exempted_rules', 1, 1, 'unchanged'],
]);

it('lowers the ceiling of exempted rules and never raises it', function (): void {
    expect((new Ratchet(['exempted_rules' => 2]))->tighten(['exempted_rules' => 1])->toArray())->toBe(['exempted_rules' => 1])
        ->and((new Ratchet(['exempted_rules' => 1]))->tighten(['exempted_rules' => 3])->toArray())->toBe(['exempted_rules' => 1]);
});

it('does not check exempted rules when no ceiling was recorded', function (): void {
    $verdicts = array_column((new Ratchet(['phpstan_level' => 5]))->compare(['phpstan_level' => 5, 'exempted_rules' => null]), 'verdict', 'metric');

    expect($verdicts['exempted_rules'])->toBe('unmeasured');
});
