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
 */
final readonly class MutationSettings
{
    /** Mutate the whole repository and feed the ratchet's `msi`. */
    public const string ALL = 'all';

    /** Mutate the files a change touches and hold their score to `min`. */
    public const string CHANGED = 'changed';

    /** Mutate only the files a change adds and hold their score to `min`; the files it modifies are not mutated. */
    public const string ADDED = 'added';

    /**
     * @param  'all'|'changed'|'added'  $scope  What `check --mutation` mutates
     * @param  float|null  $min  Score (percent, 0 to 100) the changed files must reach; null only reports it. Meaningful for `changed`
     */
    public function __construct(
        public string $scope = self::ALL,
        public ?float $min = null,
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

        if (! in_array($scope, [self::ALL, self::CHANGED, self::ADDED], true)) {
            throw InvalidConfig::unknownMutationScope($path, $scope);
        }

        $min = $data['min'] ?? null;

        if ($min !== null && (! is_int($min) && ! is_float($min) || $min < 0 || $min > 100)) {
            throw InvalidConfig::invalidMutationMinimum($path, $min);
        }

        return new self($scope, $min === null ? null : (float) $min);
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
     * Whether `check --mutation` mutates only some files of the change (`changed` or `added`), not the repository.
     */
    public function limited(): bool
    {
        return $this->changedOnly() || $this->addedOnly();
    }

    /**
     * @return array{scope?: string, min?: int|float}|null null when nothing differs from the default
     */
    public function toArray(): ?array
    {
        if (! $this->limited() && $this->min === null) {
            return null;
        }

        return array_filter([
            'scope' => $this->scope,
            'min' => $this->min === null ? null : ($this->min === floor($this->min) ? (int) $this->min : $this->min),
        ], static fn (mixed $value): bool => $value !== null);
    }
}
