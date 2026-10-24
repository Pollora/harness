<?php

declare(strict_types=1);

namespace Pollora\Harness\Rules;

/**
 * Reads the rule catalogue in resources/rules.php.
 */
final class Catalogue
{
    /**
     * @var array<string, array{title: string, tool: string, gate: string, status: string}>|null
     */
    private static ?array $rules = null;

    /**
     * @return array<string, array{title: string, tool: string, gate: string, status: string}>
     */
    public static function all(): array
    {
        if (self::$rules === null) {
            /** @var array<string, array{title: string, tool: string, gate: string, status: string}> $rules */
            $rules = require dirname(__DIR__, 2).'/resources/rules.php';
            self::$rules = $rules;
        }

        return self::$rules;
    }

    /**
     * @return array<string, array{title: string, tool: string, gate: string, status: string}>
     */
    public static function family(string $prefix): array
    {
        return array_filter(
            self::all(),
            static fn (string $id): bool => str_starts_with($id, $prefix.'-'),
            ARRAY_FILTER_USE_KEY,
        );
    }

    public static function title(string $id): string
    {
        return self::all()[$id]['title'] ?? $id;
    }
}
