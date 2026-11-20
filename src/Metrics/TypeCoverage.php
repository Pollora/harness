<?php

declare(strict_types=1);

namespace Pollora\Harness\Metrics;

use FilesystemIterator;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

/**
 * The share of declarations that carry a native type (CONV-11): the metric
 * `type_coverage` of the ratchet.
 *
 * It is measured by a static pass over the source of the repository with
 * nikic/php-parser, so it needs neither Pest's type-coverage plugin (installed
 * in two repositories out of forty-five) nor an autoloader: it works on a
 * checkout where `composer install` has not run. The result is deterministic
 * (files sorted, no clock, no randomness).
 *
 * What is counted, and what is not (each choice is a documented decision):
 * - the return type of every function and method, except constructors and
 *   destructors, which cannot declare one;
 * - every parameter, promoted constructor parameters included (they are the
 *   properties of such a class: they are not counted twice);
 * - every declared property, one per variable of `public $a, $b;`.
 * - `mixed` is a type: it is an explicit statement, unlike an absent one.
 * - closures and arrow functions are out of scope: they are local, and their
 *   signature is dictated by the callback contract they are passed to.
 * - the parameters of a method that overrides a method of WordPress that has
 *   no types (the `Walker` family and `WP_Widget`, see OVERRIDES) are left out
 *   and reported as excluded: a child cannot narrow an untyped parameter of
 *   its parent (`string &$output` is a fatal error), so the only type it could
 *   write is `mixed`. The return type of such a method is still counted, since
 *   a child may add one. The class is recognised by the fully qualified name
 *   of its parent after name resolution, so `\Walker_Nav_Menu` matches and a
 *   `Walker_Nav_Menu` of the namespace of the class does not.
 * - `%placeholder%` markers of the theme templates (`make:theme`) are
 *   replaced by an identifier before parsing; Blade files are not read.
 * - a file that does not parse is never ignored silently: it is listed in
 *   {@see TypeCoverageReport::$unparsed} and `check` prints it.
 *
 * The files read are the PSR-4, PSR-0, classmap and `files` entries of the
 * `autoload` section of composer.json plus `src` and `app`, except `vendor`,
 * `node_modules` and the test directories.
 */
final class TypeCoverage
{
    /**
     * The untyped methods of WordPress a child class overrides, by parent
     * class pattern. The pattern is matched against the global (unnamespaced)
     * name of the parent.
     *
     * @var array<string, list<string>>
     */
    public const array OVERRIDES = [
        '/^Walker(_[A-Za-z_]+)?$/' => ['start_lvl', 'end_lvl', 'start_el', 'end_el', 'display_element', 'walk', 'paged_walk', 'get_number_of_root_elements', 'unset_children'],
        '/^WP_Widget$/' => ['widget', 'form', 'update'],
    ];

    /** Directories never read, wherever they sit in the tree. */
    private const array SKIPPED_DIRECTORIES = ['vendor', 'node_modules', 'tests', 'Tests', 'test', '.git', 'storage'];

    /**
     * Measures the source of the repository at $root.
     */
    public static function measure(string $root): TypeCoverageReport
    {
        $parser = (new ParserFactory)->createForNewestSupportedVersion();
        $stats = ['return' => [0, 0], 'parameter' => [0, 0], 'property' => [0, 0]];
        $missing = [];
        $unparsed = [];
        $excluded = 0;
        $files = 0;

        foreach (self::files($root) as $path) {
            $file = self::analyse($parser, $path);

            if ($file === null) {
                $unparsed[] = self::relative($root, $path);

                continue;
            }

            $files++;
            $excluded += $file['excluded'];

            foreach ($file['stats'] as $kind => [$typed, $total]) {
                $stats[$kind][0] += $typed;
                $stats[$kind][1] += $total;
            }

            $untyped = $file['untyped'];

            if ($untyped > 0) {
                $missing[self::relative($root, $path)] = $untyped;
            }
        }

        $missing = self::topFiles($missing);

        return new TypeCoverageReport($stats['return'], $stats['parameter'], $stats['property'], $missing, $unparsed, $excluded, $files);
    }

