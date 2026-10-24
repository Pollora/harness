<?php

declare(strict_types=1);

namespace Pollora\Harness;

/**
 * A broken rule, with what to do about it.
 */
final class Violation
{
    public function __construct(
        public readonly string $rule,
        public readonly string $message,
        public readonly string $fix,
    ) {}

    public function __toString(): string
    {
        return "[{$this->rule}] {$this->message}\n          Fix: {$this->fix}";
    }
}
