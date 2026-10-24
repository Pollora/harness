<?php

declare(strict_types=1);

use Pollora\Harness\Metrics\Collector;

it('reads the PHPStan level of the repository', function (): void {
    expect((new Collector(fixtureProject()))->phpstanLevel())->toBe(7);
});

it('counts the errors a baseline hides, using each entry\'s count', function (): void {
    expect((new Collector(fixtureProject()))->phpstanBaselineEntries())->toBe(4);
});

it('counts zero when there is no baseline', function (): void {
    expect((new Collector(harnessTempDir()))->phpstanBaselineEntries())->toBe(0);
});

it('reads the project line coverage of a Clover report, not a file\'s', function (): void {
    expect(Collector::cloverCoverage(fixtureProject().'/coverage.xml'))->toBe(75.0);
});

it('reads the mutation score of an Infection log', function (): void {
    expect(Collector::infectionMsi(fixtureProject().'/infection.json'))->toBe(72.35);
});

it('reports a missing source as not measured rather than as zero', function (): void {
    $metrics = (new Collector(harnessTempDir()))->collect('missing.xml', 'missing.json');

    expect($metrics['coverage'])->toBeNull()
        ->and($metrics['msi'])->toBeNull()
        ->and($metrics['phpstan_level'])->toBeNull();
});
