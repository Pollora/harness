<?php

declare(strict_types=1);

use Pest\TestSuite;
use Pollora\Harness\Config\RuleExemption;
use Pollora\Harness\Pest\Architecture;
use Pollora\Harness\Profile;

/**
 * A hexagonal package under a namespace of its own, so that the tests it registers have names no other test uses.
 */
function covHexagon(string $namespace, bool $withExceptions = true): string
{
    $root = harnessTempDir();
    $path = str_replace('\\', '/', $namespace);

    foreach (['Domain', 'Application', 'Infrastructure', 'UI'] as $layer) {
        mkdir("{$root}/src/{$layer}", 0o755, true);
        file_put_contents("{$root}/src/{$layer}/{$layer}Thing.php", "<?php\n\ndeclare(strict_types=1);\n\nnamespace {$namespace}\\{$layer};\n\nfinal class {$layer}Thing {}\n");
    }

    if ($withExceptions) {
        mkdir("{$root}/src/Domain/Exceptions", 0o755, true);
        file_put_contents("{$root}/src/Domain/Exceptions/GoneException.php", "<?php\n\ndeclare(strict_types=1);\n\nnamespace {$namespace}\\Domain\\Exceptions;\n\nfinal class GoneException extends \\Exception {}\n");
    }

    file_put_contents($root.'/composer.json', json_encode(['autoload' => ['psr-4' => [$namespace.'\\' => 'src/']]], JSON_UNESCAPED_SLASHES));

    return $root;
}

/**
 * The tests a repository registers, by name.
 *
 * @return list<string>
 */
function covRegisteredNames(string $root, ?Profile $profile = null, ?array $exemptions = null): array
{
    $before = array_keys(covPestTests());
    Architecture::for($root, $profile, $exemptions)->register();

    return array_values(array_diff(array_keys(covPestTests()), $before));
}

/**
 * Every test Pest knows, by file and description.
 *
 * @return array<string, true>
 */
function covPestTests(): array
{
    $repository = TestSuite::getInstance()->tests;
    $names = [];

    foreach ($repository->getFilenames() as $filename) {
        foreach ($repository->get($filename)->methods ?? [] as $name => $method) {
            $names[$filename.'::'.(string) ($method->description ?? $name)] = true;
        }
    }

    return $names;
}

/**
 * Pest refuses two tests with one description in one file, and ARCH-08 has a fixed one: a process
 * can register the rules once, so the whole registration is checked in a single test.
 */
it('registers one test per rule and layer, and registers an exempted rule as a skipped test', function (): void {
    $exemptions = [new RuleExemption('ARCH-01', 'the domain talks to Illuminate for now'), new RuleExemption('CONV-05', 'legacy env() calls')];
    $names = covRegisteredNames(covHexagon('Cov\\One'), Profile::Core, $exemptions);
    $text = implode("\n", $names);

    expect($text)->toContain('ARCH-09 Cov\\One declares strict types')
        ->and($text)->toContain('ARCH-01 Cov\\One\\Domain stays independent')
        ->and($text)->toContain('CONV-07 Cov\\One\\Domain receives')
        ->and($text)->toContain('ARCH-03 Cov\\One\\Application depends on the domain only')
        ->and($text)->toContain('CONV-07 Cov\\One\\Application receives')
        ->and($text)->toContain('ARCH-08 attribute classes are final')
        ->and($text)->toContain('CONV-03 Cov\\One\\Domain\\Exceptions names its classes')
        ->and($text)->toContain('CONV-05 Cov\\One reads configuration');
});

it('registers no hexagonal rule for a profile that is not hexagonal', function (): void {
    $architecture = Architecture::for(covHexagon('Cov\\Three'), Profile::Integration, []);
    $before = covPestTests();

    (new ReflectionMethod($architecture, 'hexagon'))->invoke($architecture);

    expect(covPestTests())->toBe($before);
});

it('registers no hexagonal rule for a repository without layers, and no CONV-03 without an Exceptions namespace', function (): void {
    $root = harnessTempDir();
    mkdir($root.'/src');
    file_put_contents($root.'/src/Plain.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Cov\\Four;\n\nfinal class Plain {}\n");
    file_put_contents($root.'/composer.json', '{"autoload": {"psr-4": {"Cov\\\\Four\\\\": "src/"}}}');
    $flat = Architecture::for($root, Profile::Core, []);
    $withoutExceptions = Architecture::for(covHexagon('Cov\\Five', false), Profile::Core, []);
    $before = covPestTests();

    (new ReflectionMethod($flat, 'hexagon'))->invoke($flat);
    (new ReflectionMethod($flat, 'naming'))->invoke($flat);
    (new ReflectionMethod($withoutExceptions, 'naming'))->invoke($withoutExceptions);

    expect(covPestTests())->toBe($before);
});

it('reads the profile and the exemptions of .harness.json when it is given neither', function (): void {
    $root = covHexagon('Cov\\Six');
    file_put_contents($root.'/.harness.json', json_encode(['profile' => 'integration']));
    $architecture = Architecture::for($root);
    $before = covPestTests();

    (new ReflectionMethod($architecture, 'hexagon'))->invoke($architecture);

    expect((new ReflectionProperty($architecture, 'profile'))->getValue($architecture))->toBe(Profile::Integration)
        ->and(covPestTests())->toBe($before);
});
