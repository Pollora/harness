<?php

declare(strict_types=1);

namespace Pollora\Harness\Architecture;

/**
 * CONV-08: a class is final unless it is an extension point.
 *
 * An extension point is an abstract class, a builder (a class named `...Builder`, which people chain and
 * sometimes extend), a service behind a facade, which uses `Macroable` so that its users can add methods,
 * or a class that another class of the repository already extends (a base in use: making it final would
 * break that subclass, and the subclass proves that it is meant to be open). Interfaces, traits and enums
 * have no `final`. Everything else is concrete code that nobody should subclass: a `final` class can
 * change without breaking a subclass.
 *
 * Read from the tokens of each file, so it judges a repository whose dependencies are not installed.
 */
final class FinalClasses
{
    /**
     * @return list<string> One line per class that is neither final nor an extension point
     */
    public static function problems(Layers $layers): array
    {
        $problems = [];
        $extended = self::extended($layers);

        foreach ($layers->files as $path) {
            foreach (SourceTypes::ofFile($path) as $type) {
                if (self::isExtensionPoint($type, $extended)) {
                    continue;
                }

                $problems[] = "{$type->name} is not final: make it final, or make it an extension point (abstract, a builder, or a service that uses Macroable).";
            }
        }

        $problems = array_values(array_unique($problems));
        sort($problems);

        return $problems;
    }

    /**
     * Whether the type is allowed to stay open: final already, not a class, abstract, a builder, Macroable
     * or the parent of a class of the repository.
     *
     * @param  array<string, true>  $extended  The classes the repository extends, by name
     */
    public static function isExtensionPoint(TypeDeclaration $type, array $extended = []): bool
    {
        return $type->kind !== 'class'
            || isset($extended[$type->name])
            || $type->final
            || $type->abstract
            || str_ends_with($type->shortName(), 'Builder')
            || $type->usesTraitNamed('Macroable');
    }

    /**
     * @return array<string, true> The parent classes named by a class of the repository
     */
    private static function extended(Layers $layers): array
    {
        $extended = [];

        foreach ($layers->files as $path) {
            foreach (SourceTypes::ofFile($path) as $type) {
                if ($type->extends !== null) {
                    $extended[$type->extends] = true;
                }
            }
        }

        return $extended;
    }
}
