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
 */
final class InterpreterFlags
{
    /**
     * @return list<string> Arguments to put between the PHP binary and the script
     */
    public static function inherited(): array
    {
        $files = array_filter([php_ini_loaded_file(), ...explode(',', (string) php_ini_scanned_files())]);
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
            array_push($flags, '-d', 'zend_extension=xdebug');

            if ($xdebugMode !== '') {
                array_push($flags, '-d', 'xdebug.mode='.$xdebugMode);
            }
        }

        if ($pcovLoaded && preg_match('/^\s*extension\s*=.*pcov/mi', $ini) !== 1) {
            array_push($flags, '-d', 'extension=pcov');

            if ($pcovEnabled !== '') {
                array_push($flags, '-d', 'pcov.enabled='.$pcovEnabled);
            }
        }

        return $flags;
    }
}
