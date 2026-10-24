<?php

declare(strict_types=1);

namespace Pollora\Harness;

/**
 * The kind of repository the harness is applied to.
 *
 * The profile decides which gates run and which thresholds apply, so that a
 * repository only declares what it is, never how it should be checked.
 */
enum Profile: string
{
    /** A standalone package that must stay usable without Laravel. */
    case Core = 'core';

    /** The pollora/framework repository itself. */
    case Framework = 'framework';

    /** A package that depends on pollora/framework (bridges, debug tools, AI context). */
    case Integration = 'integration';

    /** A WordPress plugin distributed on its own. */
    case WpPlugin = 'wp-plugin';

    /** A theme, plugin or module template, or the application skeleton. */
    case Template = 'template';

    /** A client project built on Pollora. */
    case Project = 'project';

    /**
     * The PHPStan level the profile aims for. The current level of a
     * repository lives in its ratchet and only ever moves up towards this.
     */
    public function targetPhpstanLevel(): int|string
    {
        return match ($this) {
            self::Core, self::WpPlugin => 'max',
            self::Framework, self::Integration => 8,
            self::Template, self::Project => 6,
        };
    }

    /** Whether ARCH-04 applies: no dependency on Laravel. */
    public function mustStayLaravelFree(): bool
    {
        return $this === self::Core;
    }

    /** Whether ARCH-06 allows a dependency on pollora/framework. */
    public function mayDependOnFramework(): bool
    {
        return in_array($this, [self::Integration, self::Template, self::Project], true);
    }

    /** Whether the hexagonal rules (ARCH-01 to ARCH-03) apply. */
    public function isHexagonal(): bool
    {
        return in_array($this, [self::Core, self::Framework], true);
    }

    /** Whether the downstream job should run the framework against this repository. */
    public function hasDownstream(): bool
    {
        return $this === self::Core;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $profile): string => $profile->value, self::cases());
    }
}
