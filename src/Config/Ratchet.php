<?php

declare(strict_types=1);

namespace Pollora\Harness\Config;

/**
 * The best value reached so far for each tracked metric (QA-01).
 *
 * A metric that has never been measured is null and is not compared. Once
 * recorded, a metric may only improve: a higher PHPStan level, fewer baseline
 * entries, more coverage, a higher mutation or type coverage score.
 */
final class Ratchet
{
    /** Rounding noise tolerated on percentages, in points. */
    public const TOLERANCE = 0.05;

    /**
     * The metrics, their direction and how they are labelled.
     *
     * @var array<string, array{better: 'higher'|'lower', label: string}>
     */
    public const METRICS = [
        'phpstan_level' => ['better' => 'higher', 'label' => 'PHPStan level'],
        'phpstan_baseline' => ['better' => 'lower', 'label' => 'PHPStan baseline entries'],
        'coverage' => ['better' => 'higher', 'label' => 'Line coverage (%)'],
        'type_coverage' => ['better' => 'higher', 'label' => 'Type coverage (%)'],
        'msi' => ['better' => 'higher', 'label' => 'Mutation score indicator (%)'],
    ];

    /** PHPStan 2 has ten levels; "max" is the highest. */
    private const MAX_PHPSTAN_LEVEL = 10;

    /**
     * @param  array<string, int|float|null>  $values
     */
    public function __construct(public readonly array $values = []) {}

    /**
     * @param  array<mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $values = [];

        foreach (array_keys(self::METRICS) as $metric) {
            $value = $data[$metric] ?? null;

            if ($metric === 'phpstan_level' && $value === 'max') {
                $value = self::MAX_PHPSTAN_LEVEL;
            }

            $values[$metric] = is_int($value) || is_float($value) ? $value : null;
        }

        return new self($values);
    }

    public function get(string $metric): int|float|null
    {
        return $this->values[$metric] ?? null;
    }

    /**
     * Compares measured values with the ratchet.
     *
     * @param  array<string, int|float|null>  $measured
     * @return list<array{metric: string, label: string, recorded: int|float|null, measured: int|float|null, verdict: 'regressed'|'improved'|'unchanged'|'new'|'unmeasured'}>
     */
    public function compare(array $measured): array
    {
        $rows = [];

        foreach (self::METRICS as $metric => $definition) {
            $recorded = $this->get($metric);
            $current = $measured[$metric] ?? null;

            $rows[] = [
                'metric' => $metric,
                'label' => $definition['label'],
                'recorded' => $recorded,
                'measured' => $current,
                'verdict' => self::verdict($definition['better'], $recorded, $current),
            ];
        }

        return $rows;
    }

    /**
     * Tightens the ratchet with every measured value that is better than,
     * or not yet in, the recorded one. A worse value never loosens it.
     *
     * @param  array<string, int|float|null>  $measured
     */
    public function tighten(array $measured): self
    {
        $values = $this->values;

        foreach ($this->compare($measured) as $row) {
            if (in_array($row['verdict'], ['improved', 'new'], true)) {
                $values[$row['metric']] = $row['measured'];
            }
        }

        return new self($values);
    }

    /**
     * @return array<string, int|float|string>
     */
    public function toArray(): array
    {
        $data = [];

        foreach (array_keys(self::METRICS) as $metric) {
            $value = $this->get($metric);

            if ($value === null) {
                continue;
            }

            $data[$metric] = $metric === 'phpstan_level' && $value >= self::MAX_PHPSTAN_LEVEL ? 'max' : $value;
        }

        return $data;
    }

    /**
     * @return 'regressed'|'improved'|'unchanged'|'new'|'unmeasured'
     */
    private static function verdict(string $better, int|float|null $recorded, int|float|null $measured): string
    {
        if ($measured === null) {
            return 'unmeasured';
        }

        if ($recorded === null) {
            return 'new';
        }

        $delta = $better === 'higher' ? $measured - $recorded : $recorded - $measured;

        return match (true) {
            $delta < -self::TOLERANCE => 'regressed',
            $delta > self::TOLERANCE => 'improved',
            default => 'unchanged',
        };
    }
}
