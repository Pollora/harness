<?php

declare(strict_types=1);

namespace Pollora\Harness\Architecture;

use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * CONV-12: tests are Pest functions (`it()`, `test()`), not PHPUnit test classes.
 *
 * A PHPUnit test class is a concrete class whose parent is a test case (a name ending with `TestCase`:
 * PHPUnit's, Testbench's, the repository's own) and that has at least one test method (named `test...`,
 * or marked `#[Test]` or `@test`). PHPStan's rule tests (`PHPStan\Testing\RuleTestCase`, `TypeInferenceTestCase`)
 * can only be classes, and a test double that has a method called `test...` is not a test: neither counts. The tests of a repository are what its `autoload-dev` PSR-4 roots and
 * its `tests/` directory hold, without the directories of fixtures and stubs, which contain such classes
 * on purpose. A test that needs a process of its own (`#[RunInSeparateProcess]`, `#[RunTestsInSeparateProcesses]`
 * or their annotations) may stay a class: Pest runs it the same, and a function cannot ask for the isolation.
 *
 * Read from the tokens of each file, so it judges a repository whose dependencies are not installed.
 */
final class TestClasses
{
    private const array IGNORED_DIRECTORIES = ['vendor', 'node_modules', 'Fixtures', 'fixtures', 'Fixture', 'Stubs', 'stubs', '__fixtures__', 'Stub'];

    /**
     * @return list<string> One line per PHPUnit test class of the repository
     */
    public static function problems(string $root): array
    {
        $problems = [];
        $root = rtrim($root, '/');

        foreach (self::files($root) as $path) {
            foreach (SourceTypes::ofFile($path) as $type) {
                if (self::isTestClass($type)) {
                    $problems[] = "{$type->name} is a PHPUnit test class ({$type->extends}): write its tests as it()/test() functions with datasets.";
                }
            }
        }

        sort($problems);

        return array_values(array_unique($problems));
    }

    /**
     * Whether the class is a PHPUnit test class that must be a set of Pest functions.
     */
    public static function isTestClass(TypeDeclaration $type): bool
    {
        if ($type->kind !== 'class' || $type->abstract || $type->extends === null) {
            return false;
        }

        if (! str_ends_with($type->extends, 'TestCase') || str_starts_with($type->extends, 'PHPStan\\Testing\\')) {
            return false;
        }

        $tests = array_filter(
            $type->methods,
            static fn (string $annotations, string $name): bool => str_starts_with($name, 'test') || preg_match('/#\[\s*(?:\\\\?[\w\\\\]*\\\\)?Test\b|@test\b/', $annotations) === 1,
            ARRAY_FILTER_USE_BOTH,
        );

        if ($tests === [] || self::isolates($type->annotations)) {
            return false;
        }

        foreach ($tests as $annotations) {
            if (! self::isolates($annotations)) {
                return true;
            }
        }

        return false;
    }

    /** Whether annotations ask for a separate process. */
    private static function isolates(string $annotations): bool
    {
        return preg_match('/RunInSeparateProcess|RunTestsInSeparateProcesses|@runInSeparateProcess|@runTestsInSeparateProcesses/i', $annotations) === 1;
    }

    /**
     * @return list<string>
     */
    private static function files(string $root): array
    {
        $directories = ['tests'];
        $composer = is_file($root.'/composer.json') ? json_decode((string) file_get_contents($root.'/composer.json'), true) : null;
        $psr4 = is_array($composer) && is_array($composer['autoload-dev'] ?? null) && is_array($composer['autoload-dev']['psr-4'] ?? null) ? $composer['autoload-dev']['psr-4'] : [];

        foreach ($psr4 as $paths) {
            foreach ((array) $paths as $path) {
                if (is_string($path)) {
                    $directories[] = trim($path, '/');
                }
            }
        }

        $files = [];

        foreach (array_unique($directories) as $directory) {
            $base = $root.'/'.$directory;

            if (! is_dir($base)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
                static fn (\SplFileInfo $entry): bool => ! $entry->isDir() || ! in_array($entry->getFilename(), self::IGNORED_DIRECTORIES, true),
            ));

            foreach ($iterator as $file) {
                if ($file instanceof \SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                    $files[$file->getPathname()] = $file->getPathname();
                }
            }
        }

        ksort($files);

        return array_values($files);
    }
}
