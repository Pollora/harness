<?php

declare(strict_types=1);

namespace Pollora\Harness\Architecture;

/**
 * The problems of a rule once its per-class exemptions are applied.
 */
final readonly class Exempted
{
    /**
     * @param  list<string>  $all  Every problem the rule reports
     * @param  list<string>  $kept  The problems that remain: what the rule blocks on
     * @param  list<ExemptionEffect>  $effects  One per exemption, in the order of `allow_rules`
     */
    public function __construct(
        public array $all,
        public array $kept,
        public array $effects,
    ) {}

    /** How many problems the exemptions remove. */
    public function exemptedProblems(): int
    {
        return count($this->all) - count($this->kept);
    }

    /**
     * The classes exempted, each counted once per rule (the figure the ratchet's `exempted_classes` records).
     *
     * @return list<string>
     */
    public function classes(): array
    {
        $classes = [];

        foreach ($this->effects as $effect) {
            foreach ($effect->classes as $class) {
                $classes[$class] = $class;
            }
        }

        return array_values($classes);
    }

    /**
     * @return list<string>
     */
    public function warnings(): array
    {
        return array_merge(...array_map(static fn (ExemptionEffect $effect): array => $effect->warnings(), $this->effects));
    }
}
