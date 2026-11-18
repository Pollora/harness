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
 * ```json
 * "mutation": { "scope": "changed", "min": 80 }
 * ```
 */
final readonly class MutationSettings
{
    /** Mutate the whole repository and feed the ratchet's `msi`. */
    public const string ALL = 'all';

    /** Mutate the files a change touches and hold their score to `min`. */
    public const string CHANGED = 'changed';

    /**
     * @param  'all'|'changed'  $scope  What `check --mutation` mutates
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

        if ($scope !== self::ALL && $scope !== self::CHANGED) {
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
     * @return array{scope?: string, min?: int|float}|null null when nothing differs from the default
     */
    public function toArray(): ?array
    {
        if (! $this->changedOnly() && $this->min === null) {
            return null;
        }

        return array_filter([
            'scope' => $this->scope,
            'min' => $this->min === null ? null : ($this->min === floor($this->min) ? (int) $this->min : $this->min),
        ], static fn (mixed $value): bool => $value !== null);
    }
}
