<?php

declare(strict_types=1);

namespace Pollora\Harness\Config;

/**
 * What `.harness.json` says about mutation testing under its `mutation` key.
 *
 * Two shapes exist. By default (`scope: "all"`, or no `mutation` key) `check --mutation` mutates the whole
 * repository and compares its score with the ratchet's `msi`, a number that may only go up. A repository whose
 * mutation run is too long for a pull request's CI (the framework) declares `scope: "changed"` instead: the run
 * mutates only the source files the change touches and the score of those files must reach a fixed `min`.
 *
 * That minimum is a threshold, not a ratchet: the score of some files says nothing about the score of the next
 * pull request's files, so it is never recorded, tightened or compared with `ratchet.msi`.
 *
 * `scope: "added"` goes one step further for a repository whose existing files score unevenly: only the files the
 * change adds are mutated and held to `min`, so a change never fails on a mutant that was already alive in a file it
 * only edits.
 *
 * ```json
 * "mutation": { "scope": "added", "min": 80 }
 * ```
 *
 * `scope: "changed-relative"` is the one that also holds the files a change EDITS: each modified file is measured
 * on the base and on the change, and the change must not lower its score by more than `tolerance` points. A new
 * file is still held to `min`. The run is two Pest passes per file, so `max_files` and `max_minutes` cap its cost,
 * and a file with fewer than `min_mutants` mutants is listed, not judged.
 *
 * ```json
 * "mutation": { "scope": "changed-relative", "min": 80, "tolerance": 2, "min_mutants": 5, "max_files": 3, "max_minutes": 15 }
 * ```
 */
