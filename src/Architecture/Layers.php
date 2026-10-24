<?php

declare(strict_types=1);

namespace Pollora\Harness\Architecture;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Finds the hexagonal layers of a repository from its PSR-4 autoload map.
 *
 * Pollora names its layers by directory: the framework uses Domain,
 * Application, Infrastructure and UI inside each module (src/Hook/Domain),
 * packages such as pollora/ajax use Domain, Application, Port and Adapter at
 * the root. Every directory with one of those names becomes a layer
 * namespace, wherever it sits.
 */
final class Layers
{
    private const LAYER_DIRECTORIES = [
        'Domain' => 'domain',
        'Application' => 'application',
        'Infrastructure' => 'infrastructure',
        'Adapter' => 'infrastructure',
        'UI' => 'ui',
    ];

    /** How deep below a PSR-4 root a layer directory may sit (src/Module/Domain is 2). */
    private const MAX_DEPTH = 3;

    /**
     * @param  list<string>  $roots  The PSR-4 root namespaces, without trailing backslash
     * @param  array<'domain'|'application'|'infrastructure'|'ui', list<string>>  $layers  Layer namespaces by kind
     * @param  array<string, string>  $files  Every PHP file under the PSR-4 roots, by class name
     */
    public function __construct(
        public readonly array $roots,
        public readonly array $layers,
        public readonly array $files,
    ) {}

    public static function discover(string $root): self
    {
        $roots = [];
        $layers = ['domain' => [], 'application' => [], 'infrastructure' => [], 'ui' => []];
        $files = [];

        foreach (self::psr4($root) as $namespace => $directory) {
            $namespace = rtrim($namespace, '\\');
            $roots[] = $namespace;

            foreach (self::directories($directory) as $relative) {
                $name = basename($relative);

                if (isset(self::LAYER_DIRECTORIES[$name])) {
                    $layers[self::LAYER_DIRECTORIES[$name]][] = $namespace.'\\'.str_replace('/', '\\', $relative);
                }
            }

            foreach (self::phpFiles($directory) as $relative => $path) {
                $files[$namespace.'\\'.str_replace('/', '\\', substr($relative, 0, -4))] = $path;
            }
        }

        foreach ($layers as $kind => $namespaces) {
            sort($namespaces);
            $layers[$kind] = array_values(array_unique($namespaces));
        }

        ksort($files);

        return new self($roots, $layers, $files);
    }

    /**
     * @return list<string>
     */
    public function of(string $kind): array
    {
        return $this->layers[$kind] ?? [];
    }

    public function isHexagonal(): bool
    {
        return $this->of('domain') !== [];
    }

    /**
     * The PSR-4 map of composer.json ("autoload" only: tests are not layered).
     *
     * @return array<string, string> Namespace prefix => absolute directory
     */
    private static function psr4(string $root): array
    {
        $composer = json_decode((string) @file_get_contents(rtrim($root, '/').'/composer.json'), true);
        $autoload = is_array($composer) && is_array($composer['autoload'] ?? null) ? $composer['autoload'] : [];
        $map = is_array($autoload['psr-4'] ?? null) ? $autoload['psr-4'] : [];
        $resolved = [];

        foreach ($map as $namespace => $directories) {
            foreach ((array) $directories as $directory) {
                if (! is_string($directory)) {
                    continue;
                }

                $path = rtrim($root, '/').'/'.trim($directory, '/');

                if (is_string($namespace) && is_dir($path)) {
                    $resolved[$namespace] = $path;
                }
            }
        }

        return $resolved;
    }

    /**
     * @return list<string> Directories relative to $base, up to MAX_DEPTH
     */
    private static function directories(string $base, string $prefix = '', int $depth = 0): array
    {
        if ($depth >= self::MAX_DEPTH) {
            return [];
        }

        $found = [];

        foreach (new FilesystemIterator($base, FilesystemIterator::SKIP_DOTS) as $entry) {
            if (! $entry instanceof \SplFileInfo || ! $entry->isDir()) {
                continue;
            }

            $relative = ltrim($prefix.'/'.$entry->getFilename(), '/');
            $found[] = $relative;
            array_push($found, ...self::directories($entry->getPathname(), $relative, $depth + 1));
        }

        return $found;
    }

    /**
     * @return array<string, string> Relative path => absolute path
     */
    private static function phpFiles(string $base): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                $files[substr($file->getPathname(), strlen(rtrim($base, '/')) + 1)] = $file->getPathname();
            }
        }

        return $files;
    }
}
