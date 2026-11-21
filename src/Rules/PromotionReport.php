<?php

declare(strict_types=1);

namespace Pollora\Harness\Rules;

use Pollora\Harness\Architecture\ClassExemptions;
use Pollora\Harness\Architecture\FacadeClasses;
use Pollora\Harness\Architecture\FinalClasses;
use Pollora\Harness\Architecture\Layers;
use Pollora\Harness\Architecture\RawRegistrationArrays;
use Pollora\Harness\Architecture\TestClasses;
use Pollora\Harness\Config\HarnessConfig;
use Pollora\Harness\Config\PerformanceSettings;
use Pollora\Harness\Config\Ratchet;
use Pollora\Harness\Metrics\TypeCoverage;
use Pollora\Harness\Performance\PerformanceCheck;

/**
 * What would block in a repository if each `observed` rule of the catalogue became `enforced` (`pollora-qa promote-report`).
 *
 * It only reads: the rules judged from the files (CONV-02, CONV-08, CONV-09, CONV-12) run the same code as their Pest
 * tests, CONV-11 compares the static type count with the recorded `type_coverage` floor, and nothing is
 * executed that needs an installed dependency, a database or a network. Nothing is written.
 *
 * Each rule gets one verdict:
 * - `would-block`: it would fail the gate today (with the number of problems and the first ones);
 * - `clean`: it would pass;
 * - `not-applicable`: there is nothing to judge (no class, no test file, no declaration), which is not a pass;
 * - `no-probe`: the catalogue lists the rule as observed but this report cannot measure it, and says so
 *   rather than leaving the rule out, so a new observed rule is never silently missing.
 */
final class PromotionReport
{
    /** How many problems are quoted for a rule. */
    public const int FIRST = 5;

    public const string WOULD_BLOCK = 'would-block';

    public const string CLEAN = 'clean';

    public const string NOT_APPLICABLE = 'not-applicable';

    public const string NO_PROBE = 'no-probe';

    /**
     * `count` is what would block once the per-class exemptions of `allow_rules` are applied; `found` is what the
     * rule reports before them, `exempted_problems` the difference, `exempted_classes` the classes the exemptions
     * name and `stale` the patterns that exempt nothing. Without a per-class exemption `count` equals `found`.
     *
     * @return list<array{rule: string, title: string, verdict: string, count: int, first: list<string>, note: string, exempted: string|null, found: int, exempted_problems: int, exempted_classes: list<string>, stale: list<string>}>
     */
    public static function build(string $root, ?HarnessConfig $config = null): array
    {
        $config ??= HarnessConfig::exists($root) ? HarnessConfig::load($root) : null;
        $exemptions = [];

        foreach ($config instanceof HarnessConfig ? $config->allowRules : [] as $exemption) {
            if (! $exemption->isPartial()) {
                $exemptions[$exemption->rule] = $exemption->reason;
            }
        }

        $partials = [];

        foreach ($config instanceof HarnessConfig ? $config->allowRules : [] as $exemption) {
            if ($exemption->isPartial()) {
                $partials[$exemption->rule][] = $exemption;
            }
        }

        $layers = null;
        $rows = [];

        foreach (Catalogue::all() as $rule => $definition) {
            if ($definition['status'] !== 'observed') {
                continue;
            }

            $layers ??= Layers::discover($root);

            [$verdict, $problems, $note] = match ($rule) {
                'CONV-02' => self::fromFiles($layers->files === [], 'no class under the PSR-4 roots of composer.json', static fn (): array => RawRegistrationArrays::problems($layers, $root)),
                'CONV-08' => self::fromFiles($layers->files === [], 'no class under the PSR-4 roots of composer.json', static fn (): array => FinalClasses::problems($layers)),
                'CONV-09' => self::fromFiles($layers->files === [], 'no class under the PSR-4 roots of composer.json', static fn (): array => FacadeClasses::problems($layers)),
                'CONV-12' => self::fromFiles(TestClasses::files($root) === [], 'no test file under tests/ or the autoload-dev roots', static fn (): array => TestClasses::problems($root)),
                'PERF-01', 'PERF-07' => self::performance($rule, $root, $config),
                'CONV-11' => self::typeCoverage($root, $config instanceof HarnessConfig ? $config->ratchet : new Ratchet),
                default => [self::NO_PROBE, [], 'the catalogue lists this rule as observed, but promote-report has no probe for it: measure it another way'],
            };

            $found = count($problems);
            $exemptedProblems = 0;
            $exemptedClasses = [];
            $stale = [];

            if (isset($partials[$rule]) && in_array($verdict, [self::WOULD_BLOCK, self::CLEAN], true)) {
                $outcome = ClassExemptions::apply($partials[$rule], $problems, $layers, $root);
                $problems = $outcome->kept;
                $exemptedProblems = $outcome->exemptedProblems();
                $exemptedClasses = $outcome->classes();
                $stale = $outcome->warnings();
                $verdict = $problems === [] ? self::CLEAN : self::WOULD_BLOCK;
            }

            $rows[] = [
                'rule' => $rule,
                'title' => $definition['title'],
                'verdict' => $verdict,
                'count' => count($problems),
                'first' => array_slice($problems, 0, self::FIRST),
                'note' => $note,
                'exempted' => $exemptions[$rule] ?? null,
                'found' => $found,
                'exempted_problems' => $exemptedProblems,
                'exempted_classes' => $exemptedClasses,
                'stale' => $stale,
            ];
        }

        return $rows;
    }

