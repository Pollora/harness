<?php

declare(strict_types=1);

namespace Pollora\Harness\Check;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Tells whether the test suites declared in `phpunit.xml(.dist)` run a given
 * test file, which is what `vendor/bin/pest` runs when it gets no argument.
 */
final readonly class PhpUnitSuites
{
    private const string DEFAULT_SUFFIX = 'Test.php';

    public function __construct(private string $root) {}

    /**
     * @param  string  $file  Path of the test file, relative to the repository
     */
    public function runs(string $file): bool
    {
        $target = realpath($this->root.'/'.$file);

        if ($target === false) {
            return false;
        }

        foreach (['phpunit.xml', 'phpunit.xml.dist'] as $name) {
            if (is_file($this->root.'/'.$name)) {
                return $this->declares($this->root.'/'.$name, $target);
            }
        }

        return false;
    }

    private function declares(string $configuration, string $target): bool
    {
        $document = new DOMDocument;

        if (! @$document->load($configuration)) {
            return false;
        }

        $xpath = new DOMXPath($document);
        $base = dirname($configuration);
        $included = false;

        foreach ($this->nodes($xpath, '//testsuite/file | //testsuite/directory') as $node) {
            if ($this->matches($node, $base, $target)) {
                $included = true;

                break;
            }
        }

        if (! $included) {
            return false;
        }

        foreach ($this->nodes($xpath, '//testsuite/exclude') as $node) {
            if ($this->excludes(trim($node->textContent), $base, $target)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<DOMElement>
     */
    private function nodes(DOMXPath $xpath, string $query): array
    {
        $found = $xpath->query($query);
        $nodes = [];

        foreach ($found === false ? [] : $found as $node) {
            if ($node instanceof DOMElement) {
                $nodes[] = $node;
            }
        }

        return $nodes;
    }

    private function matches(DOMElement $node, string $base, string $target): bool
    {
        $path = trim($node->textContent);

        if ($path === '') {
            return false;
        }

        $suffix = $node->hasAttribute('suffix') ? $node->getAttribute('suffix') : self::DEFAULT_SUFFIX;

        foreach ($this->expand($base, $path) as $resolved) {
            if ($node->tagName === 'file' && $resolved === $target) {
                return true;
            }

            if ($node->tagName === 'directory' && is_dir($resolved) && str_starts_with($target, $resolved.'/') && str_ends_with($target, $suffix)) {
                return true;
            }
        }

        return false;
    }

    private function excludes(string $path, string $base, string $target): bool
    {
        foreach ($this->expand($base, $path) as $resolved) {
            if ($resolved === $target || str_starts_with($target, $resolved.'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string> Real paths a (possibly globbed) entry of the configuration designates
     */
    private function expand(string $base, string $path): array
    {
        $matches = glob($path[0] === '/' ? $path : $base.'/'.$path);
        $real = array_map(realpath(...), $matches === false ? [] : $matches);

        return array_values(array_filter($real, static fn (string|false $match): bool => $match !== false));
    }
}
