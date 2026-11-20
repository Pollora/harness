<?php

declare(strict_types=1);

use Pollora\Harness\Architecture\SourceTypes;

it('reads the kind, the modifiers, the parent and the traits of a type, with names resolved through the imports', function (): void {
    $source = <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace Acme\Shop;

        use Illuminate\Support\Traits\Macroable;
        use Acme\Base\{Model as BaseModel, Other};

        /** Docblock. */
        #[Deprecated]
        abstract class Cart extends BaseModel implements \Countable
        {
            use Macroable, \Acme\Shop\Concerns\Totals {
                total as protected;
            }

            public function total(): int
            {
                return array_sum([1, 2]);
            }
        }

        final class Line extends Other {}
        PHP;

    [$cart, $line] = SourceTypes::parse($source);

    expect($cart->name)->toBe('Acme\\Shop\\Cart')
        ->and($cart->kind)->toBe('class')
        ->and($cart->abstract)->toBeTrue()
        ->and($cart->final)->toBeFalse()
        ->and($cart->extends)->toBe('Acme\\Base\\Model')
        ->and($cart->traits)->toBe(['Illuminate\\Support\\Traits\\Macroable', 'Acme\\Shop\\Concerns\\Totals'])
        ->and($cart->usesTraitNamed('Macroable'))->toBeTrue()
        ->and($cart->annotations)->toContain('Docblock')->toContain('#[Deprecated]')
        ->and(array_keys($cart->methods))->toBe(['total'])
        ->and($cart->bodies['total'])->toContain('array_sum')
        ->and($line->final)->toBeTrue()
        ->and($line->extends)->toBe('Acme\\Base\\Other')
        ->and($line->shortName())->toBe('Line')
        ->and($line->resolve('\\Fully\\Qualified'))->toBe('Fully\\Qualified')
        ->and($line->resolve('Local'))->toBe('Acme\\Shop\\Local');
});

it('tells interfaces, traits and enums from classes, and ignores anonymous classes and ::class', function (): void {
    $source = <<<'PHP'
        <?php

        namespace Acme;

        interface Contract {}
        trait Helps {}
        enum Mode: string { case A = 'a'; }
        final readonly class Value
        {
            public function make(): object
            {
                $name = self::class;

                return new class {};
            }
        }
        PHP;

    $types = SourceTypes::parse($source);

    expect(array_map(static fn ($type): string => $type->kind, $types))->toBe(['interface', 'trait', 'enum', 'class'])
        ->and(array_map(static fn ($type): string => $type->name, $types))->toBe(['Acme\\Contract', 'Acme\\Helps', 'Acme\\Mode', 'Acme\\Value'])
        ->and($types[3]->final)->toBeTrue()
        ->and(array_keys($types[3]->methods))->toBe(['make']);
});

it('follows a braced namespace and several namespaces in one file', function (): void {
    $source = "<?php\nnamespace One { class A {} }\nnamespace Two { use One\\A as First; class B extends First {} }\n";

    $types = SourceTypes::parse($source);

    expect(array_map(static fn ($type): string => $type->name, $types))->toBe(['One\\A', 'Two\\B'])
        ->and($types[1]->extends)->toBe('One\\A');
});

it('keeps the annotations of each method and the tokens of its body', function (): void {
    $source = "<?php\nnamespace T;\nclass A extends B\n{\n    #[Test]\n    public function works(): void { \$x = \"{\$y}\"; }\n\n    /** @test */\n    public function other() {}\n\n    abstract protected function nothing(): void;\n}\n";

    [$type] = SourceTypes::parse($source);

    expect($type->methods['works'])->toContain('#[')->toContain('Test')
        ->and($type->methods['other'])->toContain('@test')
        ->and($type->bodies['nothing'])->toBe([])
        ->and($type->bodies['works'])->toContain('$x');
});

it('reads each file once and again when it changes', function (): void {
    $path = harnessTempDir().'/A.php';
    file_put_contents($path, "<?php\nclass First {}\n");
    $first = SourceTypes::ofFile($path);
    touch($path, time() - 100);
    file_put_contents($path, "<?php\nclass Second {}\n");
    touch($path, time() + 100);

    expect($first[0]->name)->toBe('First')
        ->and(SourceTypes::ofFile($path)[0]->name)->toBe('Second')
        ->and(SourceTypes::ofFile($path.'.missing'))->toBe([]);
});
