<?php

declare(strict_types=1);

namespace Pollora\Harness\Support;

/**
 * The constants a WordPress file checks before it lets itself be loaded.
 *
 * A plugin starts nearly every file with `defined('ABSPATH') || exit;`. The
 * harness loads classes to read them (reflection), outside WordPress: without
 * the constant the first such file ends the whole process, silently.
 */
final class WordPressGuards
{
    /**
     * Defines ABSPATH and WPINC unless they exist, so a guarded file can be loaded.
     */
    public static function define(string $root): void
    {
        defined('ABSPATH') || define('ABSPATH', rtrim($root, '/').'/');
        defined('WPINC') || define('WPINC', 'wp-includes');
    }
}
