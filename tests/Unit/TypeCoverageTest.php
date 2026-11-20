<?php

declare(strict_types=1);

use Pollora\Harness\Metrics\Collector;
use Pollora\Harness\Metrics\TypeCoverage;

/**
 * Builds a throw-away project from a map of relative path => contents.
 *
 * @param  array<string, string>  $files
 */
function typeCoverageProject(array $files): string
{
    $root = harnessTempDir();

    foreach ($files as $path => $contents) {
        if (! is_dir(dirname($root.'/'.$path))) {
            mkdir(dirname($root.'/'.$path), 0o755, true);
        }

        file_put_contents($root.'/'.$path, $contents);
    }

    return $root;
}

it('counts returns, parameters and properties, and reads none of them as typed when absent', function (): void {
    $root = typeCoverageProject(['src/A.php' => <<<'PHP'
        <?php
        final class A
        {
            public $untyped;
            public int $typed = 0;
            public $a, $b;
            public function run($x, int $y)
            {
            }
            public function ok(string $x): void
            {
            }
        }
        PHP]);

    $report = TypeCoverage::measure($root);

    expect($report->returns)->toBe([1, 2])
        ->and($report->parameters)->toBe([2, 3])
        ->and($report->properties)->toBe([1, 4])
        ->and($report->percentage())->toBe(round(4 / 9 * 100, 2));
});

it('leaves out the return type of constructors and destructors and counts promoted parameters once', function (): void {
    $root = typeCoverageProject(['src/B.php' => <<<'PHP'
        <?php
        final class B
        {
            public function __construct(private int $a, private $b) {}
            public function __destruct() {}
            public function __clone() {}
        }
        PHP]);

    $report = TypeCoverage::measure($root);

    expect($report->returns)->toBe([0, 1])
        ->and($report->parameters)->toBe([1, 2])
        ->and($report->properties)->toBe([0, 0]);
});

it('counts mixed as a type and ignores closures and arrow functions', function (): void {
    $root = typeCoverageProject(['src/C.php' => <<<'PHP'
        <?php
        function c(mixed $a): mixed
        {
            $f = function ($x) { return $x; };
            $g = fn ($y) => $y;

            return $f($a) ?? $g($a);
        }
        PHP]);

    expect(TypeCoverage::measure($root)->percentage())->toBe(100.0);
});

it('measures the methods of interfaces, traits, enums and anonymous classes', function (): void {
    $root = typeCoverageProject(['src/D.php' => <<<'PHP'
        <?php
        interface I { public function a($x); }
        trait T { public $p; public function b(): void {} }
        enum E: string { case A = 'a'; public function c() {} }
        $o = new class { public function d(int $x): int { return $x; } };
        PHP]);

    $report = TypeCoverage::measure($root);

    expect($report->returns)->toBe([2, 4])
        ->and($report->parameters)->toBe([1, 2])
        ->and($report->properties)->toBe([0, 1]);
});

it('replaces the placeholders of theme templates before parsing', function (): void {
    $root = typeCoverageProject(['app/Theme.php' => <<<'PHP'
        <?php
        namespace %namespace%;
        final class %class_name% { public function boot(): void {} }
        PHP]);

    $report = TypeCoverage::measure($root);

    expect($report->unparsed)->toBe([])
        ->and($report->percentage())->toBe(100.0);
});

it('leaves out the parameters of untyped WordPress overrides, but still counts their return type', function (): void {
    $root = typeCoverageProject(['app/Walker.php' => <<<'PHP'
        <?php
        namespace App;
        final class Menu extends \Walker_Nav_Menu
        {
            public function start_el(&$output, $item, $depth = 0, $args = null, $id = 0)
            {
            }
            public function helper($x)
            {
            }
        }
        PHP]);

    $report = TypeCoverage::measure($root);

    expect($report->excluded)->toBe(5)
        ->and($report->returns)->toBe([0, 2])
        ->and($report->parameters)->toBe([0, 1]);
});

