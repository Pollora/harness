<?php

declare(strict_types=1);

use Pollora\Harness\Support\Paths;

function pintRoot(?string $pintJson): string
{
    $root = sys_get_temp_dir().'/harness-pint-'.bin2hex(random_bytes(4));
    mkdir($root);

    if ($pintJson !== null) {
        file_put_contents($root.'/pint.json', $pintJson);
    }

    return $root;
}

it('uses the harness preset where the repository has no pint.json', function (): void {
    expect(Paths::pintConfig(pintRoot(null)))->toBe(Paths::preset('pint.json'));
});

it("keeps the repository's own pint.json when it requires strict types", function (): void {
    $root = pintRoot('{"preset": "laravel", "rules": {"declare_strict_types": true}}');

    expect(Paths::pintConfig($root))->toBe($root.'/pint.json');
});

it('falls back on the preset where the pint.json would let strict types go (ARCH-09)', function (): void {
    expect(Paths::pintConfig(pintRoot('{"preset": "laravel"}')))->toBe(Paths::preset('pint.json'))
        ->and(Paths::pintConfig(pintRoot('{"rules": {"declare_strict_types": false}}')))->toBe(Paths::preset('pint.json'))
        ->and(Paths::pintConfig(pintRoot('not json')))->toBe(Paths::preset('pint.json'));
});
