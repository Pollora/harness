<?php

declare(strict_types=1);

namespace Pollora\Harness\Surface;

use Pollora\Harness\Config\CommandExemption;

/**
 * CONV-04 and CONV-06: the names a project types.
 *
 * Hooks fired by Pollora read pollora/<module>/<event_snake_case>; commands
 * start with pollora:. A name that does not is reported, not refused: renaming
 * it is a breaking change, done with an alias and a deprecation. A command
 * that keeps its name on purpose is listed, with its reason, under
 * `allow_commands` in .harness.json: it is then reported as exempted, and any
 * other command that does not follow the convention still is a problem.
 */
final class NamingConventions
{
    private const string HOOK = '~^pollora/[a-z0-9-]+(?:/[a-z0-9_]+)+$~';

    /**
     * @param  array<string, string>  $surface
     * @param  list<CommandExemption>  $exemptions
     * @return list<string>
     */
    public static function problems(array $surface, array $exemptions = []): array
    {
        $problems = [];

        foreach (array_keys($surface) as $key) {
            if (str_starts_with($key, 'hook:') && preg_match(self::HOOK, substr($key, 5)) !== 1) {
                $problems[] = sprintf('[CONV-04] The hook %s should read pollora/<module>/<event_snake_case>.', substr($key, 5));
            }

            if (str_starts_with($key, 'command:') && ! str_starts_with($key, 'command:pollora:') && ! self::exemption(substr($key, 8), $exemptions) instanceof CommandExemption) {
                $problems[] = sprintf('[CONV-06] The command %s should start with pollora:.', substr($key, 8));
            }
        }

        return $problems;
    }

    /**
     * The commands that do not start with pollora: and that .harness.json lets keep their name.
     *
     * @param  array<string, string>  $surface
     * @param  list<CommandExemption>  $exemptions
     * @return list<string>
     */
    public static function exempted(array $surface, array $exemptions): array
    {
        $lines = [];

        foreach (array_keys($surface) as $key) {
            if (! str_starts_with($key, 'command:') || str_starts_with($key, 'command:pollora:')) {
                continue;
            }

            $exemption = self::exemption(substr($key, 8), $exemptions);

            if ($exemption instanceof CommandExemption) {
                $lines[] = sprintf('[CONV-06] The command %s keeps its name (allow_commands: %s).', substr($key, 8), $exemption->reason);
            }
        }

        return $lines;
    }

    /**
     * @param  list<CommandExemption>  $exemptions
     */
    private static function exemption(string $commandName, array $exemptions): ?CommandExemption
    {
        foreach ($exemptions as $exemption) {
            if ($exemption->matches($commandName)) {
                return $exemption;
            }
        }

        return null;
    }
}
