<?php

declare(strict_types=1);

use Pollora\Harness\Check\Report;
use Pollora\Harness\Check\Step;
use Pollora\Harness\Check\StepResult;
use Pollora\Harness\Config\Mode;
use Pollora\Harness\Profile;

/**
 * @param  list<string>  $failed  Names of the gates that failed
 * @param  list<string>  $observed
 */
function reportWhere(array $failed, Mode $mode, array $observed = []): Report
{
    $results = array_map(
        static fn (string $name): array => ['step' => new Step($name, ['ARCH-09'], []), 'result' => StepResult::failed('x')],
        $failed,
    );

    return new Report(Profile::Core, $mode, false, $results, $observed);
}

it('blocks on a failed gate in enforce mode', function (): void {
    expect(reportWhere(['Static analysis'], Mode::Enforce)->hasBlockingFailures())->toBeTrue();
});

it('never blocks in observe mode', function (): void {
    expect(reportWhere(['Static analysis'], Mode::Observe)->hasBlockingFailures())->toBeFalse();
});

it('does not block on a gate the repository only observes, in enforce mode', function (): void {
    expect(reportWhere(['Rector'], Mode::Enforce, ['Rector'])->hasBlockingFailures())->toBeFalse()
        ->and(reportWhere(['Rector', 'Static analysis'], Mode::Enforce, ['Rector'])->hasBlockingFailures())->toBeTrue()
        ->and(reportWhere(['Rector'], Mode::Enforce, ['Rector'])->hasFailures())->toBeTrue();
});

it('reports an observed gate as observed in the job summary', function (): void {
    expect(reportWhere(['Rector', 'Static analysis'], Mode::Enforce, ['Rector'])->markdown())
        ->toContain('| Rector | ARCH-09 | ⚠️ failed (observed) |')
        ->toContain('| Static analysis | ARCH-09 | ❌ failed |');
});
