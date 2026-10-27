<?php

declare(strict_types=1);

use Pollora\Harness\Rector\Preset;
use Rector\CodeQuality\Rector\BooleanNot\NegatedAndsToPositiveOrsRector;
use Rector\CodingStyle\Rector\String_\SimplifyQuoteEscapeRector;
use Rector\Contract\Rector\RectorInterface;
use Rector\Php82\Rector\Class_\ReadOnlyClassRector;

it('skips only Rector rules: the lowest Rector may not ship them all, the latest must', function (): void {
    $installed = array_filter(Preset::SKIPPED, static fn (string $rule): bool => class_exists($rule));

    expect($installed)->not->toBe([])
        ->and(array_filter($installed, static fn (string $rule): bool => ! is_subclass_of($rule, RectorInterface::class)))->toBe([]);
});

it('keeps the rules that contradict Pint or break an override out of the preset', function (): void {
    expect(Preset::SKIPPED)->toContain(
        NegatedAndsToPositiveOrsRector::class,
        SimplifyQuoteEscapeRector::class,
        ReadOnlyClassRector::class,
    );
});
