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
 *
 * Only a file that declares a class, interface, trait or enum is checked: a
 * Blade template kept under src/ (`*.blade.php`) or a script without a type
 * is not a class, and Pest never asked for it before.
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

            if (str_ends_with($path, '.blade.php')) {
                continue;
            }

            $source = (string) file_get_contents($path);

            if (! self::declaresType($source)) {
                continue;
            }

            if (preg_match(self::DECLARATION, $source) !== 1) {
                $violations[] = $class;
            }
        }

        return $violations;
    }

    /**
     * Whether the source declares a class, interface, trait or enum.
     *
     * Read from the tokens, so `Foo::class`, `new class {}`, and the words
     * "class" or "enum" in a string or a comment do not count.
     */
    public static function declaresType(string $source): bool
    {
        $previous = null;

        foreach (token_get_all($source) as $token) {
            if (! is_array($token) || in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $declares = in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)
                && ! in_array($previous, [T_DOUBLE_COLON, T_NEW, T_NULLSAFE_OBJECT_OPERATOR, T_OBJECT_OPERATOR], true);

            if ($declares) {
                return true;
            }

            $previous = $token[0];
        }

        return false;
    }
}
