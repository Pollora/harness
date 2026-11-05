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
     * @param  string|null  $note  Said before the step runs, when it does not run the way it usually does
     * @param  list<string>  $exempted  The rules of this step that `.harness.json` exempts: the step can pass without having evaluated them
     */
    public function __construct(
        public string $name,
        public array $rules,
        public array|Closure $action,
        public array $requires = [],
        public ?string $skipReason = null,
        public ?string $install = null,
        public ?string $note = null,
        public array $exempted = [],
    ) {}

    /**
     * "1 rule exempted: ARCH-08", or null when the step exempts nothing.
     */
    public function exemptionSummary(): ?string
    {
        if ($this->exempted === []) {
            return null;
        }

        return sprintf('%d %s exempted: %s', count($this->exempted), count($this->exempted) === 1 ? 'rule' : 'rules', implode(', ', $this->exempted));
    }

    /**
     * @param  list<string>  $rules
     */
    public static function skipped(string $name, array $rules, string $reason): self
    {
        return new self($name, $rules, [], skipReason: $reason);
    }

    /**
     * A step that cannot run here because a tool is not installed.
     *
     * @param  list<string>  $rules
     * @param  string  $install  The command that installs the tool, on one line
     */
    public static function missingTool(string $name, array $rules, string $reason, string $install): self
    {
        return new self($name, $rules, [], skipReason: $reason, install: $install);
    }
}
