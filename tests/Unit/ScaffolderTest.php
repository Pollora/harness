<?php

declare(strict_types=1);

use Pollora\Harness\Config\HarnessConfig;
use Pollora\Harness\Scaffold\AgentsFile;
use Pollora\Harness\Scaffold\Scaffolder;

function packageRoot(): string
{
    $root = harnessTempDir();
    mkdir($root.'/src');
    file_put_contents($root.'/composer.json', json_encode([
        'name' => 'pollora/example',
        'require' => ['php' => '^8.3'],
        'scripts' => ['test' => 'pest'],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    return $root;
}

it('applies the harness to a bare package', function (): void {
    $root = packageRoot();

    $changes = (new Scaffolder($root, HarnessConfig::fromArray(['profile' => 'core', 'downstream' => ['framework']])))->apply(5);

    expect($changes)->toContain('wrote .harness.json', 'created phpstan.neon', 'created tests/Arch.php', 'created rector.php', 'created .github/workflows/harness.yml', 'created .claude/settings.json', 'created AGENTS.md')
        ->and((string) file_get_contents($root.'/phpstan.neon'))->toContain('vendor/pollora/harness/presets/phpstan/base.neon', 'level: 5', '- src')
        ->and((string) file_get_contents($root.'/rector.php'))->toContain('Profile::Core')
        ->and((string) file_get_contents($root.'/.github/workflows/harness.yml'))->toContain('Pollora/harness/.github/workflows/php-package.yml')
        ->not->toContain('with:')
        ->and(json_decode((string) file_get_contents($root.'/.claude/settings.json'), true))->toHaveKeys(['hooks.PreToolUse', 'hooks.PostToolUse', 'hooks.Stop'])
        ->and(json_decode((string) file_get_contents($root.'/composer.json'), true)['scripts'])->toBe([
            'test' => 'pest',
            'qa' => '@php vendor/bin/pollora-qa check',
            'qa:fast' => '@php vendor/bin/pollora-qa check --fast',
        ])
        ->and((string) file_get_contents($root.'/.gitignore'))->toContain('/.harness/');
});

it('never overwrites a file the repository already has', function (): void {
    $root = packageRoot();
    file_put_contents($root.'/rector.php', '<?php // ours');

    $changes = (new Scaffolder($root, HarnessConfig::fromArray(['profile' => 'core'])))->apply(5);

    expect($changes)->toContain('kept the existing rector.php')
        ->and((string) file_get_contents($root.'/rector.php'))->toBe('<?php // ours');
});

it('adds the preset to an existing PHPStan configuration and keeps the rest', function (): void {
    $root = packageRoot();
    file_put_contents($root.'/composer.json', '{"require-dev": {"larastan/larastan": "^3.0"}}');
    file_put_contents($root.'/phpstan.neon', "includes:\n    - phpstan-baseline.neon\n\nparameters:\n    level: 6\n");

    (new Scaffolder($root, HarnessConfig::fromArray(['profile' => 'framework'])))->apply(6);

    expect((string) file_get_contents($root.'/phpstan.neon'))->toBe(
        "includes:\n    - vendor/pollora/harness/presets/phpstan/base.neon\n    - vendor/pollora/harness/presets/phpstan/laravel.neon\n    - phpstan-baseline.neon\n\nparameters:\n    level: 6\n",
    );
});

it('leaves the Laravel preset out where Larastan is not required', function (): void {
    $root = packageRoot();
    file_put_contents($root.'/composer.json', '{"require-dev": {"pestphp/pest": "^3.0"}}');
    file_put_contents($root.'/phpstan.neon', "includes:\n    - phpstan-baseline.neon\n");

    (new Scaffolder($root, HarnessConfig::fromArray(['profile' => 'integration'])))->apply(6);

    expect((string) file_get_contents($root.'/phpstan.neon'))->toBe(
        "includes:\n    - vendor/pollora/harness/presets/phpstan/base.neon\n    - phpstan-baseline.neon\n",
    );
});

it('drops the extensions the presets include, so PHPStan does not load them twice', function (): void {
    $root = packageRoot();
    file_put_contents($root.'/composer.json', '{"require-dev": {"larastan/larastan": "^3.0"}}');
    file_put_contents($root.'/phpstan.neon', "includes:\n    - phpstan-baseline.neon\n    - vendor/larastan/larastan/extension.neon\n    - vendor/szepeviktor/phpstan-wordpress/extension.neon\n\nparameters:\n    level: 6\n");

    (new Scaffolder($root, HarnessConfig::fromArray(['profile' => 'framework'])))->apply(6);

    expect((string) file_get_contents($root.'/phpstan.neon'))->toBe(
        "includes:\n    - vendor/pollora/harness/presets/phpstan/base.neon\n    - vendor/pollora/harness/presets/phpstan/laravel.neon\n    - phpstan-baseline.neon\n\nparameters:\n    level: 6\n",
    );
});

it('can run again without changing anything it already did', function (): void {
    $root = packageRoot();
    $config = HarnessConfig::fromArray(['profile' => 'core']);

    (new Scaffolder($root, $config))->apply(5);
    $second = (new Scaffolder($root, $config))->apply(5);

    expect(array_filter($second, static fn (string $change): bool => ! str_starts_with($change, 'kept') && $change !== 'wrote .harness.json'))->toBe([]);
});

it('imports AGENTS.md from an existing CLAUDE.md once', function (): void {
    $root = packageRoot();
    file_put_contents($root.'/CLAUDE.md', "# CLAUDE.md\n\nOur notes.\n");
    $config = HarnessConfig::fromArray(['profile' => 'core']);

    (new Scaffolder($root, $config))->apply(5);
    (new Scaffolder($root, $config))->apply(5);

    expect(substr_count((string) file_get_contents($root.'/CLAUDE.md'), '@AGENTS.md'))->toBe(1);
});

it('replaces only its own block in AGENTS.md', function (): void {
    $block = AgentsFile::block(HarnessConfig::fromArray(['profile' => 'core']));
    $existing = "# Agents\n\nOur own rules.\n\n".AgentsFile::START."\nold\n".AgentsFile::END."\n\nAfter.\n";

    $merged = AgentsFile::merge($existing, $block);

    expect($merged)->toStartWith("# Agents\n\nOur own rules.\n\n".AgentsFile::START)
        ->toEndWith(AgentsFile::END."\n\nAfter.\n")
        ->not->toContain("\nold\n")
        ->and(AgentsFile::merge($merged, $block))->toBe($merged);
});

it('lists every enforced and observed rule in AGENTS.md, and no planned one', function (): void {
    $block = AgentsFile::block(HarnessConfig::fromArray(['profile' => 'core']));

    expect($block)->toContain('**ARCH-02**', '**SEC-03**', '**QA-01**')
        ->not->toContain('**PERF-01**');
});
