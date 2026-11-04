<?php

declare(strict_types=1);

namespace Pollora\Harness\Config;

use Pollora\Harness\Profile;
use RuntimeException;

final class InvalidConfig extends RuntimeException
{
    public static function missing(string $path): self
    {
        return new self("{$path} not found. Run `vendor/bin/pollora-qa init --profile=<profile>` to create it.");
    }

    public static function unreadable(string $path, string $reason): self
    {
        return new self("{$path} is not valid JSON: {$reason}.");
    }

    public static function unknownProfile(string $path, mixed $profile): self
    {
        $given = is_scalar($profile) ? (string) $profile : get_debug_type($profile);

        return new self(sprintf(
            '%s declares the profile "%s". Use one of: %s.',
            $path,
            $given,
            implode(', ', Profile::values()),
        ));
    }

    public static function unknownMode(string $path, mixed $mode): self
    {
        $given = is_scalar($mode) ? (string) $mode : get_debug_type($mode);

        return new self("{$path} declares the mode \"{$given}\". Use \"observe\" or \"enforce\".");
    }

    public static function exemptionWithoutReason(string $path, mixed $target): self
    {
        $name = is_string($target) ? "\"{$target}\"" : 'an entry';

        return new self("{$path}: allow_commands lists {$name} without a reason. Say why the command keeps its name.");
    }

    public static function exemptionWithoutTarget(string $path): self
    {
        return new self("{$path}: each allow_commands entry needs either \"command\" (a name) or \"prefix\" (the start of names), not both, and a \"reason\".");
    }

    public static function ruleExemptionWithoutRule(string $path): self
    {
        return new self("{$path}: each allow_rules entry needs a \"rule\" (such as \"ARCH-08\") and a \"reason\".");
    }

    public static function unknownRule(string $path, string $rule): self
    {
        return new self("{$path}: allow_rules lists \"{$rule}\", which is not a rule of the catalogue. List them with `vendor/bin/pollora-qa rules`.");
    }

    public static function ruleNotExemptible(string $path, string $rule): self
    {
        return new self(sprintf(
            '%s: allow_rules lists "%s", which tests/Arch.php does not evaluate. Only %s can be exempted there.',
            $path,
            $rule,
            implode(', ', RuleExemption::EXEMPTIBLE),
        ));
    }

    public static function ruleExemptionWithoutReason(string $path, string $rule): self
    {
        return new self("{$path}: allow_rules exempts \"{$rule}\" without a reason. Say why the rule cannot hold.");
    }

    public static function duplicateRuleExemption(string $path, string $rule): self
    {
        return new self("{$path}: allow_rules lists \"{$rule}\" twice. Keep one entry and its reason.");
    }
}
