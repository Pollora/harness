<?php

declare(strict_types=1);

namespace Pollora\Harness\Config;

/**
 * The `performance` key of `.harness.json`: where a repository keeps its query budgets and what its nightly
 * measures are (PERF-01 and PERF-07).
 *
 * Nothing here is required. A repository that has a `tests/e2e/budgets.json` is checked on it without declaring
 * anything, and the same goes for the nightly measures of the framework layout; the key only moves a file or
 * declares a nightly job a repository keeps elsewhere.
 *
 * ```json
 * "performance": {
 *     "budgets": "tests/e2e/budgets.json",
 *     "nightly": { "measure": "tests/e2e/specs/perf-metrics.spec.ts", "compare": "tests/e2e/bin/perf-compare.ts", "workflow": ".github/workflows/ci.yml" }
 * }
 * ```
 *
 * `nightly: {}` declares the nightly measures with the default paths, so a repository that lost the files is told so
 * instead of being skipped.
 */
final readonly class PerformanceSettings
{
    /** The budgets file when `budgets` is not given. */
    public const string DEFAULT_BUDGETS = 'tests/e2e/budgets.json';

    /** The Playwright spec that measures the timings, when `nightly.measure` is not given. */
    public const string DEFAULT_MEASURE = 'tests/e2e/specs/perf-metrics.spec.ts';

    /** The script that compares a night with the last good one, when `nightly.compare` is not given. */
    public const string DEFAULT_COMPARE = 'tests/e2e/bin/perf-compare.ts';

    /**
     * @param  string|null  $budgets  The budgets file, relative to the repository; null for the default, which is only checked when it exists
     * @param  bool  $nightlyDeclared  Whether `nightly` is present: the nightly measures are checked even if the default files are absent
     * @param  string|null  $measure  The file that measures the timings; null for the default
     * @param  string|null  $compare  The file that compares them with the reference; null for the default
     * @param  string|null  $workflow  The only workflow looked at for the schedule; null looks at every workflow
     */
    public function __construct(
        public ?string $budgets = null,
        public bool $nightlyDeclared = false,
        public ?string $measure = null,
        public ?string $compare = null,
        public ?string $workflow = null,
    ) {}

    /**
     * @throws InvalidConfig
     */
    public static function fromArray(mixed $data, string $path): self
    {
        if ($data === null) {
            return new self;
        }

        if (! is_array($data) || ($data !== [] && array_is_list($data))) {
            throw InvalidConfig::performance($path, 'it must be an object');
        }

        $nightly = $data['nightly'] ?? null;

        if ($nightly !== null && (! is_array($nightly) || ($nightly !== [] && array_is_list($nightly)))) {
            throw InvalidConfig::performance($path, 'nightly must be an object');
        }

        return new self(
            budgets: self::file($data['budgets'] ?? null, 'budgets', $path),
            nightlyDeclared: $nightly !== null,
            measure: self::file($nightly['measure'] ?? null, 'nightly.measure', $path),
            compare: self::file($nightly['compare'] ?? null, 'nightly.compare', $path),
            workflow: self::file($nightly['workflow'] ?? null, 'nightly.workflow', $path),
        );
    }

    /**
     * Whether the repository names its budgets file, which then has to exist.
     */
    public function declaresBudgets(): bool
    {
        return $this->budgets !== null;
    }

    public function budgetsPath(): string
    {
        return $this->budgets ?? self::DEFAULT_BUDGETS;
    }

    public function measurePath(): string
    {
        return $this->measure ?? self::DEFAULT_MEASURE;
    }

    public function comparePath(): string
    {
        return $this->compare ?? self::DEFAULT_COMPARE;
    }

    /**
     * @return array<string, mixed>|null null when nothing differs from the defaults
     */
    public function toArray(): ?array
    {
        $nightly = array_filter(['measure' => $this->measure, 'compare' => $this->compare, 'workflow' => $this->workflow], static fn (?string $value): bool => $value !== null);
        $data = array_filter([
            'budgets' => $this->budgets,
            'nightly' => $this->nightlyDeclared ? ($nightly === [] ? (object) [] : $nightly) : null,
        ], static fn (mixed $value): bool => $value !== null);

        return $data === [] ? null : $data;
    }

    /**
     * A path inside the repository: relative, without `..`, and not an option for git.
     *
     * @throws InvalidConfig
     */
    private static function file(mixed $value, string $key, string $path): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value) || $value === '' || str_starts_with($value, '/') || str_starts_with($value, '-') || in_array('..', explode('/', $value), true) || str_contains($value, "\0")) {
            throw InvalidConfig::performance($path, $key.' must be a path inside the repository, relative to its root');
        }

        return $value;
    }
}
