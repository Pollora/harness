<?php

declare(strict_types=1);

namespace Pollora\Harness\Config;

/**
 * Whether a failing gate fails the build.
 *
 * Every repository starts in observe mode: the harness measures and reports
 * without blocking anything, so the reference values of the ratchet are
 * known before any rule can stop a merge.
 */
enum Mode: string
{
    case Observe = 'observe';
    case Enforce = 'enforce';

    public function blocks(): bool
    {
        return $this === self::Enforce;
    }
}
