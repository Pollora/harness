<?php

declare(strict_types=1);

namespace Pollora\Harness\Console\Commands;

use Pollora\Harness\Config\HarnessConfig;
use Pollora\Harness\Config\Ratchet;
use Pollora\Harness\Console\Command;
use Pollora\Harness\Console\Io;
use Pollora\Harness\Metrics\Collector;

final class RatchetCommand implements Command
{
    public function name(): string
    {
        return 'ratchet';
    }

    public function description(): string
    {
        return 'Compare the metrics with the ratchet (QA-01), or tighten it with --update';
    }

    public function usage(): string
    {
        return <<<'TXT'
              --update            Record every improved metric in .harness.json (never loosens it)
              --coverage=FILE     Clover coverage report
              --infection=FILE    Infection JSON log
              --metric=NAME:VALUE Any other measured value, e.g. --metric=type_coverage:98.4
            TXT;
    }

    public function run(Io $io): int
    {
        $config = HarnessConfig::load($io->cwd);
        $measured = (new Collector($io->cwd))->collect($io->option('coverage'), $io->option('infection'), $this->overrides($io));
        $rows = $config->ratchet->compare($measured);

        $io->line(sprintf('  %-32s %-10s %-10s %s', 'Metric', 'Recorded', 'Measured', 'Verdict'));

        foreach ($rows as $row) {
            $verdict = match ($row['verdict']) {
                'regressed' => $io->failure('regressed'),
                'improved' => $io->success('improved'),
                'new' => $io->success('new'),
                default => $io->muted($row['verdict']),
            };

            $io->line(sprintf('  %-32s %-10s %-10s %s', $row['label'], $row['recorded'] ?? '-', $row['measured'] ?? '-', $verdict));
        }

        if ($io->flag('update')) {
            $tightened = $config->ratchet->tighten($measured);

            if ($tightened->values !== $config->ratchet->values) {
                $config->withRatchet($tightened)->save($io->cwd);
                $io->line();
                $io->line($io->success('.harness.json tightened.'));
            }
        }

        $regressed = array_filter($rows, static fn (array $row): bool => $row['verdict'] === 'regressed');

        return $regressed === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<string, float>
     */
    private function overrides(Io $io): array
    {
        $overrides = [];

        foreach ($io->list('metric') as $pair) {
            [$name, $value] = array_pad(explode(':', $pair, 2), 2, '');

            if (array_key_exists($name, Ratchet::METRICS) && is_numeric($value)) {
                $overrides[$name] = (float) $value;
            }
        }

        return $overrides;
    }
}
