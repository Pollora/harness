<?php

declare(strict_types=1);

namespace Pollora\Harness\Config;

use Pollora\Harness\Rules\Catalogue;

/**
 * An architecture rule of tests/Arch.php that a repository does not satisfy
 * yet, with the reason it is exempted.
 *
 * It is written in `.harness.json` as `{"rule": "ARCH-08", "reason": "…"}`.
 * The whole rule is exempted (its tests are reported as skipped, with the
 * reason); every other rule keeps running. The reason is required: nothing is
 * exempted without a trace.
 *
 * A rule that judges classes read from the files (CONV-02, CONV-08, CONV-09) can be exempted for some
 * classes only: `{"rule": "CONV-08", "reason": "…", "classes": ["Acme\\Models\\*", "Acme\\Foo"]}`.
 * A pattern is a fully qualified class name or a namespace prefix ending in `\\*`; nothing else (no
 * regular expression, no bare `*`). The rule keeps running on every other class. An entry without
 * `classes` exempts the whole rule, exactly as before.
 */
final readonly class RuleExemption
{
    /** The rules `Architecture::register()` evaluates; the others belong to another gate. */
    public const array EXEMPTIBLE = ['ARCH-01', 'ARCH-03', 'ARCH-08', 'ARCH-09', 'CONV-02', 'CONV-03', 'CONV-05', 'CONV-07', 'CONV-08', 'CONV-09', 'CONV-12'];

    /** The rules that can be exempted for some classes only: those that report problems class by class from the files. */
    public const array CLASS_EXEMPTIBLE = ['CONV-02', 'CONV-08', 'CONV-09'];

    /**
     * @param  list<string>  $classes  Class names or `Namespace\*` prefixes the exemption is limited to; empty: the whole rule
     */
    public function __construct(
        public string $rule,
        public string $reason,
        public array $classes = [],
    ) {}

    /**
     * @param  array<mixed>  $data
     *
     * @throws InvalidConfig
     */
    public static function fromArray(array $data, string $path): self
    {
        $rule = $data['rule'] ?? null;
        $reason = $data['reason'] ?? null;

        if (! is_string($rule) || trim($rule) === '') {
            throw InvalidConfig::ruleExemptionWithoutRule($path);
        }

        $rule = trim($rule);

        if (! array_key_exists($rule, Catalogue::all())) {
            throw InvalidConfig::unknownRule($path, $rule);
        }

        if (! in_array($rule, self::EXEMPTIBLE, true)) {
            throw InvalidConfig::ruleNotExemptible($path, $rule);
        }

        if (! is_string($reason) || trim($reason) === '') {
            throw InvalidConfig::ruleExemptionWithoutReason($path, $rule);
        }

        return new self($rule, trim($reason), self::classes($data['classes'] ?? null, $rule, $path));
    }

    /** Whether the exemption is limited to some classes instead of covering the whole rule. */
    public function isPartial(): bool
    {
        return $this->classes !== [];
    }

    /**
     * Whether the exemption changes what the rule does today.
     *
     * A rule the catalogue lists as `observed` never blocks, so exempting it would hide its findings for
     * nothing: the exemption is accepted and counted by the ratchet (so a repository can declare it, with its
     * reason, before the rule is promoted) but the rule keeps running and reporting until it is `enforced`.
     */
    public function applies(): bool
    {
        return (Catalogue::all()[$this->rule]['status'] ?? 'enforced') !== 'observed';
    }

    /**
     * What the gate and the skipped test say.
     */
    public function message(): string
    {
        if (! $this->applies()) {
            return "declared, not applied while the rule is observed: {$this->rule} — {$this->reason}";
        }

        return "exempted: {$this->rule} — {$this->reason}";
    }

    /**
     * @return array{rule: string, reason: string, classes?: list<string>}
     */
    public function toArray(): array
    {
        return $this->classes === [] ? ['rule' => $this->rule, 'reason' => $this->reason] : ['rule' => $this->rule, 'reason' => $this->reason, 'classes' => $this->classes];
    }

    /**
     * Reads the optional `classes` of an entry: absent means the whole rule.
     *
     * @return list<string>
     *
     * @throws InvalidConfig
     */
    private static function classes(mixed $value, string $rule, string $path): array
    {
        if ($value === null) {
            return [];
        }

        if (! in_array($rule, self::CLASS_EXEMPTIBLE, true)) {
            throw InvalidConfig::classesNotSupported($path, $rule);
        }

        if (! is_array($value) || $value === [] || ! array_is_list($value)) {
            throw InvalidConfig::classesNotAList($path, $rule);
        }

        $classes = [];

        foreach ($value as $written) {
            $pattern = is_string($written) ? ltrim(trim($written), '\\') : '';

            if (! ClassPattern::isValid($pattern)) {
                throw InvalidConfig::invalidClassPattern($path, $rule, is_string($written) ? $written : get_debug_type($written));
            }

            $classes[$pattern] = $pattern;
        }

        return array_values($classes);
    }
}
