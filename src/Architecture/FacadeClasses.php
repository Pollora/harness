<?php

declare(strict_types=1);

namespace Pollora\Harness\Architecture;

/**
 * CONV-09: a facade is a thin front for an injectable service bound in the container.
 *
 * A facade (a class that extends a `Facade`) holds no logic of its own: its only method is
 * `getFacadeAccessor()`, which returns what the container resolves (`Service::class`, or the name of a
 * binding). A new facade resolves a contract: when the accessor names a class of the repository, that
 * class must not be a concrete one. An accessor that is a string, or a class outside the repository,
 * cannot be judged on that point and is accepted.
 *
 * Read from the tokens of each file, so it judges a repository whose dependencies are not installed.
 */
final class FacadeClasses
{
    private const string ACCESSOR = 'getFacadeAccessor';

    /**
     * @return list<string> One line per way a facade is more than a thin front
     */
    public static function problems(Layers $layers): array
    {
        $kinds = self::kinds($layers);
        $problems = [];

        foreach ($layers->files as $path) {
            foreach (SourceTypes::ofFile($path) as $type) {
                if ($type->kind === 'class' && self::isFacade($type)) {
                    array_push($problems, ...self::problemsOf($type, $kinds));
                }
            }
        }

        sort($problems);

        return array_values(array_unique($problems));
    }

    /** Whether the class extends a `Facade` (Illuminate's or a base of its own). */
    public static function isFacade(TypeDeclaration $type): bool
    {
        return $type->extends !== null && ($type->extends === 'Facade' || str_ends_with($type->extends, '\\Facade'));
    }

    /**
     * @param  array<string, string>  $kinds  Class name => 'interface' or 'concrete', for the classes of the repository
     * @return list<string>
     */
    private static function problemsOf(TypeDeclaration $facade, array $kinds): array
    {
        $problems = [];

        foreach (array_keys($facade->methods) as $method) {
            if ($method !== self::ACCESSOR) {
                $problems[] = "{$facade->name}::{$method}() is logic of its own: a facade only names the service it fronts.";
            }
        }

        $body = $facade->bodies[self::ACCESSOR] ?? null;

        if ($body === null) {
            return $problems;
        }

        $accessor = self::accessorClass($body);

        if ($accessor === null) {
            if (! self::isLiteralReturn($body)) {
                $problems[] = "{$facade->name}::getFacadeAccessor() does more than return what the container resolves.";
            }

            return $problems;
        }

        if (($kinds[$facade->resolve($accessor)] ?? null) === 'concrete') {
            $problems[] = "{$facade->name} resolves {$facade->resolve($accessor)}, a concrete class: a facade resolves a contract (an interface).";
        }

        return $problems;
    }

    /**
     * The class written in `return Name::class;`, or null when the body is something else.
     *
     * @param  list<string>  $body
     */
    private static function accessorClass(array $body): ?string
    {
        if (count($body) === 5 && $body[0] === 'return' && $body[2] === '::' && strtolower($body[3]) === 'class' && $body[4] === ';') {
            return $body[1];
        }

        return null;
    }

    /**
     * @param  list<string>  $body
     */
    private static function isLiteralReturn(array $body): bool
    {
        return count($body) === 3 && $body[0] === 'return' && preg_match('/^([\'"]).*\1$/s', $body[1]) === 1 && $body[2] === ';';
    }

    /**
     * @return array<string, string>
     */
    private static function kinds(Layers $layers): array
    {
        $kinds = [];

        foreach ($layers->files as $path) {
            foreach (SourceTypes::ofFile($path) as $type) {
                $kinds[$type->name] = $type->kind === 'class' && ! $type->abstract ? 'concrete' : $type->kind;
            }
        }

        return $kinds;
    }
}
