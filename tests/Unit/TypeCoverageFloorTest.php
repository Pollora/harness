<?php

declare(strict_types=1);

use Pollora\Harness\Check\Plan;
use Pollora\Harness\Check\Report;
use Pollora\Harness\Check\Step;
use Pollora\Harness\Check\StepResult;
use Pollora\Harness\Config\HarnessConfig;
use Pollora\Harness\Config\InvalidConfig;
use Pollora\Harness\Config\Mode;
use Pollora\Harness\Config\Ratchet;
use Pollora\Harness\Console\Commands\RatchetCommand;
use Pollora\Harness\Metrics\TypeCoverage;
use Pollora\Harness\Profile;

/**
 * A repository that adopts the type_coverage floor: one typed and two untyped functions (33.33 % typed).
 *
 * @param  array<string, mixed>  $config  Merged into .harness.json
 */
function typeFloorProject(array $config = []): string
{
    $cwd = harnessTempDir();
    mkdir($cwd.'/src', 0o755, true);
    file_put_contents($cwd.'/src/Untyped.php', '<?php function a($x) {} function b($y) {}');
    file_put_contents($cwd.'/src/Typed.php', '<?php function c(int $z): void {}');
    file_put_contents($cwd.'/.harness.json', json_encode(['profile' => 'core'] + $config));

    return $cwd;
}

/**
 * Runs the Ratchet gate of `check` and the report it feeds, in the given mode.
 *
 * @param  array<string, mixed>  $config
 * @return array{StepResult, bool} The gate's result, and whether the run is blocked
 */
function typeFloorCheck(string $cwd, array $config, Mode $mode): array
{
    $plan = new Plan(HarnessConfig::fromArray($config), $cwd);
    $step = array_values(array_filter($plan->full(), static fn (Step $step): bool => $step->name === 'Ratchet'))[0];
    $result = ($step->action)();

    return [$result, (new Report(Profile::Core, $mode, false, [['step' => $step, 'result' => $result]]))->hasBlockingFailures()];
}

it('records the type coverage floor with ratchet --update, and reads it back', function (): void {
    $cwd = typeFloorProject();

    [$code, $out] = harnessRun(new RatchetCommand, $cwd, '--update');
    $saved = json_decode((string) file_get_contents($cwd.'/.harness.json'), true);

    expect($code)->toBe(0)
        ->and($out)->toContain('Type coverage (%)')->toContain('new')->toContain('.harness.json tightened.')
        ->and($saved['ratchet']['type_coverage'])->toBe(33.33)
        ->and(HarnessConfig::load($cwd)->ratchet->get('type_coverage'))->toBe(33.33);
});

it('never lowers the floor and raises it when the code gets better', function (): void {
    $cwd = typeFloorProject(['ratchet' => ['type_coverage' => 90.0]]);

    [$code, $out] = harnessRun(new RatchetCommand, $cwd, '--update');

    expect($code)->toBe(1)
        ->and($out)->toContain('regressed')
        ->and(HarnessConfig::load($cwd)->ratchet->get('type_coverage'))->toEqual(90);

    file_put_contents($cwd.'/src/Untyped.php', '<?php function a(int $x): void {} function b(int $y): void {}');
    harnessRun(new RatchetCommand, $cwd, '--update');

    expect(HarnessConfig::load($cwd)->ratchet->get('type_coverage'))->toEqual(100);
});

it('tolerates 0.05 points of rounding noise on the floor and no more', function (): void {
    $cwd = typeFloorProject();
    $measured = TypeCoverage::measure($cwd)->percentage();
    $verdict = static fn (float $floor): string => (new Ratchet(['type_coverage' => $floor]))->compare(['type_coverage' => $measured])[3]['verdict'];

    expect(Ratchet::TOLERANCE)->toBe(0.05)
        ->and($verdict($measured))->toBe('unchanged')
        ->and($verdict($measured + 0.04))->toBe('unchanged')
        ->and($verdict($measured + 0.06))->toBe('regressed')
        ->and($verdict($measured - 0.06))->toBe('improved');
});

it('fails the check in enforce mode when the type coverage drops below the floor, and only warns in observe mode', function (): void {
    $cwd = typeFloorProject();
    $config = ['profile' => 'core', 'ratchet' => ['type_coverage' => 90.0]];

    [$result, $enforced] = typeFloorCheck($cwd, $config, Mode::Enforce);
    [, $observed] = typeFloorCheck($cwd, $config, Mode::Observe);

    expect($result->isFailed())->toBeTrue()
        ->and($result->detail)->toContain('Type coverage (%)')->toContain('recorded 90')->toContain('regressed')
        ->and($enforced)->toBeTrue()
        ->and($observed)->toBeFalse();
});

it('passes the check while the type coverage holds the floor', function (): void {
    $cwd = typeFloorProject();

    [$result, $blocked] = typeFloorCheck($cwd, ['profile' => 'core', 'ratchet' => ['type_coverage' => 33.33]], Mode::Enforce);

    expect($result->status)->toBe(StepResult::PASSED)->and($blocked)->toBeFalse();
});

it('refuses a type_coverage floor outside 0 to 100, and a value that is not a number', function (mixed $value): void {
    expect(fn () => HarnessConfig::fromArray(['profile' => 'core', 'ratchet' => ['type_coverage' => $value]], '.harness.json'))
        ->toThrow(InvalidConfig::class, 'ratchet.type_coverage');
})->with([101, 100.5, -1, '95', true, [[95]]]);

it('says what a valid floor looks like', function (): void {
    expect(fn () => HarnessConfig::fromArray(['profile' => 'core', 'ratchet' => ['type_coverage' => 120]], 'x/.harness.json'))
        ->toThrow(InvalidConfig::class, 'x/.harness.json: ratchet.type_coverage is 120. Give a number from 0 to 100 (a percentage).');
});

it('accepts the bounds, and the keys the ratchet could already hold', function (): void {
    $config = HarnessConfig::fromArray(['profile' => 'core', 'ratchet' => [
        'type_coverage' => 0, 'coverage' => 100, 'msi' => 55.5, 'phpstan_level' => 'max', 'phpstan_baseline' => 0, 'exempted_rules' => 2,
    ]]);

    expect($config->ratchet->get('type_coverage'))->toBe(0)->and($config->ratchet->get('phpstan_level'))->toBe(10);
});

it('refuses the other ratchet values the ratchet would silently ignore', function (string $metric, mixed $value): void {
    expect(fn () => HarnessConfig::fromArray(['profile' => 'core', 'ratchet' => [$metric => $value]]))
        ->toThrow(InvalidConfig::class, "ratchet.{$metric}");
})->with([
    ['coverage', 101],
    ['msi', -0.5],
    ['phpstan_level', 11],
    ['phpstan_level', '8'],
    ['phpstan_baseline', -1],
    ['phpstan_baseline', 1.5],
    ['exempted_rules', 'two'],
]);