final readonly class MutationSettings
{
    /** Mutate the whole repository and feed the ratchet's `msi`. */
    public const string ALL = 'all';

    /** Mutate the files a change touches and hold their score to `min`. */
    public const string CHANGED = 'changed';

    /** Mutate only the files a change adds and hold their score to `min`; the files it modifies are not mutated. */
    public const string ADDED = 'added';

    /** Measure each modified file on the base and on the change, and refuse a drop beyond `tolerance`; new files are held to `min`. */
    public const string RELATIVE = 'changed-relative';

    /** Every scope `mutation.scope` and `--mutation-scope` accept. */
    public const array SCOPES = [self::ALL, self::CHANGED, self::ADDED, self::RELATIVE];

    /**
     * Points a file's score may lose between the base and the change before `changed-relative` refuses it.
     *
     * A score moves without any change to the code: a mutant that loops until Pest's timeout counts as killed or not
     * depending on the load of the machine (89.18 % and 90.53 % on the same code, 1.35 points). 2 points absorbs that,
     * and is one mutant out of 50: a file with fewer mutants sees every mutant it loses (1 out of 8 is 12.5 points).
     */
    public const float DEFAULT_TOLERANCE = 2.0;

    /**
     * Mutants a file needs, on the base and on the change, to be judged by `changed-relative`. A file below it is
     * listed as too small to judge and neither fails nor counts as green; 8 mutants are judged, 3 are not.
     */
    public const int DEFAULT_MIN_MUTANTS = 5;

    /** Files `changed-relative` measures at most; a change that touches more fails, saying so, instead of measuring some of them. */
    public const int DEFAULT_MAX_FILES = 3;

    /** Minutes `changed-relative` may spend measuring; the run stops before a pass it expects to overrun, and fails. */
    public const int DEFAULT_MAX_MINUTES = 15;

    /**
     * The score a limited run (`changed` or `added`) is held to when nothing says otherwise: neither `mutation.min`
     * nor a figure the caller chose. Deliberately low. Mutation scores are measured on whole files and the files of a
     * repository score unevenly (51 % to 100 % in the framework), so a high default would fail pull requests for
     * mutants they did not write; 50 % still catches a file whose tests barely notice a change.
     */
    public const float PULL_REQUEST_MINIMUM = 50.0;

    /**
     * @param  'all'|'changed'|'added'|'changed-relative'  $scope  What `check --mutation` mutates
     * @param  float|null  $min  Score (percent, 0 to 100) the changed files must reach; null only reports it. Meaningful for `changed`, `added` and, for new files, `changed-relative`
     * @param  float  $tolerance  `changed-relative`: points a modified file's score may lose (0 to 100)
     * @param  int  $minMutants  `changed-relative`: mutants a file needs on the base and on the change to be judged (at least 1)
     * @param  int  $maxFiles  `changed-relative`: files measured at most (at least 1)
     * @param  int  $maxMinutes  `changed-relative`: minutes spent measuring at most (at least 1)
     */
    public function __construct(
        public string $scope = self::ALL,
        public ?float $min = null,
        public float $tolerance = self::DEFAULT_TOLERANCE,
        public int $minMutants = self::DEFAULT_MIN_MUTANTS,
        public int $maxFiles = self::DEFAULT_MAX_FILES,
        public int $maxMinutes = self::DEFAULT_MAX_MINUTES,
    ) {}

    /**
     * @throws InvalidConfig
     */
    public static function fromArray(mixed $data, string $path): self
    {
        if (! is_array($data)) {
            return new self;
        }

        $scope = $data['scope'] ?? self::ALL;

        if (! in_array($scope, self::SCOPES, true)) {
            throw InvalidConfig::unknownMutationScope($path, $scope);
        }

        $min = $data['min'] ?? null;

        if ($min !== null && (! is_int($min) && ! is_float($min) || $min < 0 || $min > 100)) {
            throw InvalidConfig::invalidMutationMinimum($path, $min);
        }

        $tolerance = $data['tolerance'] ?? self::DEFAULT_TOLERANCE;

        if (! is_int($tolerance) && ! is_float($tolerance) || $tolerance < 0 || $tolerance > 100) {
            throw InvalidConfig::invalidMutationOption($path, 'tolerance', $tolerance, 'a number of points between 0 and 100');
        }

        $counts = [];

        foreach (['min_mutants' => self::DEFAULT_MIN_MUTANTS, 'max_files' => self::DEFAULT_MAX_FILES, 'max_minutes' => self::DEFAULT_MAX_MINUTES] as $key => $default) {
            $value = $data[$key] ?? $default;

            if (! is_int($value) || $value < 1) {
                throw InvalidConfig::invalidMutationOption($path, $key, $value, 'a whole number, at least 1');
            }

            $counts[$key] = $value;
        }

        return new self($scope, $min === null ? null : (float) $min, (float) $tolerance, $counts['min_mutants'], $counts['max_files'], $counts['max_minutes']);
    }

    /**
     * The settings for one run whose scope the command line chose (`check --mutation-scope=`), which wins over `.harness.json`.
     *
     * A limited scope keeps `mutation.min` when the file sets one and otherwise falls back to
     * `PULL_REQUEST_MINIMUM`, so that choosing the scope never leaves the run without a threshold. `all` keeps
     * the settings as they are: the whole-repository run is compared with the ratchet, not with `min`.
     *
     * @param  'all'|'changed'|'added'|'changed-relative'  $scope
     */
    public function withScope(string $scope): self
    {
        return new self($scope, $scope === self::ALL ? $this->min : ($this->min ?? self::PULL_REQUEST_MINIMUM), $this->tolerance, $this->minMutants, $this->maxFiles, $this->maxMinutes);
    }

    /**
     * Whether a `--mutation-scope` value names a scope.
     *
     * @phpstan-assert-if-true 'all'|'changed'|'added'|'changed-relative' $scope
     */
    public static function isScope(string $scope): bool
    {
        return in_array($scope, self::SCOPES, true);
    }

    /**
     * Whether `check --mutation` is limited to the files the change touches.
     */
    public function changedOnly(): bool
    {
        return $this->scope === self::CHANGED;
    }

    /**
     * Whether `check --mutation` is limited to the files the change adds.
     */
    public function addedOnly(): bool
    {
        return $this->scope === self::ADDED;
    }

    /**
     * Whether `check --mutation` compares the score of each modified file with its score on the base.
     */
    public function relative(): bool
    {
        return $this->scope === self::RELATIVE;
    }

    /**
     * Whether `check --mutation` mutates only some files of the change (`changed`, `added` or `changed-relative`), not the repository.
     */
    public function limited(): bool
    {
        return $this->changedOnly() || $this->addedOnly() || $this->relative();
    }

    /**
     * @return array<string, int|float|string>|null null when nothing differs from the default; the `changed-relative` options only when they differ from their defaults
     */
    public function toArray(): ?array
    {
        if (! $this->limited() && $this->min === null) {
            return null;
        }

        return array_filter([
            'scope' => $this->scope,
            'min' => $this->min === null ? null : self::compact($this->min),
            'tolerance' => $this->tolerance === self::DEFAULT_TOLERANCE ? null : self::compact($this->tolerance),
            'min_mutants' => $this->minMutants === self::DEFAULT_MIN_MUTANTS ? null : $this->minMutants,
            'max_files' => $this->maxFiles === self::DEFAULT_MAX_FILES ? null : $this->maxFiles,
            'max_minutes' => $this->maxMinutes === self::DEFAULT_MAX_MINUTES ? null : $this->maxMinutes,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * A whole number as an int, so that `80.0` is written `80`.
     */
    private static function compact(float $value): int|float
    {
        return $value === floor($value) ? (int) $value : $value;
    }
}
