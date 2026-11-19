<?php

declare(strict_types=1);

use Pest\TestSuite;
use Pollora\Harness\Architecture\ForeignNamespaces;
use Pollora\Harness\Pest\Architecture;
use Pollora\Harness\Profile;

/**
 * A hexagonal project (Cov\Foreign: Domain with Exceptions, Application) with a package installed under
 * vendor/ whose PSR-4 prefix is Cov\Foreign\Domain, as pollora/media 0.9 is for the framework.
 */
function foreignHexagon(): string
{
    $root = harnessTempDir();

    foreach (['Domain/Exceptions' => 'Domain\\Exceptions', 'Application' => 'Application'] as $directory => $namespace) {
        mkdir("{$root}/src/{$directory}", 0o755, true);
        file_put_contents("{$root}/src/{$directory}/Thing.php", "<?php\n\ndeclare(strict_types=1);\n\nnamespace Cov\\Foreign\\{$namespace};\n\nfinal class Thing {}\n");
    }

    mkdir($root.'/vendor/composer', 0o755, true);
    mkdir($root.'/vendor/acme/pkg/src/Domain/Exceptions', 0o755, true);
    file_put_contents($root.'/composer.json', '{"autoload": {"psr-4": {"Cov\\\\Foreign\\\\": "src/"}}}');
    file_put_contents($root.'/vendor/composer/autoload_psr4.php', "<?php\n\n\$vendorDir = dirname(__DIR__);\n\$baseDir = dirname(\$vendorDir);\n\nreturn [\n    'Cov\\\\Foreign\\\\Domain\\\\' => [\$vendorDir . '/acme/pkg/src/Domain'],\n    'Cov\\\\Foreign\\\\' => [\$baseDir . '/src'],\n];\n");

    return $root;
}

/**
 * The reasons for which the tests that match a name are skipped, null for a test that runs.
 *
 * @return array<string, string|null>
 */
function foreignRegistered(string $needle): array
{
    $repository = TestSuite::getInstance()->tests;
    $found = [];

    foreach ($repository->getFilenames() as $filename) {
        foreach ($repository->get($filename)->methods ?? [] as $method) {
            $description = (string) $method->description;

            if (! str_contains($description, $needle)) {
                continue;
            }

            $messages = (new ReflectionProperty($method->chains, 'messages'))->getValue($method->chains);
            $skip = null;

            foreach ($messages as $message) {
                if ($message->name === 'markTestSkipped') {
                    $skip = (string) $message->arguments[0];
                }
            }

            $found[$description] = $skip;
        }
    }

    return $found;
}

/**
 * A project directory with vendor/ and a PSR-4 map, for the namespaces that packages map.
 *
 * @param  array<string, string|list<string>>  $map  Prefix => directories, relative to the project
 */
function foreignProject(array $map): ForeignNamespaces
{
    $root = realpath(harnessTempDir()) ?: '';
    mkdir($root.'/src', 0o755, true);
    mkdir($root.'/vendor/acme/pkg/src', 0o755, true);
    mkdir($root.'/vendor/composer', 0o755, true);
    $absolute = [];

    foreach ($map as $prefix => $directories) {
        $absolute[$prefix] = array_map(static fn (string $directory): string => $root.'/'.$directory, (array) $directories);
    }

    file_put_contents($root.'/vendor/composer/autoload_psr4.php', "<?php\n\nreturn ".var_export($absolute, true).";\n");

    return ForeignNamespaces::discover($root);
}

/**
 * ARCH-08 has a fixed name and a process can register it once (see ArchitectureRegistrationTest), so the
 * rules on namespaces are registered without it, the Domain layer shared with the package and the
 * Application layer that is not in the same call.
 */
