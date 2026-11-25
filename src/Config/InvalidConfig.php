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

    public static function notATemplateProfile(string $path, Profile $profile): self
    {
        return new self(sprintf('%s declares the profile "%s", but template.yml checks a template: use "template" (or "project"), or leave the profile out.', $path, $profile->value));
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

    public static function classesNotSupported(string $path, string $rule): self
    {
        return new self(sprintf(
            '%s: allow_rules limits "%s" to classes, but only %s report problems class by class. Remove "classes" to exempt the whole rule.',
            $path,
            $rule,
            implode(', ', RuleExemption::CLASS_EXEMPTIBLE),
        ));
    }

    public static function classesNotAList(string $path, string $rule): self
    {
        return new self("{$path}: the \"classes\" of the allow_rules entry for \"{$rule}\" must be a non-empty list of class names or namespace prefixes. Remove \"classes\" to exempt the whole rule.");
    }

    public static function invalidClassPattern(string $path, string $rule, string $pattern): self
    {
        return new self("{$path}: allow_rules \"{$rule}\" lists the class pattern \"{$pattern}\", which is neither a fully qualified class name (Acme\\Foo) nor a namespace prefix ending in \\* (Acme\\Models\\*). No other pattern is supported.");
    }

    public static function ruleExemptionConflict(string $path, string $rule): self
    {
        return new self("{$path}: allow_rules exempts \"{$rule}\" as a whole and for some classes. Keep one: the whole rule, or the classes.");
    }

    public static function duplicateRuleExemption(string $path, string $rule): self
    {
        return new self("{$path}: allow_rules lists \"{$rule}\" twice. Keep one entry and its reason.");
    }

    public static function unknownMutationScope(string $path, mixed $scope): self
    {
        $given = is_scalar($scope) ? (string) $scope : get_debug_type($scope);

        return new self("{$path}: mutation.scope is \"{$given}\". Use \"all\" (the whole repository, compared with ratchet.msi), \"changed\" (the files a change touches, held to mutation.min), \"added\" (only the files it adds) or \"changed-relative\" (the files it touches, each compared with its score on the base).");
    }

    public static function invalidMutationOption(string $path, string $key, mixed $value, string $expected): self
    {
        $given = is_scalar($value) ? (string) $value : get_debug_type($value);

        return new self("{$path}: mutation.{$key} is \"{$given}\". Give {$expected}.");
    }

    public static function invalidMutationMinimum(string $path, mixed $min): self
    {
        $given = is_scalar($min) ? (string) $min : get_debug_type($min);

        return new self("{$path}: mutation.min is \"{$given}\". Give a score between 0 and 100.");
    }

    public static function unknownService(string $path, mixed $service): self
    {
        $given = is_scalar($service) ? (string) $service : get_debug_type($service);

        return new self("{$path}: services lists \"{$given}\". The CI can start: ".implode(', ', HarnessConfig::SERVICES).'.');
    }

    public static function ratchetValue(string $path, string $metric, mixed $value, string $expected): self
    {
        $given = is_scalar($value) ? var_export($value, true) : get_debug_type($value);

        return new self("{$path}: ratchet.{$metric} is {$given}. Give {$expected}. A value the ratchet cannot read would never be compared, so it is refused instead of ignored.");
    }

    public static function slowStepShare(string $path, mixed $share): self
    {
        $given = is_scalar($share) ? var_export($share, true) : get_debug_type($share);

        return new self("{$path}: slow_step_share is {$given}. Give a share of the total time above 0 and up to 1, e.g. 0.5.");
    }

    public static function npmAuditMalformed(string $path): self
    {
        return new self("{$path}: npm_audit must be an object with an \"allow\" list of {\"advisory\", \"reason\", \"expires\"} entries.");
    }

    public static function npmAuditAdvisory(string $path, mixed $advisory): self
    {
        $given = is_scalar($advisory) ? (string) $advisory : get_debug_type($advisory);

        return new self("{$path}: npm_audit.allow lists \"{$given}\", which is not a GitHub advisory identifier (GHSA-xxxx-xxxx-xxxx).");
    }

    public static function npmAuditWithoutReason(string $path, string $advisory): self
    {
        return new self("{$path}: npm_audit.allow lists {$advisory} without a reason. Say why the advisory is tolerated.");
    }

    public static function npmAuditExpiry(string $path, string $advisory, mixed $expires): self
    {
        $given = is_scalar($expires) ? (string) $expires : get_debug_type($expires);

        return new self("{$path}: npm_audit.allow lists {$advisory} with the expiry \"{$given}\". Use a calendar date written YYYY-MM-DD.");
    }

    public static function npmAuditTooFar(string $path, string $advisory, string $latest): self
    {
        return new self(sprintf('%s: npm_audit.allow lists %s with an expiry more than %d days away. The latest date accepted today is %s.', $path, $advisory, NpmAuditSettings::MAX_DAYS, $latest));
    }

    public static function npmAuditDuplicate(string $path, string $advisory): self
    {
        return new self("{$path}: npm_audit.allow lists {$advisory} twice.");
    }

    public static function performance(string $path, string $reason): self
    {
        return new self("{$path}: performance is malformed: {$reason}.");
    }
}