    /**
     * The directories whose PHP is measured, relative to $root: the roots the
     * `autoload` section declares, then `src` and `app`; only those that exist.
     *
     * @return list<string>
     */
    public static function directories(string $root): array
    {
        $directories = [];
        $composer = is_file($root.'/composer.json') ? json_decode((string) file_get_contents($root.'/composer.json'), true) : null;
        $autoload = is_array($composer) && is_array($composer['autoload'] ?? null) ? $composer['autoload'] : [];

        foreach (['psr-4', 'psr-0'] as $kind) {
            foreach (is_array($autoload[$kind] ?? null) ? $autoload[$kind] : [] as $paths) {
                foreach (is_array($paths) ? $paths : [$paths] as $path) {
                    $directories[] = $path;
                }
            }
        }

        foreach (is_array($autoload['classmap'] ?? null) ? $autoload['classmap'] : [] as $path) {
            $directories[] = $path;
        }

        $directories = [...$directories, 'src', 'app'];
        $found = [];

        foreach ($directories as $directory) {
            if (! is_string($directory)) {
                continue;
            }

            $directory = trim(str_replace('\\', '/', $directory), '/');

            if ($directory === '' || $directory === '.' || ! is_dir($root.'/'.$directory)) {
                continue;
            }

            $found[$directory] = true;
        }

        $found = array_keys($found);
        sort($found);

        return $found;
    }

    /**
     * The files the `autoload.files` section lists (a bootstrap at the root of
     * a package is not under any directory read), relative to $root, existing ones only.
     *
     * @return list<string>
     */
    private static function autoloadedFiles(string $root): array
    {
        $composer = is_file($root.'/composer.json') ? json_decode((string) file_get_contents($root.'/composer.json'), true) : null;
        $listed = is_array($composer) && is_array($composer['autoload'] ?? null) && is_array($composer['autoload']['files'] ?? null) ? $composer['autoload']['files'] : [];
        $files = [];

        foreach ($listed as $file) {
            if (is_string($file) && str_ends_with($file, '.php') && is_file($root.'/'.ltrim($file, '/'))) {
                $files[] = ltrim(str_replace('\\', '/', $file), '/');
            }
        }

        return $files;
    }

    /**
     * The PHP files to read, sorted, each listed once even when two roots overlap.
     *
     * @return list<string>
     */
    private static function files(string $root): array
    {
        $files = [];

        foreach (self::autoloadedFiles($root) as $file) {
            $files[$root.'/'.$file] = true;
        }

        foreach (self::directories($root) as $directory) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory, FilesystemIterator::SKIP_DOTS));

            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                $path = str_replace('\\', '/', $file->getPathname());

                if (! str_ends_with($path, '.php') || str_ends_with($path, '.blade.php') || ! $file->isFile()) {
                    continue;
                }

                if (self::isSkipped(substr($path, strlen($root) + 1))) {
                    continue;
                }

                $files[$path] = true;
            }
        }

        $files = array_keys($files);
        sort($files);

        return $files;
    }

    /**
     * Whether a path relative to the root goes through a directory never read.
     */
    private static function isSkipped(string $relative): bool
    {
        $segments = explode('/', $relative);
        array_pop($segments);

        return array_intersect($segments, self::SKIPPED_DIRECTORIES) !== [];
    }

    /**
     * Counts one file; null when it does not parse.
     *
     * @return array{stats: array{return: array{int, int}, parameter: array{int, int}, property: array{int, int}}, untyped: int, excluded: int}|null
     */
    private static function analyse(Parser $parser, string $path): ?array
    {
        $code = (string) file_get_contents($path);
        $code = preg_replace('/%[A-Za-z_][A-Za-z0-9_]*%/', 'Placeholder', $code) ?? $code;

        try {
            $ast = $parser->parse($code) ?? [];
        } catch (Throwable) {
            return null;
        }

        $visitor = new TypeCoverageVisitor;
        $traverser = new NodeTraverser(new NameResolver, $visitor);
        $traverser->traverse($ast);

        return [
            'stats' => $visitor->stats,
            'untyped' => $visitor->untyped,
            'excluded' => $visitor->excluded,
        ];
    }

    /**
     * The five files with the most untyped declarations, the worst first; ties by path.
     *
     * @param  array<string, int>  $missing
     * @return array<string, int>
     */
    private static function topFiles(array $missing): array
    {
        $paths = array_keys($missing);
        usort($paths, static fn (string $a, string $b): int => [$missing[$b], $a] <=> [$missing[$a], $b]);

        $top = [];

        foreach (array_slice($paths, 0, 5) as $path) {
            $top[$path] = $missing[$path];
        }

        return $top;
    }

    private static function relative(string $root, string $path): string
    {
        return substr($path, strlen($root) + 1);
    }
}
