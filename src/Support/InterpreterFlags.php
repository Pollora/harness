<?php

declare(strict_types=1);

namespace Pollora\Harness\Support;

/**
 * The interpreter flags a child PHP process needs to measure coverage like its
 * parent does.
 *
 * `php -d zend_extension=xdebug.so vendor/bin/pollora-qa check --coverage=...`
 * loads Xdebug in the parent only: a child started as a plain executable reads
 * the ini files alone and has no coverage driver. A driver the ini files
 * already load is left out, since loading Xdebug twice is a warning. The
 * environment (XDEBUG_MODE) is inherited by the child as it is.
 *
 * It also widens what pcov instruments. Left alone, pcov watches one directory
 * it guesses (src, lib or app under the working directory), while phpunit.xml
 * may list others in `<source>`: their lines count in the total but are never
 * instrumented, so they read as uncovered whatever the tests do.
 */
final class InterpreterFlags
{
    /** Directories that are never the code under test, relative to the project root. */
    private const array NOT_INSTRUMENTED = ['vendor', 'node_modules', '.git', '.harness'];

    /** The directories pcov picks by itself, in this order. */
    private const array PCOV_GUESSES = ['src', 'lib', 'app'];

    /**
     * @return list<string> Arguments to put between the PHP binary and the script
     */
    public static function inherited(): array
    {
        $files = array_filter([php_ini_loaded_file(), ...explode(',', (string) php_ini_scanned_files())], static fn (string|false $file): bool => $file !== false && trim($file) !== '');
        $ini = implode("\n", array_map(static fn (string $file): string => (string) @file_get_contents(trim($file)), $files));

        return self::from(
            extension_loaded('xdebug'),
            extension_loaded('pcov'),
            $ini,
            (string) ini_get('xdebug.mode'),
            (string) ini_get('pcov.enabled'),
        );
    }

    /**
     * @param  string  $ini  The text of the ini files the child reads
     * @return list<string>
     */
    public static function from(bool $xdebugLoaded, bool $pcovLoaded, string $ini, string $xdebugMode, string $pcovEnabled): array
    {
        $flags = [];

        if ($xdebugLoaded && preg_match('/^\s*zend_extension\s*=.*xdebug/mi', $ini) !== 1) {
            $flags[] = '-d';
            $flags[] = 'zend_extension=xdebug';
            if ($xdebugMode !== '') {
                $flags[] = '-d';
                $flags[] = 'xdebug.mode='.$xdebugMode;
            }
        }

        if ($pcovLoaded && preg_match('/^\s*extension\s*=.*pcov/mi', $ini) !== 1) {
            $flags[] = '-d';
            $flags[] = 'extension=pcov';
            if ($pcovEnabled !== '') {
                $flags[] = '-d';
                $flags[] = 'pcov.enabled='.$pcovEnabled;
            }
        }

        return $flags;
    }

    /**
     * The flags that make pcov instrument the whole project and not the one directory it guesses.
     *
     * @return list<string>
     */
    public static function coverageScope(string $root): array
    {
        $real = realpath($root);

        return self::pcovScope($real === false ? $root : $real, extension_loaded('pcov'), (string) ini_get('pcov.directory'));
    }

    /**
     * pcov takes a single directory. It is the project root, minus what is not the code under test, unless the
     * project set another one itself: pcov writes the directory it guessed (<root>/src, lib or app) back into the
     * ini value, so those three count as "not set".
     *
     * @param  string  $configuredDirectory  The value of pcov.directory the parent process sees
     * @return list<string>
     */
    public static function pcovScope(string $root, bool $pcovLoaded, string $configuredDirectory): array
    {
        $root = rtrim($root, '/\\');

        if (! $pcovLoaded || $root === '' || ! self::isGuessedOrUnset($root, $configuredDirectory)) {
            return [];
        }

        return [
            '-d', 'pcov.directory='.$root,
            '-d', 'pcov.exclude='.self::excludePattern($root),
        ];
    }

    /**
     * Anchored on the root: a project that lives under a folder called "vendor" or "tests" must not be excluded whole.
     */
    public static function excludePattern(string $root): string
    {
        return '~^'.preg_quote(rtrim($root, '/\\'), '~').'/(?:'.implode('|', array_map(static fn (string $directory): string => preg_quote($directory, '~'), self::NOT_INSTRUMENTED)).')/~';
    }

    private static function isGuessedOrUnset(string $root, string $configured): bool
    {
        $configured = rtrim($configured, '/\\');

        if ($configured === '' || $configured === $root) {
            return true;
        }

        foreach (self::PCOV_GUESSES as $guess) {
            if ($configured === $root.'/'.$guess) {
                return true;
            }
        }

        return false;
    }
}
