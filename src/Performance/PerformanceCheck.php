<?php

declare(strict_types=1);

namespace Pollora\Harness\Performance;

use Pollora\Harness\Check\StepResult;
use Pollora\Harness\Config\PerformanceSettings;
use Pollora\Harness\Support\ChangedSources;
use Pollora\Harness\Support\Paths;

/**
 * The "Performance budgets" step: PERF-01 (query budgets) and PERF-07 (nightly measures), checked from the files
 * of the repository without running a site.
 *
 * PERF-01 validates the budgets file, reports the budgets that are `null`, and holds the ratchet: a budget never
 * goes up, nor disappears, without a decision, found by comparing the file with the one of the base commit. When
 * the base cannot be read the step says "not compared"; it never reports a comparison that did not happen.
 * PERF-07 checks that the nightly measures are wired to a scheduled workflow (see `NightlyMeasures`).
 */
final readonly class PerformanceCheck
{
    public function __construct(
        private string $cwd,
        private PerformanceSettings $settings,
        private string $base = ChangedSources::DEFAULT_BASE,
    ) {}

    /**
     * Whether the repository declares budgets: the file named by the settings, or the default one when it exists.
     */
    public function hasBudgets(): bool
    {
        return $this->settings->declaresBudgets() || is_file($this->cwd.'/'.$this->settings->budgetsPath());
    }

    /**
     * Whether the repository declares nightly measures: `nightly` in the settings, or the default measure file.
     */
    public function hasNightly(): bool
    {
        return $this->settings->nightlyDeclared || is_file($this->cwd.'/'.$this->settings->measurePath());
    }

    /**
     * Why the step has nothing to check here, or null when it applies.
     */
    public function skipReason(): ?string
    {
        if ($this->hasBudgets() || $this->hasNightly()) {
            return null;
        }

        return sprintf('no budgets file (%s) and no nightly measures (%s) declared: nothing to check (performance.budgets and performance.nightly in .harness.json move them)', $this->settings->budgetsPath(), $this->settings->measurePath());
    }

    public function run(): StepResult
    {
        $problems = [];
        $lines = [];

        if ($this->hasBudgets()) {
            ['problems' => $found, 'lines' => $said] = $this->budgets();
            array_push($problems, ...$found);
            array_push($lines, ...$said);
        } else {
            $lines[] = sprintf('  PERF-01 not declared: no %s', $this->settings->budgetsPath());
        }

        if ($this->hasNightly()) {
            $nightly = NightlyMeasures::check($this->cwd, $this->settings);
            array_push($problems, ...array_map(static fn (string $problem): string => 'PERF-07: '.$problem, $nightly['problems']));
            array_push($lines, ...array_map(static fn (string $fact): string => '  PERF-07: '.$fact, $nightly['facts']));
        } else {
            $lines[] = sprintf('  PERF-07 not declared: no %s and no performance.nightly', $this->settings->measurePath());
        }

        if ($problems === []) {
            return StepResult::passed(implode("\n", $lines));
        }

        return StepResult::failed(implode("\n", [...array_map(static fn (string $problem): string => '  '.$problem, $problems), ...$lines]));
    }

    /**
     * @return array{problems: list<string>, lines: list<string>}
     */
    private function budgets(): array
    {
        $file = $this->settings->budgetsPath();
        $json = is_file($this->cwd.'/'.$file) ? file_get_contents($this->cwd.'/'.$file) : false;

        if ($json === false) {
            return ['problems' => [sprintf('PERF-01: %s is declared but does not exist', $file)], 'lines' => []];
        }

        $budgets = Budgets::parse($json);

        if (is_array($budgets)) {
            return ['problems' => array_map(static fn (string $problem): string => sprintf('PERF-01: %s: %s', $file, $problem), $budgets), 'lines' => []];
        }

        $unset = $budgets->unset();
        $lines = [sprintf('  PERF-01: %d budget%s set across %d page type%s in %s', $budgets->set(), $budgets->set() === 1 ? '' : 's', count($budgets->pages), count($budgets->pages) === 1 ? '' : 's', $file)];

        if ($unset !== []) {
            $lines[] = sprintf('  PERF-01: %d without a budget (null, measured only): %s', count($unset), implode(', ', $unset));
        }

        $before = $this->baseBudgets();

        if (is_string($before)) {
            return ['problems' => [], 'lines' => [...$lines, '  PERF-01: not compared with '.$this->base.': '.$before]];
        }

        $loosened = $budgets->loosenedSince($before);

        if ($loosened === []) {
            return ['problems' => [], 'lines' => [...$lines, sprintf('  PERF-01: compared with %s: no budget raised or dropped', $this->base)]];
        }

        return [
            'problems' => [sprintf('PERF-01: %d budget%s loosened since %s. A budget never goes up without a written decision: lower it back, or say why in the change and move the base.', count($loosened), count($loosened) === 1 ? '' : 's', $this->base), ...array_map(static fn (string $line): string => '  '.$line, $loosened)],
            'lines' => $lines,
        ];
    }

    /**
     * The budgets of the base commit, or the reason why they cannot be compared.
     */
    private function baseBudgets(): Budgets|string
    {
        if ($this->base === '' || str_starts_with($this->base, '-') || ! Paths::gitUsable($this->cwd)) {
            return 'git cannot say (no git, or no usable repository)';
        }

        if ($this->git(['rev-parse', '--verify', '--quiet', $this->base.'^{commit}']) === null) {
            return sprintf('%s is not in this checkout (a history of two commits is needed: fetch-depth 2, or --budgets-base=REF)', $this->base);
        }

        $content = $this->git(['show', $this->base.':'.$this->settings->budgetsPath()]);

        if ($content === null) {
            return sprintf('the file does not exist at %s, so every budget is new', $this->base);
        }

        $budgets = Budgets::parse($content);

        return is_array($budgets) ? sprintf('the file at %s is unreadable (%s)', $this->base, $budgets[0]) : $budgets;
    }

    /**
     * @param  list<string>  $arguments
     * @return string|null The output, or null when git fails
     */
    private function git(array $arguments): ?string
    {
        $process = proc_open(['git', ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->cwd);

        if (! is_resource($process)) {
            return null;
        }

        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return proc_close($process) === 0 ? $output : null;
    }
}
