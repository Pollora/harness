<?php

declare(strict_types=1);

use Pollora\Harness\Config\HarnessConfig;
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

it('finds the Clover report at a conventional location when none is given', function (string $location): void {
    $cwd = harnessTempDir();
    mkdir(dirname($cwd.'/'.$location), 0o755, true);
    copy(fixtureProject().'/coverage.xml', $cwd.'/'.$location);

    $collector = new Collector($cwd);

    expect($collector->findClover())->toBe($location)
        ->and($collector->collect()['coverage'])->toBe(75.0);
})->with(Collector::CLOVER_LOCATIONS);

it('prefers the report it is given over the conventional one', function (): void {
    $cwd = harnessTempDir();
    mkdir($cwd.'/.harness');
    copy(fixtureProject().'/coverage.xml', $cwd.'/.harness/coverage.xml');

    expect((new Collector($cwd))->collect('missing.xml')['coverage'])->toBeNull();
});

it('measures the exempted rules only where allow_rules is used or was recorded', function (): void {
    $exempt = [['rule' => 'ARCH-08', 'reason' => 'published classes'], ['rule' => 'CONV-05', 'reason' => 'legacy']];
    $without = HarnessConfig::fromArray(['profile' => 'core']);

    expect(Collector::exemptedRules($without))->toBeNull()
        ->and(Collector::exemptedRules(HarnessConfig::fromArray(['profile' => 'core', 'allow_rules' => $exempt])))->toBe(2)
        ->and(Collector::exemptedRules(HarnessConfig::fromArray(['profile' => 'core', 'ratchet' => ['exempted_rules' => 1]])))->toBe(0)
        ->and((new Collector(harnessTempDir()))->collect(null, null, [], 2)['exempted_rules'])->toBe(2)
        ->and((new Collector(harnessTempDir()))->collect()['exempted_rules'])->toBeNull();
});
