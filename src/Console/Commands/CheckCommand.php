<?php

declare(strict_types=1);

namespace Pollora\Harness\Console\Commands;

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
              --mutation-base=REF With mutation.scope "changed", the commit the changed files are counted from (default HEAD^1: a pull request's merge commit needs fetch-depth 2)
              --budgets-base=REF  The commit the query budgets (PERF-01) are compared with, to refuse a budget that went up (default HEAD^1; "not compared" when it is not in the checkout)
              --mutation-scope=S  all, changed or added: what --mutation mutates in this run, over .harness.json (a limited scope is held to mutation.min, 50 % without one)
              --observe           Report failures without failing (overrides .harness.json)
              --enforce           Fail on any failure (overrides .harness.json)
              --strict            Also fail when a gate could not run because a tool is missing (Semgrep, vendor/bin/*), in any mode
              --report=FILE       Where to write the JSON report (default .harness/report.json)
            TXT;
    }

    public function run(Io $io): int
    {
        $config = HarnessConfig::load($io->cwd);
        $mode = match (true) {
            $io->flag('enforce') => Mode::Enforce,
            $io->flag('observe') => Mode::Observe,
            default => $config->mode,
        };

        $scope = $io->option('mutation-scope');

        if ($scope !== null || $io->flag('mutation-scope')) {
            if ($scope === null || ! MutationSettings::isScope($scope)) {
                $io->error(sprintf('--mutation-scope is "%s". Use "all", "changed" or "added".', $scope ?? ''));

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
     * The Clover report to write and read: the file given, or the conventional one for a bare --coverage.
     */
    private function coverageReport(Io $io): ?string
    {
        return $io->option('coverage') ?? ($io->flag('coverage') ? Collector::CLOVER_LOCATIONS[0] : null);
    }
}
