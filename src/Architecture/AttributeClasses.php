<?php

declare(strict_types=1);

namespace Pollora\Harness\Architecture;

use Attribute;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionProperty;

/**
 * ARCH-08: attribute classes are final, immutable and declare their targets.
 *
 * Attributes are the heart of Pollora's public API. A final, readonly
 * attribute can be read the same way everywhere, and an explicit target
 * turns a misplaced attribute into an error at reflection time instead of a
 * silent no-op.
 */
final class AttributeClasses
{
    /**
     * @return list<string> One line per problem, naming the class
     */
    public static function problems(Layers $layers): array
    {
        $problems = [];

        foreach (array_keys($layers->files) as $class) {
            if (! self::loads($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);
            $declaration = $reflection->getAttributes(Attribute::class)[0] ?? null;

            if (! $declaration instanceof ReflectionAttribute) {
                continue;
            }

            array_push($problems, ...self::problemsOf($reflection, $declaration));
        }

        return $problems;
    }

    /**
     * @param  ReflectionClass<object>  $class
     * @param  ReflectionAttribute<Attribute>  $declaration
     * @return list<string>
     */
    private static function problemsOf(ReflectionClass $class, ReflectionAttribute $declaration): array
    {
        $problems = [];
        $name = $class->getName();

        if (! $class->isFinal()) {
            $problems[] = "{$name} is not final.";
        }

        if (! $class->isReadOnly()) {
            $mutable = array_filter(
                $class->getProperties(ReflectionProperty::IS_PUBLIC),
                static fn (ReflectionProperty $property): bool => ! $property->isReadOnly() && ! $property->isStatic(),
            );

            foreach ($mutable as $property) {
                $problems[] = "{$name}::\${$property->getName()} is public and not readonly.";
            }
        }

        $flags = $declaration->getArguments()[0] ?? $declaration->getArguments()['flags'] ?? Attribute::TARGET_ALL;
        $flags = is_int($flags) ? $flags : Attribute::TARGET_ALL;

        if (($flags & Attribute::TARGET_ALL) === Attribute::TARGET_ALL) {
            $problems[] = "{$name} does not declare its targets: use #[Attribute(Attribute::TARGET_CLASS)] or the targets it supports.";
        }

        return $problems;
    }

    /**
     * Autoloads the class without letting a file that is not a class (a
     * helper file, a stub) stop the check.
     *
     * @phpstan-assert-if-true class-string $class
     */
    private static function loads(string $class): bool
    {
        try {
            return class_exists($class) && ! (new ReflectionClass($class))->isInterface();
        } catch (\Throwable) {
            return false;
        }
    }
}
