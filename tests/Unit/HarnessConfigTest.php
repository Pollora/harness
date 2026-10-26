<?php

declare(strict_types=1);

use Pollora\Harness\Config\HarnessConfig;
use Pollora\Harness\Config\InvalidConfig;
use Pollora\Harness\Config\Mode;
use Pollora\Harness\Profile;

it('starts in observe mode with the default protected files', function (): void {
    $config = HarnessConfig::fromArray(['profile' => 'core']);

    expect($config->profile)->toBe(Profile::Core)
        ->and($config->mode)->toBe(Mode::Observe)
        ->and($config->protected)->toBe(HarnessConfig::DEFAULT_PROTECTED);
});

it('refuses an unknown profile and names the valid ones', function (): void {
    HarnessConfig::fromArray(['profile' => 'library']);
})->throws(InvalidConfig::class, 'core, framework, integration, wp-plugin, template, project');

it('refuses an unknown mode', function (): void {
    HarnessConfig::fromArray(['profile' => 'core', 'mode' => 'strict']);
})->throws(InvalidConfig::class, 'Use "observe" or "enforce"');

it('round-trips through .harness.json', function (): void {
    $root = harnessTempDir();
    $config = HarnessConfig::fromArray([
        'profile' => 'framework',
        'mode' => 'enforce',
        'downstream' => ['skeleton'],
        'ratchet' => ['phpstan_level' => 5, 'phpstan_baseline' => 36],
    ]);

    $config->save($root);

    expect(HarnessConfig::load($root)->toArray())->toBe($config->toArray())
        ->and(json_decode((string) file_get_contents($root.'/.harness.json'), true))->toBe([
            'profile' => 'framework',
            'mode' => 'enforce',
            'downstream' => ['skeleton'],
            'ratchet' => ['phpstan_level' => 5, 'phpstan_baseline' => 36],
        ]);
});

it('tells how to create a missing file', function (): void {
    HarnessConfig::load(harnessTempDir());
})->throws(InvalidConfig::class, 'pollora-qa init');

it('keeps the gates a repository only observes, and writes them back', function (): void {
    $config = HarnessConfig::fromArray(['profile' => 'core', 'mode' => 'enforce', 'observed' => ['Rector', 'Code style']]);

    expect($config->observed)->toBe(['Rector', 'Code style'])
        ->and($config->toArray()['observed'])->toBe(['Rector', 'Code style'])
        ->and(HarnessConfig::fromArray(['profile' => 'core'])->toArray())->not->toHaveKey('observed');
});
