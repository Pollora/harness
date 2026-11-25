<?php

declare(strict_types=1);

/**
 * The text of the reusable workflow, read from the repository.
 */
function qualityTimeoutWorkflow(): string
{
    return (string) file_get_contents(dirname(__DIR__, 2).'/.github/workflows/php-package.yml');
}

it('lets a repository raise the time limit of the quality job, 30 minutes by default', function (): void {
    $text = qualityTimeoutWorkflow();

    expect($text)->toMatch('/quality-timeout-minutes:\n(?:        .+\n)+?        type: number\n        default: 30\n/')
        ->and($text)->toContain('timeout-minutes: ${{ inputs.quality-timeout-minutes }}');
});

it('keeps the fixed limits of every other job', function (): void {
    $text = qualityTimeoutWorkflow();
    preg_match_all('/^    timeout-minutes: (.+)$/m', $text, $limits);

    // The quality job takes the input; every other job keeps its own number.
    $fixed = array_values(array_filter($limits[1], static fn (string $limit): bool => ctype_digit($limit)));

    expect($limits[1])->toContain('${{ inputs.quality-timeout-minutes }}')
        ->and(count($limits[1]))->toBeGreaterThan(count($fixed))
        ->and(count($limits[1]) - count($fixed))->toBe(1)
        ->and($fixed)->not->toBe([]);
});

it('uses the input on the quality job only', function (): void {
    $text = qualityTimeoutWorkflow();
    $section = substr($text, (int) strpos($text, "\n  quality:\n"));
    $quality = substr($section, 0, (int) strpos($section, "\n  tests:\n") ?: strlen($section));

    expect(substr_count($text, 'inputs.quality-timeout-minutes'))->toBe(1)
        ->and($quality)->toContain('timeout-minutes: ${{ inputs.quality-timeout-minutes }}');
});
