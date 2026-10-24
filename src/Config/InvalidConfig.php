<?php

declare(strict_types=1);

namespace Pollora\Harness\Config;

use Pollora\Harness\Profile;
use RuntimeException;

final class InvalidConfig extends RuntimeException
{
    public static function missing(string $path): self
    {
        return new self("{$path} not found. Run `vendor/bin/pollora-qa init --profile=<profile>` to create it.");
    }

    public static function unreadable(string $path, string $reason): self
    {
        return new self("{$path} is not valid JSON: {$reason}.");
    }

    public static function unknownProfile(string $path, mixed $profile): self
    {
        $given = is_scalar($profile) ? (string) $profile : get_debug_type($profile);

        return new self(sprintf(
            '%s declares the profile "%s". Use one of: %s.',
            $path,
            $given,
            implode(', ', Profile::values()),
        ));
    }

    public static function unknownMode(string $path, mixed $mode): self
    {
        $given = is_scalar($mode) ? (string) $mode : get_debug_type($mode);

        return new self("{$path} declares the mode \"{$given}\". Use \"observe\" or \"enforce\".");
    }
}
