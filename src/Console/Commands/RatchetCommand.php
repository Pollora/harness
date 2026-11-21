<?php

declare(strict_types=1);

namespace Pollora\Harness\Console\Commands;

use Pollora\Harness\Config\HarnessConfig;
use Pollora\Harness\Config\InvalidConfig;
use Pollora\Harness\Config\Ratchet;
use Pollora\Harness\Console\Command;
use Pollora\Harness\Console\Io;
use Pollora\Harness\Metrics\Collector;
use Pollora\Harness\Metrics\MissingReports;
use Pollora\Harness\Profile;

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
              --coverage=FILE     Clover coverage report (default: .harness/coverage.xml, .harness/clover.xml or build/logs/clover.xml, when one exists)
              --infection=FILE    Infection JSON log
              --config=FILE       The file to read and update (default: .harness.json, then .github/harness.json for a template); a file without a profile is a template's
              --metric=NAME:VALUE Any other measured value, e.g. --metric=type_coverage:98.4
            TXT;
    }

    public function run(Io $io): int
    {
        $file = $io->option('config') ?? (HarnessConfig::exists($io->cwd) ? HarnessConfig::FILE : HarnessConfig::locateForTemplate($io->cwd) ?? HarnessConfig::FILE);

        $config = $file === HarnessConfig::FILE && $io->option('config') === null ? $this->loadRoot($io->cwd) : HarnessConfig::loadAny($io->cwd, $file);

        $collector = new Collector($io->cwd);
        $measured = $collector->collect($io->option('coverage'), $io->option('infection'), $this->overrides($io), Collector::exemptedRules($config), Collector::exemptedClasses($config, $io->cwd));
        $rows = $config->ratchet->compare($measured);

        $io->line(sprintf('  %-32s %-10s %-10s %s', 'Metric', 'Recorded', 'Measured', 'Verdict'));

        foreach ($rows as $row) {
            $verdict = match ($row['verdict']) {
                'regressed' => $io->failure('regressed'),
                'improved' => $io->success('improved'),
                'new' => $io->success('new'),
                default => MissingReports::isMissing($row) ? $io->warning(MissingReports::verdict($row)) : $io->muted($row['verdict']),
            };

            $io->line(sprintf('  %-32s %-10s %-10s %s', $row['label'], $row['recorded'] ?? '-', $row['measured'] ?? '-', $verdict));
        }

        foreach (MissingReports::notes($rows) as $note) {
            $io->line();
            $io->line($io->warning($note));
        }

        if ($io->flag('update')) {
            $tightened = $config->ratchet->tighten($measured);

            if ($tightened->values !== $config->ratchet->values) {
                $config->withRatchet($tightened)->save($io->cwd, $file, $file !== HarnessConfig::FILE || $this->isTemplate($config));
                $io->line();
                $io->line($io->success($file.' tightened.'));
            }
        }

        $regressed = array_filter($rows, static fn (array $row): bool => $row['verdict'] === 'regressed');

        return $regressed === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * The root file: read as before (the profile is required, the error raised as it always was) unless it leaves
     * the profile out, which only a template's file may.
     *
     * @throws InvalidConfig
     */
    private function loadRoot(string $cwd): HarnessConfig
    {
        $data = is_file(HarnessConfig::path($cwd)) ? json_decode((string) file_get_contents(HarnessConfig::path($cwd)), true) : null;

        return is_array($data) && ! array_key_exists('profile', $data) ? HarnessConfig::loadAny($cwd) : HarnessConfig::load($cwd);
    }

    private function isTemplate(HarnessConfig $config): bool
    {
        return in_array($config->profile, [Profile::Template, Profile::Project], true);
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
