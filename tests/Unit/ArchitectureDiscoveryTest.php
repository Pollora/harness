<?php

declare(strict_types=1);

use Pollora\Harness\Architecture\AttributeClasses;
use Pollora\Harness\Architecture\Layers;
use Pollora\Harness\Architecture\StrictTypes;

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

it('names the classes whose file does not declare strict types (ARCH-09)', function (): void {
    $root = harnessTempDir();
    mkdir($root.'/src');
    file_put_contents($root.'/composer.json', '{"autoload": {"psr-4": {"Acme\\\\Shared\\\\": "src/"}}}');
    file_put_contents($root.'/src/Strict.php', "<?php\n\n/** Header. */\n\ndeclare(strict_types=1);\n\nnamespace Acme\\Shared;\n\nfinal class Strict {}\n");
    file_put_contents($root.'/src/Loose.php', "<?php\n\nnamespace Acme\\Shared;\n\nfinal class Loose {}\n");

    expect(StrictTypes::violations(Layers::discover($root), 'Acme\\Shared'))->toBe(['Acme\\Shared\\Loose']);
});

it('reads only the files of the repository when a dependency maps the same namespace (ARCH-09)', function (): void {
    $root = harnessTempDir();
    mkdir($root.'/src');
    mkdir($root.'/vendor/acme/vocabulary/Shared', 0777, true);
    file_put_contents($root.'/composer.json', '{"autoload": {"psr-4": {"Acme\\\\Shared\\\\": "src/"}}}');
    file_put_contents($root.'/src/Strict.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Acme\\Shared;\n\nfinal class Strict {}\n");
    file_put_contents($root.'/vendor/acme/vocabulary/Shared/Interval.php', "<?php\n\nnamespace Acme\\Shared;\n\nenum Interval {}\n");

    $layers = Layers::discover($root);

    expect($layers->files)->not->toHaveKey('Acme\\Shared\\Interval')
        ->and(StrictTypes::violations($layers, 'Acme\\Shared'))->toBe([]);
});

it('ignores Blade templates and files that declare no type (ARCH-09)', function (): void {
    $root = harnessTempDir();
    mkdir($root.'/src/Prompts', 0777, true);
    file_put_contents($root.'/composer.json', '{"autoload": {"psr-4": {"Acme\\\\Shared\\\\": "src/"}}}');
    file_put_contents($root.'/src/Prompts/upgrade.blade.php', "<h1>{{ \$title }}</h1>\n@php(\$x = 1)\n");
    file_put_contents($root.'/src/Prompts/php-blade.blade.php', "<?php\n\nfinal class Shown {}\n");
    file_put_contents($root.'/src/helpers.php', "<?php\n\nfunction helper(): string\n{\n    return Foo::class.' is a class and an enum';\n}\n// class Commented {}\n\$o = new class {};\n");
    file_put_contents($root.'/src/Loose.php', "<?php\n\nnamespace Acme\\Shared;\n\nenum Loose {}\n");

    expect(StrictTypes::violations(Layers::discover($root), 'Acme\\Shared'))->toBe(['Acme\\Shared\\Loose']);
});

it('detects a type declaration from the tokens', function (string $source, bool $expected): void {
    expect(StrictTypes::declaresType($source))->toBe($expected);
})->with([
    'class' => ['<?php final class A {}', true],
    'interface' => ['<?php interface A {}', true],
    'trait' => ['<?php trait A {}', true],
    'enum' => ['<?php enum A: string { case B = "b"; }', true],
    '::class' => ['<?php return A::class;', false],
    'anonymous class' => ['<?php $a = new class {};', false],
    'string and comment' => ['<?php /* class A */ $a = "interface B";', false],
    'html only' => ['<p>class A {}</p>', false],
]);
