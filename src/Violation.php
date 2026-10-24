<?php

declare(strict_types=1);

namespace Pollora\Harness;

/**
 * A broken rule, with what to do about it.
 */
final readonly class Violation implements \Stringable
{
    public function __construct(
        public string $rule,
        public string $message,
        public string $fix,
    ) {}

    public function __toString(): string
    {
        return "[{$this->rule}] {$this->message}\n          Fix: {$this->fix}";
    }
}
