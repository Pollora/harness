<?php

declare(strict_types=1);

namespace Pollora\Harness\Architecture;

/**
 * The PSR-4 namespaces that Composer maps outside the project (under vendor/).
 *
 * The architecture engine of Pest resolves a namespace through the PSR-4
 * prefixes of Composer's class loader, without telling the project from the
 * installed packages: every prefix that starts a namespace contributes its
 * directory. When a package installed under vendor/ adds classes to a
 * namespace of one of the project's layers (pollora/media 0.9 adds
 * Pollora\Media\Domain\Exceptions\* to the framework), the rule is then run
 * on the classes of the package too, and the engine fails describing them
 * ("Typed property ObjectDescriptionBase::$path must not be accessed before
 * initialization"). A rule can only judge the project's own classes: for a
 * namespace the engine would fill from vendor/, the rule is given the explicit list of the project's
 * classes instead of the namespace (the engine resolves a class name to its own file only, so nothing is
 * read from vendor/), except for a class that the package also has under the same name (`shadows()`).
 */
final readonly class ForeignNamespaces
{
    /**
     * @param  list<string>  $prefixes  The PSR-4 prefixes mapped to a directory outside the project, without trailing backslash
     * @param  array<string, list<string>>  $directories  The directories outside the project of each of those prefixes
     */
    public function __construct(public array $prefixes, public array $directories = []) {}

    /**
     * Reads vendor/composer/autoload_psr4.php, the map the class loader is built from. A project without
     * vendor/ has no foreign namespace.
     */
    public static function discover(string $root): self
    {
        $root = rtrim($root, '/');
        $file = $root.'/vendor/composer/autoload_psr4.php';

        if (! is_file($file)) {
            return new self([]);
        }

        $map = (static fn (): mixed => require $file)();

        return self::fromMap(is_array($map) ? $map : [], $root);
    }

    /**
     * @param  array<mixed>  $map  PSR-4 prefix => directories, as Composer writes it
     */
    public static function fromMap(array $map, string $root): self
    {
        $root = rtrim($root, '/');
        $realRoot = realpath($root);
        $found = [];

        foreach ($map as $prefix => $directories) {
            if (! is_string($prefix)) {
                continue;
            }

            foreach ((array) $directories as $directory) {
                if (is_string($directory) && self::isForeign($directory, $root, $realRoot === false ? $root : $realRoot)) {
                    $found[rtrim($prefix, '\\')][] = $directory;
                }
            }
        }

        ksort($found);

        return new self(array_keys($found), array_map(static fn (array $directories): array => array_values(array_unique($directories)), $found));
    }

    /**
     * Whether the engine would load files of an installed package for this namespace: a foreign
     * prefix starts it, which is the test the engine itself makes (a plain string prefix).
     */
    public function overlaps(string $namespace): bool
    {
        return $this->prefixFor($namespace) !== null;
    }

    /**
     * The foreign prefix that starts the namespace, if any.
     */
    public function prefixFor(string $namespace): ?string
    {
        foreach ($this->prefixes as $prefix) {
            if ($prefix !== '' && str_starts_with($namespace, $prefix)) {
                return $prefix;
            }
        }

        return null;
    }

    /**
     * Whether an installed package has a class (or a namespace directory) of this very name, which the engine
     * would then read next to the project's own: such a class cannot be told apart from the package's.
     */
    public function shadows(string $class): bool
    {
        foreach ($this->directories as $prefix => $directories) {
            if ($prefix === '' || ! str_starts_with($class, $prefix)) {
                continue;
            }

            $relative = str_replace('\\', '/', ltrim(substr($class, strlen($prefix)), '\\'));

            foreach ($directories as $directory) {
                $path = rtrim($directory, '/').'/'.$relative;

                if (is_file($path.'.php') || is_dir($path)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * A directory is foreign when it sits under vendor/ by its path (a `path` repository is a symbolic
     * link there, Composer keeps the vendor path) or, once resolved, outside the project.
     */
    private static function isForeign(string $directory, string $root, string $realRoot): bool
    {
        $vendor = $root.'/vendor/';
        $normalized = str_replace('\\', '/', $directory);

        if (str_starts_with(self::collapse($normalized), self::collapse($vendor))) {
            return true;
        }

        $real = realpath($directory);

        if ($real === false) {
            return false;
        }

        $real = str_replace('\\', '/', $real);

        return ! str_starts_with($real.'/', $realRoot.'/') || str_starts_with($real.'/', $realRoot.'/vendor/');
    }

    /** Removes `/./` and resolves `/../` of a path that may not exist, without touching symbolic links. */
    private static function collapse(string $path): string
    {
        $parts = [];

        foreach (explode('/', $path) as $part) {
            if ($part === '..') {
                array_pop($parts);
            } elseif ($part !== '.') {
                $parts[] = $part;
            }
        }

        return implode('/', $parts);
    }
}
