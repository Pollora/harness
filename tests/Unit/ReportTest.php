<?php

declare(strict_types=1);

use Pollora\Harness\Check\Report;
use Pollora\Harness\Check\Step;
use Pollora\Harness\Check\StepResult;
use Pollora\Harness\Config\Mode;
use Pollora\Harness\Console\Io;
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

/**
 * @param  list<array{step: Step, result: StepResult}>  $results
 */
function reportOf(array $results, Mode $mode = Mode::Enforce, bool $strict = false, array $observed = []): Report
{
    return new Report(Profile::Core, $mode, false, $results, $observed, $strict);
}

function semgrepMissing(): array
{
    return ['step' => Step::missingTool('Security patterns', ['SEC-05'], 'semgrep is not installed', 'pipx install semgrep'), 'result' => StepResult::missingTool('semgrep is not installed', 'pipx install semgrep')];
}

function consoleOutput(Report $report): string
{
    $stream = fopen('php://memory', 'w+');
    $report->print(new Io(sys_get_temp_dir(), [], [], $stream, $stream));
    rewind($stream);

    return (string) stream_get_contents($stream);
}

it('does not read a gate that could not run as a pass', function (): void {
    $report = reportOf([['step' => new Step('Code style', ['ARCH-09'], []), 'result' => StepResult::passed()], semgrepMissing()]);
    $output = consoleOutput($report);

    expect($output)->toContain('– no tool')
        ->and($output)->toContain('Security patterns (SEC-05): pipx install semgrep')
        ->and($output)->toContain('Every gate that ran passed, but not every gate ran.')
        ->and($output)->not->toContain('Every gate passed.')
        ->and($report->hasBlockingFailures())->toBeFalse()
        ->and($report->toArray()['steps'][1])->toMatchArray(['status' => 'skipped', 'missing_tool' => true, 'install' => 'pipx install semgrep'])
        ->and($report->markdown())->toContain('not run, tool missing: semgrep is not installed — install: `pipx install semgrep`');
});

it('tells a skipped gate from one whose tool is missing', function (): void {
    $report = reportOf([['step' => Step::skipped('Rector', ['CONV-11'], 'rector.php not found'), 'result' => StepResult::skipped('rector.php not found')]]);

    expect($report->missingTools())->toBe([])
        ->and(consoleOutput($report))->toContain('Every gate passed.')
        ->and($report->toArray()['steps'][0])->not->toHaveKey('missing_tool');
});

it('fails on a missing tool with --strict, in any mode', function (Mode $mode): void {
    expect(reportOf([semgrepMissing()], $mode, strict: true)->hasBlockingFailures())->toBeTrue()
        ->and(reportOf([semgrepMissing()], $mode, strict: false)->hasBlockingFailures())->toBeFalse();
})->with([Mode::Enforce, Mode::Observe]);

it('does not hold a missing tool against a gate the repository only observes', function (): void {
    expect(reportOf([semgrepMissing()], strict: true, observed: ['Security patterns'])->hasBlockingFailures())->toBeFalse();
});

it('says in the summary that a passed gate did not evaluate an exempted rule', function (): void {
    $step = new Step('Tests and architecture', ['ARCH-08', 'ARCH-09'], [], exempted: ['ARCH-08']);
    $report = reportOf([['step' => $step, 'result' => StepResult::passed()], ['step' => new Step('Code style', ['ARCH-09'], []), 'result' => StepResult::passed()]]);

    $lines = array_values(array_filter(explode("\n", consoleOutput($report)), static fn (string $line): bool => str_contains($line, 'Tests and architecture')));

    expect($lines[0])->toContain('passed')->toContain('(1 rule exempted: ARCH-08)')
        ->and(consoleOutput($report))->not->toContain('Code style  ARCH-09 (')
        ->and($report->markdown())->toContain('| Tests and architecture | ARCH-08, ARCH-09 | ✅ passed (1 rule exempted: ARCH-08) |')
        ->and($report->markdown())->toContain('| Code style | ARCH-09 | ✅ passed |')
        ->and($report->toArray()['steps'][0]['exempted'])->toBe(['ARCH-08'])
        ->and($report->toArray()['steps'][1])->not->toHaveKey('exempted');
});
