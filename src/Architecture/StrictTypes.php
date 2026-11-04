<?php

declare(strict_types=1);

namespace Pollora\Harness\Architecture;

/**
 * ARCH-09: every PHP file of the repository declares strict types.
 *
 * The check reads the files found under the repository's own PSR-4 roots
 * instead of asking Pest's `toUseStrictTypes()` for the classes of a
 * namespace. Pest resolves a namespace through the whole autoloader, so a
 * root shared with a dependency (Pollora\Schedule is also mapped by
 * pollora/attributes) brings vendor classes into the expectation, and those
 * have no file path to read: the rule then crashed instead of reporting.
 */
final class StrictTypes
{
    private const string DECLARATION = '/^<\?php\s*(\/\*[\s\S]*?\*\/|\/\/[^\r\n]*(?:\r?\n|$)|\s)*declare\s*\(\s*strict_types\s*=\s*1\s*\)\s*;/m';

    /**
     * @return list<string> The classes of $root whose file does not declare strict types
     */
    public static function violations(Layers $layers, string $root): array
    {
        $violations = [];

        foreach ($layers->files as $class => $path) {
            if (! str_starts_with($class, $root.'\\')) {
                continue;
            }

            if (preg_match(self::DECLARATION, (string) file_get_contents($path)) !== 1) {
                $violations[] = $class;
            }
        }

        return $violations;
    }
}
