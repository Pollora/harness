<?php

declare(strict_types=1);

namespace Pollora\Harness\Check;

use Pollora\Harness\Config\Mode;
use Pollora\Harness\Console\Io;
use Pollora\Harness\Profile;
use Pollora\Harness\Rules\Catalogue;

/**
 * What a check run found, for the console, for CI (JSON) and for the GitHub
 * job summary (Markdown).
 */
final readonly class Report
{
    /** A step shorter than this is never called slow, whatever its share of a short run. */
    public const float MIN_SLOW_SECONDS = 30.0;

    /**
     * @param  list<array{step: Step, result: StepResult}>  $results
     * @param  list<string>  $observed  Gates reported but never blocking, whatever the mode
     * @param  bool  $strict  A gate that did not run for want of a tool fails the check, whatever the mode
     * @param  float|null  $slowShare  Share of the total time above which a step is flagged slow (`slow_step_share`); null: no warning
     */
    public function __construct(
        private Profile $profile,
        private Mode $mode,
        private bool $fast,
        private array $results,
        private array $observed = [],
        private bool $strict = false,
        private ?float $slowShare = null,
    ) {}

    /**
     * The time the whole run took: the sum of the steps, which run one after the other.
     */
    public function totalSeconds(): float
    {
        return array_sum(array_map(static fn (array $entry): float => $entry['result']->seconds, $this->results));
    }

    /**
     * A duration for people: "8.4s" under a minute, "3m 05s" above.
     */
    public static function duration(float $seconds): string
    {
        if ($seconds < 60) {
            return sprintf('%.1fs', $seconds);
        }

        $whole = (int) round($seconds);

        return sprintf('%dm %02ds', intdiv($whole, 60), $whole % 60);
    }

    /**
     * The steps that took more than `slow_step_share` of the total and at least MIN_SLOW_SECONDS, slowest first.
     * Empty when the repository set no share. A step that did not run is never slow.
     *
     * @return list<array{name: string, seconds: float, share: float}>
     */
    public function slowSteps(): array
    {
        $total = $this->totalSeconds();

        if ($this->slowShare === null || $total <= 0.0) {
            return [];
        }

        $slow = [];

        foreach ($this->results as ['step' => $step, 'result' => $result]) {
            $share = $result->seconds / $total;

            if ($result->seconds >= self::MIN_SLOW_SECONDS && $share > $this->slowShare) {
                $slow[] = ['name' => $step->name, 'seconds' => round($result->seconds, 2), 'share' => round($share, 3)];
            }
        }

        usort($slow, static fn (array $a, array $b): int => $b['seconds'] <=> $a['seconds']);

        return $slow;
    }

    /**
     * Whether a failure stops the merge: the mode blocks and the failed gate is not one the repository only observes.
     */
    public function hasBlockingFailures(): bool
    {
        if ($this->strict && $this->missingTools() !== []) {
            return true;
        }

        if (! $this->mode->blocks()) {
            return false;
        }

        foreach ($this->results as $entry) {
            if ($entry['result']->isFailed() && ! in_array($entry['step']->name, $this->observed, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The gates that did not run because a tool is missing, except the ones the repository only observes.
     *
     * @return list<array{step: Step, result: StepResult}>
     */
    public function missingTools(): array
    {
        return array_values(array_filter(
            $this->results,
            fn (array $entry): bool => $entry['result']->isMissingTool() && ! in_array($entry['step']->name, $this->observed, true),
        ));
    }

    public function hasFailures(): bool
    {
        foreach ($this->results as $entry) {
            if ($entry['result']->isFailed()) {
                return true;
            }
        }

        return false;
    }

    public function print(Io $io): void
    {
        $io->title('Summary');

        foreach ($this->results as ['step' => $step, 'result' => $result]) {
            $status = match ($result->status) {
                StepResult::PASSED => $io->success('✔ passed '),
                StepResult::FAILED => $io->failure('✘ failed '),
                default => $result->isMissingTool() ? $io->warning('– no tool') : $io->warning('– skipped'),
            };

            $exempted = $result->status === StepResult::PASSED ? $step->exemptionSummary() : null;

            $io->line(sprintf('  %s  %-24s %s', $status, $step->name, $io->muted(implode(' ', $step->rules))).($exempted === null ? '' : ' '.$io->warning('('.$exempted.')')).$io->muted(sprintf('  %s', self::duration($result->seconds))));

            if ($result->isFailed()) {
                foreach ($step->rules as $rule) {
                    $io->line($io->muted("               {$rule}: ".Catalogue::title($rule)));
                }
            }
        }

        $io->line($io->muted('  Total: '.self::duration($this->totalSeconds())));

        foreach ($this->slowSteps() as $slow) {
            $io->line($io->warning('  Slow step: '.$this->slowWarning($slow)));
        }

        $io->line();

        $missing = $this->missingTools();

        if ($missing !== []) {
            $io->line($io->warning(sprintf('%d gate(s) did not run because a tool is missing. This is not a pass, CI may still fail on what they guard:', count($missing))));

            foreach ($missing as ['step' => $step, 'result' => $result]) {
                $io->line(sprintf('  %s (%s): %s', $step->name, implode(' ', $step->rules), $result->install));
            }

            $io->line($this->strict
                ? $io->failure('Strict mode: install the missing tools, then run the check again.')
                : $io->muted('Add --strict to fail when a tool is missing.'));
            $io->line();
        }

        if (! $this->hasFailures()) {
            $io->line($missing === []
                ? $io->success('Every gate passed.')
                : $io->warning('Every gate that ran passed, but not every gate ran.'));

            return;
        }

        $io->line($this->hasBlockingFailures()
            ? $io->failure('Some gates failed. Fix them before merging.')
            : $io->warning('Some gates failed. Reported, not blocking (observe mode, or gates listed as observed).'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'profile' => $this->profile->value,
            'mode' => $this->mode->value,
            'gates' => $this->fast ? 'fast' : 'full',
            'failed' => $this->hasFailures(),
            'strict' => $this->strict,
            'total_seconds' => round($this->totalSeconds(), 2),
            ...($this->slowSteps() === [] ? [] : ['slow_steps' => $this->slowSteps()]),
            'steps' => array_map(static fn (array $entry): array => [
                'name' => $entry['step']->name,
                'rules' => $entry['step']->rules,
                'status' => $entry['result']->status,
                'seconds' => round($entry['result']->seconds, 2),
                'detail' => $entry['result']->detail,
                ...($entry['step']->exempted === [] ? [] : ['exempted' => $entry['step']->exempted]),
                ...($entry['result']->isMissingTool() ? ['missing_tool' => true, 'install' => $entry['result']->install] : []),
            ], $this->results),
        ];
    }

    public function writeJson(string $path): void
    {
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0o755, true);
        }

        file_put_contents($path, json_encode($this->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
    }

    public function markdown(): string
    {
        $lines = [
            sprintf('### Pollora harness · %s · %s mode', $this->profile->value, $this->mode->value),
            '',
            '| Gate | Rules | Time | Result |',
            '| --- | --- | ---: | --- |',
        ];

        foreach ($this->results as ['step' => $step, 'result' => $result]) {
            $lines[] = sprintf('| %s | %s | %s | %s |', $step->name, implode(', ', $step->rules), self::duration($result->seconds), match ($result->status) {
                StepResult::PASSED => '✅ passed'.($step->exemptionSummary() === null ? '' : ' ('.$step->exemptionSummary().')'),
                StepResult::FAILED => $this->mode->blocks() && ! in_array($step->name, $this->observed, true) ? '❌ failed' : '⚠️ failed (observed)',
                default => $result->isMissingTool()
                    ? '⚠️ not run, tool missing: '.str_replace('|', '\|', $result->detail).' — install: `'.str_replace('|', '\|', (string) $result->install).'`'
                    : '➖ skipped: '.str_replace('|', '\|', $result->detail),
            });
        }

        $lines[] = '';
        $lines[] = sprintf('**Total: %s**', self::duration($this->totalSeconds()));

        foreach ($this->slowSteps() as $slow) {
            $lines[] = '';
            $lines[] = '⚠️ Slow step: '.$this->slowWarning($slow).'.';
        }

        return implode("\n", $lines)."\n\n";
    }

    /**
     * "Static analysis took 3m 05s, 62% of the run (the limit is 50%)".
     *
     * @param  array{name: string, seconds: float, share: float}  $slow
     */
    private function slowWarning(array $slow): string
    {
        return sprintf('%s took %s, %d%% of the run (the limit is %d%%)', $slow['name'], self::duration($slow['seconds']), (int) round($slow['share'] * 100), (int) round(($this->slowShare ?? 0.0) * 100));
    }
}
