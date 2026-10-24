<?php

declare(strict_types=1);

use Pollora\Harness\Config\HarnessConfig;
use Pollora\Harness\Deps\DependencyPolicy;

function violations(array $config, array $require): array
{
    $found = (new DependencyPolicy(HarnessConfig::fromArray($config)))->check($require);

    return array_map(static fn ($violation): string => $violation->rule, $found);
}

it('keeps a core package free of Laravel (ARCH-04)', function (): void {
    expect(violations(['profile' => 'core'], ['psr/log' => '^3.0', 'illuminate/support' => '^13.0']))->toBe(['ARCH-04']);
});

it('accepts a Laravel package listed as an exception', function (): void {
    expect(violations(['profile' => 'core', 'allow_laravel' => ['illuminate/collections']], ['illuminate/collections' => '^13.0']))->toBe([]);
});

it('flags an exception that is no longer needed', function (): void {
    expect(violations(['profile' => 'core', 'allow_laravel' => ['illuminate/support']], ['psr/log' => '^3.0']))->toBe(['ARCH-04']);
});

it('lets only integrations, templates and projects require the framework (ARCH-06)', function (string $profile, array $expected): void {
    expect(violations(['profile' => $profile], ['pollora/framework' => '^13.0']))->toBe($expected);
})->with([
    'core' => ['core', ['ARCH-06']],
    'wp-plugin' => ['wp-plugin', ['ARCH-06']],
    'integration' => ['integration', []],
    'template' => ['template', []],
    'project' => ['project', []],
]);

it('lets the framework itself depend on Laravel', function (): void {
    expect(violations(['profile' => 'framework'], ['illuminate/support' => '^13.0']))->toBe([]);
});

it('reads only the runtime requirements of composer.json', function (): void {
    expect(DependencyPolicy::readRequire(fixtureProject()))->toBe([
        'php' => '^8.3',
        'illuminate/support' => '^13.0',
        'pollora/framework' => '^13.0',
    ]);
});

it('does not count a Laravel tool run as a binary as a dependency on Laravel', function (): void {
    expect(violations(['profile' => 'core'], ['laravel/pint' => '^1.22']))->toBe([]);
});