it('registers a rule as skipped, with the reason, on a namespace an installed package maps, and as a test on the others', function (): void {
    $architecture = Architecture::for(foreignHexagon(), Profile::Core, []);

    foreach (['hexagon', 'naming', 'configuration'] as $method) {
        (new ReflectionMethod($architecture, $method))->invoke($architecture);
    }

    $skipped = foreignRegistered('Cov\\Foreign\\Domain');
    $run = foreignRegistered('Cov\\Foreign\\Application');

    expect($skipped)->toHaveCount(3)
        ->and($skipped)->each->toContain('not evaluated')
        ->and(array_keys($skipped))->toContain('CONV-03 Cov\\Foreign\\Domain\\Exceptions names its classes ...Exception')
        ->and(array_values($skipped)[0])->toContain('is also mapped by an installed package (Cov\\Foreign\\Domain, under vendor/)')
        ->and($run)->toHaveCount(1)
        ->and(array_filter($run, static fn (?string $reason): bool => $reason !== null))->toBe([])
        ->and(foreignRegistered('CONV-05 Cov\\Foreign reads configuration'))->toHaveCount(1);
})->skip(
    version_compare(\Pest\version(), '4.0.0', '<'),
    'Registering tests while a test runs is refused by Pest 3 (it asserts on the call trace); Pest 4 accepts it.'
);

it('knows the prefixes that point under vendor/, and only those', function (): void {
    $foreign = foreignProject([
        'Acme\\Shared\\' => 'src',
        'Vendor\\One\\' => 'vendor/acme/pkg/src',
        'Vendor\\Two\\' => ['src', 'vendor/acme/pkg/src'],
    ]);

    expect($foreign->prefixes)->toBe(['Vendor\\One', 'Vendor\\Two']);
});

it('treats a namespace as shared when a foreign prefix starts it, as the engine does', function (string $namespace, ?string $prefix): void {
    $foreign = foreignProject(['Pollora\\Media\\' => 'vendor/acme/pkg/src', 'Pollora\\' => 'src']);

    expect($foreign->prefixFor($namespace))->toBe($prefix)
        ->and($foreign->overlaps($namespace))->toBe($prefix !== null);
})->with([
    'a layer inside the package' => ['Pollora\\Media\\Domain\\Exceptions', 'Pollora\\Media'],
    'the package root itself' => ['Pollora\\Media', 'Pollora\\Media'],
    'a sibling module' => ['Pollora\\Hook\\Domain', null],
    'the project root' => ['Pollora', null],
]);

it('reads a prefix with several foreign directories once', function (): void {
    $foreign = foreignProject(['Vendor\\Multi\\' => ['vendor/acme/pkg/src', 'vendor/acme/pkg/src']]);

    expect($foreign->prefixes)->toBe(['Vendor\\Multi']);
});

it('recognises a vendor path that is a symbolic link to a path repository outside the project', function (): void {
    $root = realpath(harnessTempDir()) ?: '';
    $elsewhere = realpath(harnessTempDir()) ?: '';
    mkdir($elsewhere.'/src', 0o755, true);
    mkdir($root.'/src', 0o755, true);
    mkdir($root.'/vendor/acme', 0o755, true);
    mkdir($root.'/vendor/composer', 0o755, true);
    symlink($elsewhere, $root.'/vendor/acme/linked');
    file_put_contents($root.'/vendor/composer/autoload_psr4.php', "<?php\n\n\$vendorDir = dirname(__DIR__);\n\nreturn [\n    'Linked\\\\' => [\$vendorDir . '/acme/linked/src'],\n    'Outside\\\\' => ['".$elsewhere."/src'],\n    'Mine\\\\' => [dirname(\$vendorDir) . '/src'],\n    'Dotted\\\\' => [\$vendorDir . '/composer/../acme/linked/src'],\n];\n");

    expect(ForeignNamespaces::discover($root)->prefixes)->toBe(['Dotted', 'Linked', 'Outside']);
});

it('has no foreign namespace without a generated autoload map or with a map that is not an array', function (): void {
    $root = harnessTempDir();
    $empty = ForeignNamespaces::discover($root);
    mkdir($root.'/vendor/composer', 0o755, true);
    file_put_contents($root.'/vendor/composer/autoload_psr4.php', "<?php\n\nreturn 'nothing';\n");

    expect($empty->prefixes)->toBe([])
        ->and($empty->overlaps('Any\\Thing'))->toBeFalse()
        ->and(ForeignNamespaces::discover($root)->prefixes)->toBe([])
        ->and(ForeignNamespaces::fromMap([0 => ['/x'], 'A\\' => [5]], $root)->prefixes)->toBe([]);
});
