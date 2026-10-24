<?php

declare(strict_types=1);

use Pollora\Harness\Architecture\AttributeClasses;
use Pollora\Harness\Architecture\Layers;

it('finds the layers from the PSR-4 map', function (): void {
    $layers = Layers::discover(fixtureProject());
    $root = 'Pollora\\Harness\\Tests\\Fixtures\\Project';

    expect($layers->roots)->toBe([$root])
        ->and($layers->of('domain'))->toBe([$root.'\\Domain'])
        ->and($layers->of('application'))->toBe([$root.'\\Application'])
        ->and($layers->of('infrastructure'))->toBe([$root.'\\Infrastructure'])
        ->and($layers->isHexagonal())->toBeTrue()
        ->and($layers->files)->toHaveKey($root.'\\Domain\\Exceptions\\OrderNotFoundException');
});

it('sees a repository without a domain as not hexagonal', function (): void {
    $root = harnessTempDir();
    mkdir($root.'/src');
    file_put_contents($root.'/composer.json', '{"autoload": {"psr-4": {"Acme\\\\": "src/"}}}');

    expect(Layers::discover($root)->isHexagonal())->toBeFalse();
});

it('reports every way an attribute class breaks ARCH-08, and nothing for a good one', function (): void {
    $problems = AttributeClasses::problems(Layers::discover(fixtureProject()));
    $open = 'Pollora\\Harness\\Tests\\Fixtures\\Project\\Attributes\\OpenAttribute';

    expect($problems)->toBe([
        "{$open} is not final.",
        "{$open}::\$name is public and not readonly.",
        "{$open} does not declare its targets: use #[Attribute(Attribute::TARGET_CLASS)] or the targets it supports.",
    ]);
});
