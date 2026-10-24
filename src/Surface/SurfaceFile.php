<?php

declare(strict_types=1);

namespace Pollora\Harness\Surface;

/**
 * api-surface.json: the public surface as last recorded by a person.
 *
 * It is a protected file (QA-02): an agent cannot edit it, and a pull request
 * that changes the surface records it with `pollora-qa surface --update`,
 * which a reviewer then reads as the list of what the release changes.
 */
final class SurfaceFile
{
    public const string FILE = 'api-surface.json';

    private const int VERSION = 1;

    public static function path(string $root): string
    {
        return rtrim($root, '/').'/'.self::FILE;
    }

    public static function exists(string $root): bool
    {
        return is_file(self::path($root));
    }

    /**
     * @return array<string, string>
     */
    public static function read(string $root): array
    {
        $data = json_decode((string) @file_get_contents(self::path($root)), true);
        $surface = is_array($data) && is_array($data['surface'] ?? null) ? $data['surface'] : [];

        return array_filter($surface, static fn (mixed $value, mixed $key): bool => is_string($key) && is_string($value), ARRAY_FILTER_USE_BOTH);
    }

    /**
     * @param  array<string, string>  $surface
     */
    public static function write(string $root, array $surface): void
    {
        ksort($surface);

        file_put_contents(
            self::path($root),
            json_encode(['version' => self::VERSION, 'surface' => $surface], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n",
        );
    }
}
