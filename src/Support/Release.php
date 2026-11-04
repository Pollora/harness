<?php

declare(strict_types=1);

namespace Pollora\Harness\Support;

use Composer\InstalledVersions;

/**
 * The released version of the harness this binary belongs to.
 */
final class Release
{
    public const string PACKAGE = 'pollora/harness';

    /**
     * The ref the generated workflow calls: the tag of the installed release
     * (v0.1.17), or main when the harness runs from a branch or a checkout.
     */
    public static function workflowRef(): string
    {
        $version = class_exists(InstalledVersions::class) && InstalledVersions::isInstalled(self::PACKAGE)
            ? InstalledVersions::getPrettyVersion(self::PACKAGE)
            : null;

        return self::tag($version) ?? 'main';
    }

    public static function tag(?string $version): ?string
    {
        return $version !== null && preg_match('/^v?(\d+\.\d+\.\d+)$/', $version, $match) === 1 ? 'v'.$match[1] : null;
    }
}
