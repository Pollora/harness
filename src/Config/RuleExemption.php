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
 */
final readonly class RuleExemption
{
    /** The rules `Architecture::register()` evaluates; the others belong to another gate. */
    public const array EXEMPTIBLE = ['ARCH-01', 'ARCH-03', 'ARCH-08', 'ARCH-09', 'CONV-03', 'CONV-05', 'CONV-07'];

    public function __construct(
        public string $rule,
        public string $reason,
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

        return new self($rule, trim($reason));
    }

    /**
     * What the gate and the skipped test say.
     */
    public function message(): string
    {
        return "exempted: {$this->rule} — {$this->reason}";
    }

    /**
     * @return array{rule: string, reason: string}
     */
    public function toArray(): array
    {
        return ['rule' => $this->rule, 'reason' => $this->reason];
    }
}
