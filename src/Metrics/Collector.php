<?php

declare(strict_types=1);

namespace Pollora\Harness\Metrics;

use DOMDocument;

/**
 * Measures the metrics the ratchet tracks, from the files the tools already
 * write: the PHPStan configuration and baseline, a Clover coverage report and
 * an Infection JSON log. A metric whose source is missing is reported as null
 * (not measured) rather than as zero.
 */
final class Collector
{
    private const CONFIG_FILES = ['phpstan.neon', 'phpstan.neon.dist', 'phpstan.dist.neon'];

    private const BASELINE_FILES = ['phpstan-baseline.neon', 'phpstan-baseline.php'];

    public function __construct(private readonly string $root) {}

    /**
     * @param  array<string, int|float>  $overrides  Values passed on the command line (--metric name=value)
     * @return array<string, int|float|null>
     */
    public function collect(?string $cloverPath = null, ?string $infectionPath = null, array $overrides = []): array
    {
        return array_merge([
            'phpstan_level' => $this->phpstanLevel(),
            'phpstan_baseline' => $this->phpstanBaselineEntries(),
            'coverage' => $cloverPath !== null ? self::cloverCoverage($this->resolve($cloverPath)) : null,
            'type_coverage' => null,
            'msi' => $infectionPath !== null ? self::infectionMsi($this->resolve($infectionPath)) : null,
        ], $overrides);
    }

    /**
     * The level set in the repository's PHPStan configuration, "max" counting as 10.
     */
    public function phpstanLevel(): ?int
    {
        foreach (self::CONFIG_FILES as $file) {
            $path = $this->resolve($file);

            if (! is_file($path)) {
                continue;
            }

            if (preg_match('/^\s*level:\s*([0-9]+|max)\s*$/m', (string) file_get_contents($path), $match) === 1) {
                return $match[1] === 'max' ? 10 : (int) $match[1];
            }
        }

        return null;
    }

    /**
     * The number of errors the baseline hides: each entry counts for its
     * "count" (1 when absent). Zero when there is no baseline at all, which
     * is the goal every repository moves towards.
     */
    public function phpstanBaselineEntries(): int
    {
        foreach (self::BASELINE_FILES as $file) {
            $path = $this->resolve($file);

            if (! is_file($path)) {
                continue;
            }

            return str_ends_with($file, '.php')
                ? self::countPhpBaseline($path)
                : self::countNeonBaseline((string) file_get_contents($path));
        }

        return 0;
    }

    public static function countNeonBaseline(string $neon): int
    {
        $total = 0;
        $chunks = preg_split('/^\s*-\s*$/m', $neon);

        foreach ($chunks === false ? [] : $chunks as $chunk) {
            if (preg_match('/^\s*(message|rawMessage|identifier):/m', $chunk) !== 1) {
                continue;
            }

            $total += preg_match('/^\s*count:\s*([0-9]+)\s*$/m', $chunk, $match) === 1 ? (int) $match[1] : 1;
        }

        return $total;
    }

    public static function cloverCoverage(string $path): ?float
    {
        if (! is_file($path)) {
            return null;
        }

        $document = new DOMDocument;

        if (! @$document->load($path)) {
            return null;
        }

        $metrics = $document->getElementsByTagName('project')->item(0)?->getElementsByTagName('metrics');
        $project = $metrics?->item($metrics->length - 1);

        if ($project === null) {
            return null;
        }

        $statements = (int) $project->getAttribute('statements');
        $covered = (int) $project->getAttribute('coveredstatements');

        return $statements === 0 ? null : round($covered / $statements * 100, 2);
    }

    public static function infectionMsi(string $path): ?float
    {
        if (! is_file($path)) {
            return null;
        }

        $log = json_decode((string) file_get_contents($path), true);
        $msi = is_array($log) && is_array($log['stats'] ?? null) ? ($log['stats']['msi'] ?? null) : null;

        return is_int($msi) || is_float($msi) ? round((float) $msi, 2) : null;
    }

    private static function countPhpBaseline(string $path): int
    {
        $config = (static fn (): mixed => require $path)();
        $parameters = is_array($config) && is_array($config['parameters'] ?? null) ? $config['parameters'] : [];
        $errors = is_array($parameters['ignoreErrors'] ?? null) ? $parameters['ignoreErrors'] : [];

        $total = 0;

        foreach ($errors as $error) {
            $total += is_array($error) && is_int($error['count'] ?? null) ? $error['count'] : 1;
        }

        return $total;
    }

    private function resolve(string $path): string
    {
        return str_starts_with($path, '/') ? $path : rtrim($this->root, '/').'/'.$path;
    }
}
