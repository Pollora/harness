<?php

declare(strict_types=1);

use Pollora\Harness\Rector\Preset;

it('skips only rules the installed Rector ships, so a renamed rule is noticed', function (): void {
    $missing = array_values(array_filter(Preset::SKIPPED, static fn (string $rule): bool => ! class_exists($rule)));

    expect($missing)->toBe([]);
});

it('keeps the rules that contradict Pint or break an override out of the preset', function (): void {
    expect(Preset::SKIPPED)->toContain(
        'Rector\CodeQuality\Rector\BooleanNot\NegatedAndsToPositiveOrsRector',
        'Rector\CodingStyle\Rector\String_\SimplifyQuoteEscapeRector',
        'Rector\Php82\Rector\Class_\ReadOnlyClassRector',
    );
});
