<?php

declare(strict_types=1);

namespace Pollora\Harness\Console\Commands;

use Pollora\Harness\Check\Plan;
use Pollora\Harness\Check\Report;
use Pollora\Harness\Check\Runner;
use Pollora\Harness\Config\HarnessConfig;
use Pollora\Harness\Config\Mode;
use Pollora\Harness\Console\Command;
use Pollora\Harness\Console\Io;
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
              --coverage=FILE     Clover report to compare with the ratchet
              --infection=FILE    Infection JSON log to compare with the ratchet
              --observe           Report failures without failing (overrides .harness.json)
              --enforce           Fail on any failure (overrides .harness.json)
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

        $plan = new Plan($config, $io->cwd);
        $fast = $io->flag('fast');
        $steps = $fast
            ? $plan->fast(Paths::changedPhpFiles($io->cwd))
            : $plan->full($io->option('coverage'), $io->option('infection'));

        $io->line(sprintf('Pollora harness · profile %s · %s mode · %s gates', $config->profile->value, $mode->value, $fast ? 'fast' : 'full'));

        $report = new Report($config->profile, $mode, $fast, (new Runner($io, $io->cwd))->run($steps), $config->observed);
        $report->print($io);
        $report->writeJson($io->cwd.'/'.($io->option('report') ?? '.harness/report.json'));

        $summary = getenv('GITHUB_STEP_SUMMARY');

        if (is_string($summary) && $summary !== '') {
            file_put_contents($summary, $report->markdown(), FILE_APPEND);
        }

        return $report->hasBlockingFailures() ? self::FAILURE : self::SUCCESS;
    }
}
