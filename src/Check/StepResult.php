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

    public function withSeconds(float $seconds): self
    {
        return new self($this->status, $this->detail, $seconds);
    }

    public function isFailed(): bool
    {
        return $this->status === self::FAILED;
    }
}
