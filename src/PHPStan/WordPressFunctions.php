<?php

declare(strict_types=1);

namespace Pollora\Harness\PHPStan;

use Composer\InstalledVersions;
use ReflectionFunction;

/**
 * The names of WordPress's global functions.
 *
 * Read once from php-stubs/wordpress-stubs, which szepeviktor/phpstan-wordpress
 * already installs. When the stubs are missing, a list of the functions the
 * Pollora code base calls most keeps the rule useful.
 */
final class WordPressFunctions
{
    private const string STUBS_PACKAGE = 'php-stubs/wordpress-stubs';

    private const string STUBS_FILE = 'wordpress-stubs.php';

    private const array FALLBACK = [
        'add_action', 'add_filter', 'apply_filters', 'do_action', 'remove_action', 'remove_filter', 'has_action', 'has_filter',
        'get_option', 'update_option', 'add_option', 'delete_option', 'get_post', 'get_posts', 'get_post_meta', 'update_post_meta',
        'get_term', 'get_terms', 'get_user_by', 'get_current_user_id', 'current_user_can', 'is_admin', 'is_user_logged_in',
        'register_post_type', 'register_taxonomy', 'register_meta', 'register_rest_route', 'register_block_type', '__', 'esc_html',
        'esc_attr', 'esc_url', 'home_url', 'site_url', 'admin_url', 'get_template_directory', 'get_stylesheet_directory',
    ];

    /** @var array<string, true>|null */
    private ?array $names = null;

    public function __construct(private readonly ?string $stubsPath = null) {}

    public function contains(string $function): bool
    {
        return isset($this->names()[strtolower($function)]);
    }

    /**
     * @return array<string, true>
     */
    public function names(): array
    {
        if ($this->names !== null) {
            return $this->names;
        }

        $names = self::FALLBACK;
        $stubs = $this->stubsPath ?? $this->locateStubs();

        // Global functions sit at most four spaces in (inside `namespace {`);
        // class methods sit deeper and must not count (WP_Query::reset()).
        if ($stubs !== null && preg_match_all('/^ {0,4}function\s+&?\s*([A-Za-z_][A-Za-z0-9_]*)\s*\(/m', (string) file_get_contents($stubs), $matches) > 0) {
            $names = $matches[1];
        }

        // A PHP function is never WordPress's, whatever a stub declares.
        $names = array_filter($names, static fn (string $name): bool => ! function_exists($name) || ! (new ReflectionFunction($name))->isInternal());

        return $this->names = array_fill_keys(array_map(strtolower(...), $names), true);
    }

    private function locateStubs(): ?string
    {
        if (! class_exists(InstalledVersions::class) || ! InstalledVersions::isInstalled(self::STUBS_PACKAGE)) {
            return null;
        }

        $path = InstalledVersions::getInstallPath(self::STUBS_PACKAGE).'/'.self::STUBS_FILE;

        return is_file($path) ? $path : null;
    }
}
