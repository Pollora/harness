<?php

declare(strict_types=1);

namespace Pollora\Harness\Architecture;

use Pollora\Harness\Config\RuleExemption;

/**
 * What one per-class exemption of `allow_rules` does to the problems of its rule.
 */
final readonly class ExemptionEffect
{
    /**
     * @param  list<string>  $classes  The classes whose problems the exemption removes (or would remove, while the rule is observed)
     * @param  list<string>  $stale  Patterns that match no class of the repository
     * @param  list<string>  $unused  Patterns that match a class of the repository, but none that the rule reports
     */
    public function __construct(
        public RuleExemption $exemption,
        public array $classes,
        public int $problems,
        public array $stale,
        public array $unused,
    ) {}

    /**
     * The line the gate and the skipped test print: which rule, why, and how many classes (named).
     *
     * @param  bool  $applied  False while the rule is observed: the exemption is declared, not applied
     */
    public function message(bool $applied = true): string
    {
        $count = count($this->classes);
        $named = $count === 0 ? '' : ': '.implode(', ', $this->classes);
        $prefix = $applied ? 'exempted' : 'declared, not applied while the rule is observed';

        return sprintf('%s: %s — %s — %d %s%s', $prefix, $this->exemption->rule, $this->exemption->reason, $count, $count === 1 ? 'class' : 'classes', $named);
    }

    /**
     * One warning per pattern that exempts nothing, so that an exemption cannot outlive the class it was written for.
     *
     * @return list<string>
     */
    public function warnings(): array
    {
        $warnings = [];

        foreach ($this->stale as $pattern) {
            $warnings[] = "stale exemption: {$this->exemption->rule} — \"{$pattern}\" matches no class of the repository (remove it).";
        }

        foreach ($this->unused as $pattern) {
            $warnings[] = "stale exemption: {$this->exemption->rule} — \"{$pattern}\" matches no class that the rule reports (remove it).";
        }

        return $warnings;
    }
}
