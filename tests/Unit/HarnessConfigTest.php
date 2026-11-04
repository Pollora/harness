<?php

declare(strict_types=1);

use Pollora\Harness\Config\HarnessConfig;
use Pollora\Harness\Config\InvalidConfig;
use Pollora\Harness\Config\Mode;
use Pollora\Harness\Config\Ratchet;
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

it('keeps the commands allowed to keep their name, with their reason, and writes them back', function (): void {
    $allowed = [
        ['command' => 'new', 'reason' => 'named for the tool'],
        ['prefix' => 'nectar:', 'reason' => 'the product name'],
    ];
    $config = HarnessConfig::fromArray(['profile' => 'core', 'allow_commands' => $allowed]);

    expect($config->allowCommands)->toHaveCount(2)
        ->and($config->toArray()['allow_commands'])->toBe($allowed)
        ->and($config->withRatchet(new Ratchet)->toArray()['allow_commands'])->toBe($allowed)
        ->and(HarnessConfig::fromArray(['profile' => 'core'])->toArray())->not->toHaveKey('allow_commands');
});

it('refuses an allowed command that gives no reason', function (array $entry): void {
    HarnessConfig::fromArray(['profile' => 'core', 'allow_commands' => [$entry]]);
})->with([
    'no reason' => [['command' => 'new']],
    'an empty reason' => [['command' => 'new', 'reason' => ' ']],
])->throws(InvalidConfig::class, 'without a reason');

it('refuses an allowed command that names neither a command nor a prefix, or both', function (array $entry): void {
    HarnessConfig::fromArray(['profile' => 'core', 'allow_commands' => [$entry]]);
})->with([
    'neither' => [['reason' => 'x']],
    'both' => [['command' => 'new', 'prefix' => 'n', 'reason' => 'x']],
    'an empty prefix' => [['prefix' => '', 'reason' => 'x']],
])->throws(InvalidConfig::class, 'needs either');

it('keeps the architecture rules exempted, with their reason, and writes them back', function (): void {
    $exempted = [['rule' => 'ARCH-08', 'reason' => 'published attribute classes cannot become final']];
    $config = HarnessConfig::fromArray(['profile' => 'core', 'allow_rules' => $exempted]);

    expect($config->allowRules)->toHaveCount(1)
        ->and($config->allowRules[0]->message())->toBe('exempted: ARCH-08 — published attribute classes cannot become final')
        ->and($config->toArray()['allow_rules'])->toBe($exempted)
        ->and($config->withRatchet(new Ratchet)->toArray()['allow_rules'])->toBe($exempted)
        ->and(HarnessConfig::fromArray(['profile' => 'core'])->toArray())->not->toHaveKey('allow_rules');
});

it('refuses an exempted rule that gives no reason, no rule, or an unusable one', function (array $entries, string $message): void {
    HarnessConfig::fromArray(['profile' => 'core', 'allow_rules' => $entries]);
})->with([
    'no reason' => [[['rule' => 'ARCH-08']], 'without a reason'],
    'an empty reason' => [[['rule' => 'ARCH-08', 'reason' => ' ']], 'without a reason'],
    'no rule' => [[['reason' => 'x']], 'needs a "rule"'],
    'an unknown rule' => [[['rule' => 'ARCH-99', 'reason' => 'x']], 'not a rule of the catalogue'],
    'a rule tests/Arch.php does not evaluate' => [[['rule' => 'ARCH-04', 'reason' => 'x']], 'does not evaluate'],
    'the same rule twice' => [[['rule' => 'ARCH-08', 'reason' => 'x'], ['rule' => 'ARCH-08', 'reason' => 'y']], 'twice'],
])->throws(InvalidConfig::class);

it('names what is wrong with an exempted rule', function (array $entries, string $message): void {
    try {
        HarnessConfig::fromArray(['profile' => 'core', 'allow_rules' => $entries]);
    } catch (InvalidConfig $invalidConfig) {
        expect($invalidConfig->getMessage())->toContain($message);

        return;
    }

    $this->fail('The configuration should have been refused.');
})->with([
    'no reason' => [[['rule' => 'ARCH-08']], 'allow_rules exempts "ARCH-08" without a reason'],
    'an unknown rule' => [[['rule' => 'ARCH-99', 'reason' => 'x']], '"ARCH-99", which is not a rule of the catalogue'],
    'a rule tests/Arch.php does not evaluate' => [[['rule' => 'ARCH-04', 'reason' => 'x']], '"ARCH-04", which tests/Arch.php does not evaluate'],
]);
