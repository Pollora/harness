<?php

declare(strict_types=1);

namespace Pollora\Harness\Config;

/**
 * A class pattern of an `allow_rules` entry: what a per-class exemption names.
 *
 * Exactly two forms exist, so that an exemption can be read at a glance and never matches more than it says:
 * a fully qualified class name (`Acme\Models\Post`) and a namespace prefix ending in `\*`
 * (`Acme\Models\*`, which covers every class below that namespace, at any depth). There is no regular
 * expression, no wildcard inside a name and no bare `*` (that would be the whole rule: leave `classes` out).
 */
final class ClassPattern
{
    private const string NAME = '[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*';

    /** Whether the text is a class name or a `Namespace\*` prefix (without a leading backslash). */
    public static function isValid(string $pattern): bool
    {
        return preg_match('/^'.self::NAME.'(?:\\\\'.self::NAME.')*(?:\\\\\*)?$/', $pattern) === 1
            && ! str_starts_with($pattern, '\\');
    }

    /** Whether the pattern is a namespace prefix (`Acme\*`) rather than one class. */
    public static function isPrefix(string $pattern): bool
    {
        return str_ends_with($pattern, '\\*');
    }

    /**
     * Whether the pattern covers the class: the same name, or, for a prefix, a class below that namespace.
     */
    public static function matches(string $pattern, string $class): bool
    {
        $class = ltrim($class, '\\');

        if (self::isPrefix($pattern)) {
            return str_starts_with($class, substr($pattern, 0, -1));
        }

        return $class === $pattern;
    }
}
