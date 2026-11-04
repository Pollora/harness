<?php

declare(strict_types=1);

use Pollora\Harness\Config\HarnessConfig;
use Pollora\Harness\Console\Commands\InitCommand;
use Pollora\Harness\Console\Io;
use Pollora\Harness\Scaffold\AgentsFile;
use Pollora\Harness\Scaffold\ComposerScripts;
use Pollora\Harness\Scaffold\Scaffolder;
use Pollora\Harness\Support\Release;

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

it('says which includes it removes from the PHPStan configuration', function (): void {
    $root = packageRoot();
    file_put_contents($root.'/phpstan.neon', "includes:\n    - vendor/szepeviktor/phpstan-wordpress/extension.neon\n\nparameters:\n    level: 6\n");

    $changes = (new Scaffolder($root, HarnessConfig::fromArray(['profile' => 'core'])))->apply(6);

    expect($changes)->toContain('removed vendor/szepeviktor/phpstan-wordpress/extension.neon from the includes of phpstan.neon: the harness preset already loads it')
        ->and((string) file_get_contents($root.'/phpstan.neon'))->not->toContain('szepeviktor');
});

it('says nothing about includes when it removes none', function (): void {
    $root = packageRoot();
    file_put_contents($root.'/phpstan.neon', "includes:\n    - phpstan-baseline.neon\n");

    $changes = (new Scaffolder($root, HarnessConfig::fromArray(['profile' => 'core'])))->apply(6);

    expect(implode("\n", $changes))->not->toContain('removed ');
});

it('does not repeat the .gitattributes lines the repository already has, or that it would add twice', function (): void {
    $root = packageRoot();
    file_put_contents($root.'/.gitattributes', "* text=auto\n/tests export-ignore\n.claude/ export-ignore\n");
    mkdir($root.'/tests', 0o755, true);
    mkdir($root.'/.claude');
    file_put_contents($root.'/rector.php', '<?php');

    (new Scaffolder($root, HarnessConfig::fromArray(['profile' => 'core'])))->apply(5);
    $lines = array_values(array_filter(array_map('trim', explode("\n", (string) file_get_contents($root.'/.gitattributes')))));
    $paths = array_values(array_map(static fn (string $line): string => trim(explode(' ', $line)[0], '/'), array_filter($lines, static fn (string $line): bool => str_contains($line, 'export-ignore'))));

    expect($paths)->toBe(array_values(array_unique($paths)))
        ->and(array_count_values($lines)['/tests export-ignore'])->toBe(1)
        ->and($lines)->not->toContain('/.claude export-ignore')
        ->and($lines)->toContain('/.gitattributes export-ignore', '/rector.php export-ignore');

    $before = (string) file_get_contents($root.'/.gitattributes');
    (new Scaffolder($root, HarnessConfig::fromArray(['profile' => 'core'])))->apply(5);
    expect((string) file_get_contents($root.'/.gitattributes'))->toBe($before);
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

it('pins the generated workflow to the given release tag', function (): void {
    $root = packageRoot();

    (new Scaffolder($root, HarnessConfig::fromArray(['profile' => 'core']), 'v0.1.17'))->apply(5);

    expect((string) file_get_contents($root.'/.github/workflows/harness.yml'))
        ->toContain('Pollora/harness/.github/workflows/php-package.yml@v0.1.17')
        ->not->toContain('@main')
        ->not->toContain('{{');
});

it('reads the tag of the installed release, and falls back to main for a branch', function (?string $version, ?string $tag): void {
    expect(Release::tag($version))->toBe($tag);
})->with([
    'a tag with v' => ['v0.1.17', 'v0.1.17'],
    'a tag without v' => ['0.2.0', 'v0.2.0'],
    'a branch' => ['dev-main', null],
    'a pre-release' => ['v0.2.0-beta.1', null],
    'unknown' => [null, null],
]);

it('keeps the formatting of composer.json when it adds the scripts', function (): void {
    $root = packageRoot();
    $original = <<<'JSON'
{
  "name": "pollora/cli",
  "keywords": ["pollora", "cli"],
  "require": {
    "php": "^8.3"
  },
  "scripts": {
    "test": "pest"
  },
  "config": {
    "sort-packages": true
  }
}

JSON;
    file_put_contents($root.'/composer.json', $original);

    (new Scaffolder($root, HarnessConfig::fromArray(['profile' => 'core'])))->apply(5);

    $patched = (string) file_get_contents($root.'/composer.json');

    expect($patched)->toContain('"keywords": ["pollora", "cli"],')
        ->and($patched)->toContain("    \"test\": \"pest\",\n    \"qa\": \"@php vendor/bin/pollora-qa check\",\n    \"qa:fast\": \"@php vendor/bin/pollora-qa check --fast\"\n  },")
        ->and(json_decode($patched, true)['scripts'])->toHaveKeys(['test', 'qa', 'qa:fast']);
});

it('adds a scripts block to a composer.json that has none, in its own style', function (string $json): void {
    $patched = ComposerScripts::add($json, ['qa' => '@php vendor/bin/pollora-qa check']);

    expect(json_decode((string) $patched, true))->toBe([
        'name' => 'a/b',
        'keywords' => ['x', 'y'],
        'scripts' => ['qa' => '@php vendor/bin/pollora-qa check'],
    ])->and($patched)->toContain('"keywords": ["x", "y"]');
})->with([
    'spaces' => ["{\n  \"name\": \"a/b\",\n  \"keywords\": [\"x\", \"y\"]\n}\n"],
    'tabs' => ["{\n\t\"name\": \"a/b\",\n\t\"keywords\": [\"x\", \"y\"]\n}\n"],
]);

it('fills an empty scripts object and ignores a scripts key nested elsewhere', function (): void {
    $patched = ComposerScripts::add('{"extra": {"scripts": {"a": "b"}}, "scripts": {}}', ['qa' => 'x']);

    expect(json_decode((string) $patched, true))->toBe(['extra' => ['scripts' => ['a' => 'b']], 'scripts' => ['qa' => 'x']]);
});

it('does not write a downstream repository the user did not ask for', function (): void {
    $root = packageRoot();
    $stream = fopen('php://memory', 'w+');
    $io = new Io($root, [], ['profile' => 'core'], $stream, $stream);

    expect((new InitCommand)->run($io))->toBe(0)
        ->and(json_decode((string) file_get_contents($root.'/.harness.json'), true))->not->toHaveKey('downstream');
});