    /**
     * @param  callable(): list<string>  $problems
     * @return array{string, list<string>, string}
     */
    private static function fromFiles(bool $nothingToRead, string $why, callable $problems): array
    {
        if ($nothingToRead) {
            return [self::NOT_APPLICABLE, [], $why];
        }

        $found = $problems();

        return [$found === [] ? self::CLEAN : self::WOULD_BLOCK, $found, ''];
    }

    /**
     * PERF-01 and PERF-07 are judged by the same static read as the `Performance budgets` step: what is raised or
     * missing in the budgets file, what is missing from the nightly measures. A rule the repository does not declare
     * is "not applicable", which is not a pass. Nothing runs and nothing is written.
     *
     * @return array{string, list<string>, string}
     */
    private static function performance(string $rule, string $root, ?HarnessConfig $config): array
    {
        $check = new PerformanceCheck($root, $config instanceof HarnessConfig ? $config->performance : new PerformanceSettings);
        $declared = $rule === 'PERF-01' ? $check->hasBudgets() : $check->hasNightly();

        if (! $declared) {
            return [self::NOT_APPLICABLE, [], $rule === 'PERF-01' ? 'no budgets file declared' : 'no nightly measures declared'];
        }

        $found = array_values(array_filter(
            $check->assess()['problems'],
            static fn (string $problem): bool => str_starts_with($problem, $rule.':'),
        ));

        return [$found === [] ? self::CLEAN : self::WOULD_BLOCK, $found, ''];
    }

    /**
     * CONV-11 blocks through the `type_coverage` floor of the ratchet: without a recorded floor nothing blocks.
     * The PHPStan half of the rule (native types) is part of the static analysis gate and is not run here.
     *
     * @return array{string, list<string>, string}
     */
    private static function typeCoverage(string $root, Ratchet $ratchet): array
    {
        $report = TypeCoverage::measure($root);
        $measured = $report->percentage();

        if ($measured === null) {
            return [self::NOT_APPLICABLE, [], 'no declaration to count under the PSR-4 roots'];
        }

        $floor = $ratchet->get('type_coverage');
        $shown = number_format($measured, 2, '.', '');

        if ($floor === null) {
            return [self::CLEAN, [], "type coverage {$shown} %, no floor recorded in ratchet.type_coverage, so nothing blocks (record it with `pollora-qa ratchet --update`)"];
        }

        if ($measured >= $floor - Ratchet::TOLERANCE) {
            return [self::CLEAN, [], "type coverage {$shown} %, floor {$floor} %"];
        }

        return [self::WOULD_BLOCK, [
            "type coverage {$shown} % is below the recorded floor of {$floor} %",
            ...array_map(trim(...), array_slice($report->lines(), 1)),
        ], ''];
    }
}
