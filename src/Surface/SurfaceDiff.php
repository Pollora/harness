<?php

declare(strict_types=1);

namespace Pollora\Harness\Surface;

/**
 * What changed between the recorded surface and the code (QA-04).
 *
 * An addition is a new feature: a minor version, the "api-change" label. A
 * removal or a changed entry breaks a project that relied on it: a major
 * version, the "breaking" label. A new required parameter on an attribute
 * that already existed is a break too, since every existing use lacks it.
 */
final readonly class SurfaceDiff
{
    /**
     * @param  array<string, string>  $added
     * @param  array<string, string>  $removed
     * @param  array<string, array{string, string}>  $changed  Key => [recorded, current]
     * @param  list<string>  $breakingAdditions  Added keys that break existing uses
     */
    public function __construct(
        public array $added,
        public array $removed,
        public array $changed,
        public array $breakingAdditions,
    ) {}

    /**
     * @param  array<string, string>  $recorded
     * @param  array<string, string>  $current
     */
    public static function between(array $recorded, array $current): self
    {
        $added = array_diff_key($current, $recorded);
        $removed = array_diff_key($recorded, $current);
        $changed = [];

        foreach (array_intersect_key($current, $recorded) as $key => $description) {
            if ($recorded[$key] !== $description) {
                $changed[$key] = [$recorded[$key], $description];
            }
        }

        $breakingAdditions = [];

        foreach ($added as $key => $description) {
            $owner = strstr($key, '(', true);

            if ($owner !== false && isset($recorded[$owner]) && str_ends_with($description, ', required')) {
                $breakingAdditions[] = $key;
            }
        }

        return new self($added, $removed, $changed, $breakingAdditions);
    }

    public function isEmpty(): bool
    {
        return $this->added === [] && $this->removed === [] && $this->changed === [];
    }

    public function breaks(): bool
    {
        return $this->removed !== [] || $this->changed !== [] || $this->breakingAdditions !== [];
    }

    /**
     * The label a pull request needs for this change: null when none.
     */
    public function requiredLabel(): ?string
    {
        return match (true) {
            $this->breaks() => 'breaking',
            $this->added !== [] => 'api-change',
            default => null,
        };
    }

    /**
     * Whether the change is allowed by what the pull request declares:
     * "none" (no label), "additions" (api-change) or "all" (breaking).
     */
    public function allowedBy(string $allow): bool
    {
        return match ($this->requiredLabel()) {
            null => true,
            'api-change' => in_array($allow, ['additions', 'all'], true),
            default => $allow === 'all',
        };
    }

    /**
     * @return list<string>
     */
    public function lines(): array
    {
        $lines = [];

        foreach ($this->added as $key => $description) {
            $lines[] = (in_array($key, $this->breakingAdditions, true) ? '! ' : '+ ')."{$key}  {$description}";
        }

        foreach ($this->removed as $key => $description) {
            $lines[] = "- {$key}  {$description}";
        }

        foreach ($this->changed as $key => [$before, $after]) {
            $lines[] = "~ {$key}  {$before}  →  {$after}";
        }

        return $lines;
    }
}
