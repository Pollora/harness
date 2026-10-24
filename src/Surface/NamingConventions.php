<?php

declare(strict_types=1);

namespace Pollora\Harness\Surface;

/**
 * CONV-04 and CONV-06: the names a project types.
 *
 * Hooks fired by Pollora read pollora/<module>/<event_snake_case>; commands
 * start with pollora:. A name that does not is reported, not refused: renaming
 * it is a breaking change, done with an alias and a deprecation.
 */
final class NamingConventions
{
    private const string HOOK = '~^pollora/[a-z0-9-]+(?:/[a-z0-9_]+)+$~';

    /**
     * @param  array<string, string>  $surface
     * @return list<string>
     */
    public static function problems(array $surface): array
    {
        $problems = [];

        foreach (array_keys($surface) as $key) {
            if (str_starts_with($key, 'hook:') && preg_match(self::HOOK, substr($key, 5)) !== 1) {
                $problems[] = sprintf('[CONV-04] The hook %s should read pollora/<module>/<event_snake_case>.', substr($key, 5));
            }

            if (str_starts_with($key, 'command:') && ! str_starts_with($key, 'command:pollora:')) {
                $problems[] = sprintf('[CONV-06] The command %s should start with pollora:.', substr($key, 8));
            }
        }

        return $problems;
    }
}
