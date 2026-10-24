<?php

declare(strict_types=1);

namespace Pollora\Harness\Check;

use Closure;

/**
 * One gate of `pollora-qa check`: a command to run, or a check done in
 * process, with the rules it enforces.
 */
final readonly class Step
{
    /**
     * @param  list<string>  $rules  The rule identifiers this step enforces
     * @param  list<string>|Closure(): StepResult  $action  A command line, or an in-process check
     * @param  list<string>  $requires  Files that must exist for the step to run (a tool binary, a config file)
     */
    public function __construct(
        public string $name,
        public array $rules,
        public array|Closure $action,
        public array $requires = [],
        public ?string $skipReason = null,
    ) {}

    /**
     * @param  list<string>  $rules
     */
    public static function skipped(string $name, array $rules, string $reason): self
    {
        return new self($name, $rules, [], skipReason: $reason);
    }
}
