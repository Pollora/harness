<?php

declare(strict_types=1);

namespace Pollora\Harness\Console\Commands;

use Pollora\Harness\Check\MutationCanary;
use Pollora\Harness\Check\Plan;
use Pollora\Harness\Check\Report;
use Pollora\Harness\Check\Runner;
use Pollora\Harness\Config\HarnessConfig;
use Pollora\Harness\Config\Mode;
use Pollora\Harness\Config\MutationSettings;
use Pollora\Harness\Console\Command;
use Pollora\Harness\Console\Io;
use Pollora\Harness\Metrics\Collector;
use Pollora\Harness\Support\Paths;

final class CheckCommand implements Command
{
    public function name(): string
    {
        return 'check';
    }

    public function description(): string
    {
        return 'Run the gates of the repository (G1 and G2), or only the fast ones (G0)';
    }

    public function usage(): string
    {
        return <<<'TXT'
              --fast              Only the fast gates, on the files changed in the working tree
              --coverage[=FILE]   Run the tests with coverage and compare the Clover report with the ratchet (default file .harness/coverage.xml)
              --infection=FILE    Infection JSON log to compare with the ratchet
              --mutation          Run Pest's mutation testing (pest --mutate) and compare its score with the ratchet's msi
              --mutation-canary[=FILES]  Run no gate: only check that a mutation score could be believed here (mutants load, and the tests pass on unchanged code run as a mutant), of the whole repository or of the files given (comma separated); fails whatever the mode
              --mutation-base=REF With a limited mutation.scope, the commit the changed files are counted from (default HEAD^1: a pull request's merge commit needs fetch-depth 2)
              --budgets-base=REF  The commit the query budgets (PERF-01) are compared with, to refuse a budget that went up (default HEAD^1; "not compared" when it is not in the checkout)
              --mutation-scope=S  all, changed, added or changed-relative: what --mutation mutates in this run, over .harness.json (a limited scope is held to mutation.min, 50 % without one)
              --observe           Report failures without failing (overrides .harness.json)
              --enforce           Fail on any failure (overrides .harness.json)
              --strict            Also fail when a gate could not run because a tool is missing (Semgrep, vendor/bin/*), in any mode
              --template          Check a template (what template.yml runs): the ratchet of its optional .harness.json (or .github/harness.json), nothing at all without one
              --config=FILE       With --template, the file to read instead of .harness.json, then .github/harness.json
              --report=FILE       Where to write the JSON report (default .harness/report.json)
            TXT;
    }

    public function run(Io $io): int
    {
        if ($io->flag('template')) {
            return $this->template($io);
        }

        if ($io->flag('mutation-canary')) {
            return $this->mutationCanary($io);
        }

        $config = HarnessConfig::load($io->cwd);
        $mode = match (true) {
            $io->flag('enforce') => Mode::Enforce,
            $io->flag('observe') => Mode::Observe,
            default => $config->mode,
        };

        $scope = $io->option('mutation-scope');

        if ($scope !== null || $io->flag('mutation-scope')) {
            if ($scope === null || ! MutationSettings::isScope($scope)) {
                $io->error(sprintf('--mutation-scope is "%s". Use "all", "changed", "added" or "changed-relative".', $scope ?? ''));

                return self::FAILURE;
            }

            $config = $config->withMutation($config->mutation->withScope($scope));
        }

        $plan = new Plan($config, $io->cwd);
        $fast = $io->flag('fast');
        $steps = $fast
            ? $plan->fast(Paths::changedPhpFiles($io->cwd))
            : $plan->full($this->coverageReport($io), $io->option('infection'), $io->flag('mutation'), $io->option('mutation-base'), $io->option('budgets-base'));

        $io->line(sprintf('Pollora harness · profile %s · %s mode · %s gates', $config->profile->value, $mode->value, $fast ? 'fast' : 'full'));

        $report = new Report($config->profile, $mode, $fast, (new Runner($io, $io->cwd))->run($steps), [...$config->observed, ...Plan::OBSERVED_BY_DEFAULT], $io->flag('strict'), $config->slowStepShare);
        $report->print($io);
        $report->writeJson($io->cwd.'/'.($io->option('report') ?? '.harness/report.json'));

        $summary = getenv('GITHUB_STEP_SUMMARY');

        if (is_string($summary) && $summary !== '') {
            file_put_contents($summary, $report->markdown(), FILE_APPEND);
        }

        return $report->hasBlockingFailures() ? self::FAILURE : self::SUCCESS;
    }

    /**
     * `check --mutation-canary`: what a mutation run checks about its score, without the run itself, in the time of
     * a few passes of the suite and without a coverage driver.
     */
    private function mutationCanary(Io $io): int
    {
        if (! is_dir($io->cwd.'/vendor/pestphp/pest-plugin-mutate')) {
            $io->error('vendor/pestphp/pest-plugin-mutate is not installed: nothing was checked.');

            return self::FAILURE;
        }

        $paths = array_values(array_filter(explode(',', $io->option('mutation-canary') ?? ''), static fn (string $path): bool => $path !== ''));

        foreach ($paths as $path) {
            if (! is_file($io->cwd.'/'.$path)) {
                $io->error(sprintf('--mutation-canary: %s is not a file of the repository.', $path));

                return self::FAILURE;
            }
        }

        $refusal = MutationCanary::refusal($io->cwd, $paths);

        if ($refusal !== null) {
            $io->error($refusal);

            return self::FAILURE;
        }

        $io->line('Mutation canary passed: mutants load, and the tests pass on unchanged code run as a mutant. No score was measured.');

        return self::SUCCESS;
    }

    /**
     * `check --template`: the gates `template.yml` cannot run as separate jobs, read from the optional file of
     * the template. Without a file it does nothing and succeeds, so that a template that never adopted one
     * behaves exactly as before. The mode is the workflow's (--enforce / --observe), not the file's.
     */
    private function template(Io $io): int
    {
        $file = $io->option('config') ?? HarnessConfig::locateForTemplate($io->cwd);

        if ($file === null) {
            $io->line('No .harness.json (or .github/harness.json) in this template: nothing to check beyond the other gates.');

            return self::SUCCESS;
        }

        $config = HarnessConfig::loadForTemplate($io->cwd, $file);

        $mode = $io->flag('enforce') ? Mode::Enforce : Mode::Observe;
        $steps = (new Plan($config, $io->cwd))->template($this->coverageReport($io, false));

        $io->line(sprintf('Pollora harness · template (%s) · %s mode', $file, $mode->value));

        $report = new Report($config->profile, $mode, false, (new Runner($io, $io->cwd))->run($steps), [...$config->observed, ...Plan::OBSERVED_BY_DEFAULT], $io->flag('strict'), $config->slowStepShare);
        $report->print($io);
        $report->writeJson($io->cwd.'/'.($io->option('report') ?? '.harness/report.json'));

        $summary = getenv('GITHUB_STEP_SUMMARY');

        if (is_string($summary) && $summary !== '') {
            file_put_contents($summary, $report->markdown(), FILE_APPEND);
        }

        return $report->hasBlockingFailures() ? self::FAILURE : self::SUCCESS;
    }

    /**
     * The Clover report to write and read: the file given, or the conventional one for a bare --coverage.
     */
    private function coverageReport(Io $io, bool $bare = true): ?string
    {
        return $io->option('coverage') ?? ($bare && $io->flag('coverage') ? Collector::CLOVER_LOCATIONS[0] : null);
    }
}
