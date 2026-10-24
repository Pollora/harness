<?php

declare(strict_types=1);

namespace Pollora\Harness\Deps;

use Pollora\Harness\Config\HarnessConfig;
use Pollora\Harness\Violation;

/**
 * Checks the runtime dependencies a repository declares against its profile.
 *
 * ARCH-04: a core package must stay usable without Laravel, so it requires
 * neither illuminate/* nor laravel/*, except the packages listed under
 * "allow_laravel" in .harness.json while the roadmap removes them.
 *
 * ARCH-06: only integration packages, templates and projects may require
 * pollora/framework; everything else sits below it and keeps the package
 * graph acyclic.
 */
final readonly class DependencyPolicy
{
    private const string FRAMEWORK = 'pollora/framework';

    /** Laravel tools that run as binaries: requiring them adds no Laravel code to the package. */
    private const array TOOLS = ['laravel/pint'];

    public function __construct(private HarnessConfig $config) {}

    /**
     * @param  array<string, string>  $require  The "require" section of composer.json
     * @return list<Violation>
     */
    public function check(array $require): array
    {
        $violations = [];
        $profile = $this->config->profile;

        foreach (array_keys($require) as $package) {
            if ($profile->mustStayLaravelFree() && $this->isLaravel($package) && ! in_array($package, $this->config->allowLaravel, true)) {
                $violations[] = new Violation(
                    'ARCH-04',
                    "{$package} is a Laravel package: a core package must stay usable without Laravel.",
                    'Depend on a PSR interface or a Pollora port instead, or list the package under "allow_laravel" in .harness.json with the issue that will remove it.',
                );
            }

            if ($package === self::FRAMEWORK && ! $profile->mayDependOnFramework()) {
                $violations[] = new Violation(
                    'ARCH-06',
                    "{$package} is required by a {$profile->value} package, which sits below the framework.",
                    'Move the code that needs the framework into an integration package, or invert the dependency through a port.',
                );
            }
        }

        foreach ($this->config->allowLaravel as $allowed) {
            if (! array_key_exists($allowed, $require)) {
                $violations[] = new Violation(
                    'ARCH-04',
                    "{$allowed} is listed under \"allow_laravel\" but no longer required.",
                    'Remove it from "allow_laravel" in .harness.json so the exception cannot come back unnoticed.',
                );
            }
        }

        return $violations;
    }

    /**
     * @return array<string, string>
     */
    public static function readRequire(string $root): array
    {
        $path = rtrim($root, '/').'/composer.json';
        $composer = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        $require = is_array($composer) && is_array($composer['require'] ?? null) ? $composer['require'] : [];

        return array_filter($require, static fn (mixed $constraint, mixed $name): bool => is_string($name) && is_string($constraint), ARRAY_FILTER_USE_BOTH);
    }

    private function isLaravel(string $package): bool
    {
        if (in_array($package, self::TOOLS, true)) {
            return false;
        }

        return str_starts_with($package, 'illuminate/') || str_starts_with($package, 'laravel/');
    }
}