it('does not treat a class of the same namespace named like a WordPress one as a WordPress parent', function (): void {
    $root = typeCoverageProject(['app/Walker.php' => <<<'PHP'
        <?php
        namespace App;
        final class Menu extends Walker_Nav_Menu
        {
            public function start_el($output): void
            {
            }
        }
        PHP]);

    $report = TypeCoverage::measure($root);

    expect($report->excluded)->toBe(0)
        ->and($report->parameters)->toBe([0, 1]);
});

it('reports a file that does not parse instead of ignoring it', function (): void {
    $root = typeCoverageProject([
        'src/Broken.php' => '<?php function (',
        'src/Fine.php' => '<?php function ok(): void {}',
    ]);

    $report = TypeCoverage::measure($root);

    expect($report->unparsed)->toBe(['src/Broken.php'])
        ->and($report->files)->toBe(1)
        ->and(implode("\n", $report->lines()))->toContain('src/Broken.php does not parse');
});

it('reads the autoload roots and files of composer.json, once each, and skips vendor, tests, node_modules and Blade', function (): void {
    $root = typeCoverageProject([
        'composer.json' => json_encode(['autoload' => ['psr-4' => ['A\\' => ['src/', 'lib/'], 'B\\' => 'src/Sub/'], 'files' => ['bootstrap.php']]]),
        'src/One.php' => '<?php function one($a) {}',
        'src/Sub/Two.php' => '<?php function two($a) {}',
        'lib/Three.php' => '<?php function three($a) {}',
        'bootstrap.php' => '<?php function boot($a) {}',
        'src/vendor/Skip.php' => '<?php function skip($a) {}',
        'src/tests/Skip.php' => '<?php function skip2($a) {}',
        'src/node_modules/Skip.php' => '<?php function skip3($a) {}',
        'src/view.blade.php' => '<?php function skip4($a) {}',
        'src/readme.txt' => 'not php',
    ]);

    $report = TypeCoverage::measure($root);

    expect($report->files)->toBe(4)
        ->and($report->parameters)->toBe([0, 4]);
});

it('is not measured when there is nothing to type, rather than 100 or 0', function (): void {
    $report = TypeCoverage::measure(typeCoverageProject(['src/Empty.php' => '<?php // nothing']));

    expect($report->percentage())->toBeNull()
        ->and($report->lines())->toBe([])
        ->and(TypeCoverage::measure(harnessTempDir())->percentage())->toBeNull();
});

it('lists the five files with the most untyped declarations, worst first, ties by path', function (): void {
    $files = [];

    foreach ([1, 2, 3, 3, 4, 5, 6] as $index => $untyped) {
        $files['src/F'.$index.'.php'] = '<?php '.implode(' ', array_map(static fn (int $n): string => "function f{$n}x{$index}(\$a): void {}", range(1, $untyped)));
    }

    $report = TypeCoverage::measure(typeCoverageProject($files));

    expect($report->worstFiles)->toBe(['src/F6.php' => 6, 'src/F5.php' => 5, 'src/F4.php' => 4, 'src/F2.php' => 3, 'src/F3.php' => 3])
        ->and($report->lines()[0])->toContain('Type coverage: 50.00 %')
        ->and($report->lines()[1])->toBe('    6 untyped in src/F6.php');
});

it('reports the share of each kind in the summary line', function (): void {
    $root = typeCoverageProject(['src/G.php' => '<?php function g(int $a): void {}']);

    expect(TypeCoverage::measure($root)->lines()[0])
        ->toBe('  Type coverage: 100.00 % (2 of 2 declarations: returns 100.00 %, parameters 100.00 %, properties n/a).');
});

it('feeds the type_coverage metric of the collector, unless a value is given', function (): void {
    $root = typeCoverageProject(['src/H.php' => '<?php function h($a): void {}']);

    expect((new Collector($root))->collect()['type_coverage'])->toBe(50.0)
        ->and((new Collector($root))->collect(overrides: ['type_coverage' => 98.4])['type_coverage'])->toBe(98.4)
        ->and((new Collector(harnessTempDir()))->collect()['type_coverage'])->toBeNull();
});
