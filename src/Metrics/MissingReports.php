<?php

declare(strict_types=1);

namespace Pollora\Harness\Metrics;

/**
 * Tells "the report is missing" from "the metric is unchanged": a metric the
 * ratchet records but that could not be measured because its report was not
 * given or found.
 */
final class MissingReports
{
    /** Metrics that come from a report file rather than from the repository's configuration. */
    private const array REPORTED = ['coverage', 'msi'];

    /**
     * @param  array{metric: string, recorded: int|float|null, verdict: string}  $row
     */
    public static function isMissing(array $row): bool
    {
        return $row['verdict'] === 'unmeasured' && $row['recorded'] !== null && in_array($row['metric'], self::REPORTED, true);
    }

    /**
     * What to print in the verdict column.
     *
     * @param  array{metric: string, recorded: int|float|null, verdict: string}  $row
     */
    public static function verdict(array $row): string
    {
        return self::isMissing($row) ? 'no report' : $row['verdict'];
    }

    /**
     * @param  list<array{metric: string, label: string, recorded: int|float|null, verdict: string}>  $rows
     * @return list<string>
     */
    public static function notes(array $rows): array
    {
        $notes = [];

        foreach ($rows as $row) {
            if (! self::isMissing($row)) {
                continue;
            }

            $notes[] = $row['metric'] === 'coverage'
                ? sprintf('  %s: no Clover report found (looked in %s). Produce one with `vendor/bin/pollora-qa check --coverage`, or pass --coverage=FILE; nothing was compared with the recorded %s.', $row['label'], implode(', ', Collector::CLOVER_LOCATIONS), $row['recorded'])
                : sprintf('  %s: no Infection log given. Pass --infection=FILE; nothing was compared with the recorded %s.', $row['label'], $row['recorded']);
        }

        return $notes;
    }
}
