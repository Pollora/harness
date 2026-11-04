<?php

declare(strict_types=1);

namespace Pollora\Harness\Check;

final readonly class StepResult
{
    public const string PASSED = 'passed';

    public const string FAILED = 'failed';

    public const string SKIPPED = 'skipped';

    public function __construct(
        public string $status,
        public string $detail = '',
        public float $seconds = 0.0,
        public ?string $install = null,
    ) {}

    public static function passed(string $detail = ''): self
    {
        return new self(self::PASSED, $detail);
    }

    public static function failed(string $detail): self
    {
        return new self(self::FAILED, $detail);
    }

    public static function skipped(string $reason): self
    {
        return new self(self::SKIPPED, $reason);
    }

    /**
     * A gate that did not run because a tool it needs is not installed: not a pass, and what `check --strict` refuses.
     */
    public static function missingTool(string $reason, string $install): self
    {
        return new self(self::SKIPPED, $reason, install: $install);
    }

    public function withSeconds(float $seconds): self
    {
        return new self($this->status, $this->detail, $seconds, $this->install);
    }

    public function isMissingTool(): bool
    {
        return $this->status === self::SKIPPED && $this->install !== null;
    }

    public function isFailed(): bool
    {
        return $this->status === self::FAILED;
    }
}
