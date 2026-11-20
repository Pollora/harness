<?php

declare(strict_types=1);

use Pest\Arch\Repositories\ObjectsRepository;
use Pest\Arch\Support\Composer;
use Pest\TestSuite;
use Pollora\Harness\Architecture\ForeignNamespaces;
use Pollora\Harness\Pest\Architecture;
use Pollora\Harness\Profile;

/**
 * A hexagonal project (Cov\Foreign: Domain with Exceptions, Application) with a package installed under
 * vendor/ whose PSR-4 prefix is Cov\Foreign\Domain, as pollora/media 0.9 is for the framework.
 */
function foreignHexagon(string $base = 'Cov\\Foreign'): string
{
    $root = harnessTempDir();
    $escaped = str_replace('\\', '\\\\', $base);

    foreach (['Domain/Exceptions' => 'Domain\\Exceptions', 'Application' => 'Application'] as $directory => $namespace) {
        mkdir("{$root}/src/{$directory}", 0o755, true);
        file_put_contents("{$root}/src/{$directory}/Thing.php", "<?php\n\ndeclare(strict_types=1);\n\nnamespace {$base}\\{$namespace};\n\nfinal class Thing {}\n");
    }

    mkdir($root.'/vendor/composer', 0o755, true);
    mkdir($root.'/vendor/acme/pkg/src/Domain/Exceptions', 0o755, true);
    file_put_contents($root.'/composer.json', '{"autoload": {"psr-4": {"'.$escaped.'\\\\": "src/"}}}');
    file_put_contents($root.'/vendor/composer/autoload_psr4.php', "<?php\n\n\$vendorDir = dirname(__DIR__);\n\$baseDir = dirname(\$vendorDir);\n\nreturn [\n    '{$escaped}\\\\Domain\\\\' => [\$vendorDir . '/acme/pkg/src/Domain'],\n    '{$escaped}\\\\' => [\$baseDir . '/src'],\n];\n");

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
it('registers the rules of a namespace an installed package maps as tests on the repository classes, none as skipped', function (): void {
    $architecture = Architecture::for(foreignHexagon(), Profile::Core, []);

    foreach (['hexagon', 'naming', 'configuration'] as $method) {
        (new ReflectionMethod($architecture, $method))->invoke($architecture);
    }

    $shared = foreignRegistered('Cov\\Foreign\\Domain');
    $run = foreignRegistered('Cov\\Foreign\\Application');

    expect($shared)->toHaveCount(3)
        ->and(array_filter($shared, static fn (?string $reason): bool => $reason !== null))->toBe([])
        ->and(array_keys($shared))->toContain('CONV-03 Cov\\Foreign\\Domain\\Exceptions names its classes ...Exception')
        ->and($run)->toHaveCount(1)
        ->and(array_filter($run, static fn (?string $reason): bool => $reason !== null))->toBe([])
        ->and(foreignRegistered('CONV-05 Cov\\Foreign reads configuration'))->toHaveCount(1);
})->skip(
    version_compare(\Pest\version(), '4.0.0', '<'),
    'Registering tests while a test runs is refused by Pest 3 (it asserts on the call trace); Pest 4 accepts it.'
);

/**
 * The same hexagon where the package has, under the same name, the class of the repository (shadowed) next to one
 * the repository does not have: the shadowed class is left out with a skipped test that names it.
 */
it('leaves out, with a skipped test that names it, a class the package has under the same name', function (): void {
    $root = foreignHexagon('Cov\\ForeignShadow');
    file_put_contents($root.'/vendor/acme/pkg/src/Domain/Exceptions/Thing.php', "<?php\n\nnamespace Cov\\ForeignShadow\\Domain\\Exceptions;\n\nfinal class Thing {}\n");
    file_put_contents($root.'/src/Domain/Exceptions/Mine.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Cov\\ForeignShadow\\Domain\\Exceptions;\n\nfinal class Mine {}\n");
    $architecture = Architecture::for($root, Profile::Core, []);
    (new ReflectionMethod($architecture, 'naming'))->invoke($architecture);

    $registered = foreignRegistered('CONV-03 Cov\\ForeignShadow\\Domain\\Exceptions');
    $shadow = array_filter($registered, static fn (?string $reason): bool => $reason !== null);

    expect($registered)->toHaveCount(2)
        ->and($shadow)->toHaveCount(1)
        ->and(array_values($shadow)[0])->toContain('not evaluated: CONV-03')
        ->and(array_values($shadow)[0])->toContain('Cov\\ForeignShadow\\Domain\\Exceptions\\Thing')
        ->and(array_values($shadow)[0])->not->toContain('Mine');
})->skip(
    version_compare(\Pest\version(), '4.0.0', '<'),
    'Registering tests while a test runs is refused by Pest 3 (it asserts on the call trace); Pest 4 accepts it.'
);

it('skips a rule, with the reason, when every class of the repository in the shared namespace is shadowed', function (): void {
    $root = foreignHexagon('Cov\\ForeignAll');
    file_put_contents($root.'/vendor/acme/pkg/src/Domain/Exceptions/Thing.php', "<?php\n\nnamespace Cov\\ForeignAll\\Domain\\Exceptions;\n\nfinal class Thing {}\n");
    $architecture = Architecture::for($root, Profile::Core, []);
    (new ReflectionMethod($architecture, 'configuration'))->invoke($architecture);
    (new ReflectionMethod($architecture, 'naming'))->invoke($architecture);

    $registered = foreignRegistered('CONV-03 Cov\\ForeignAll\\Domain\\Exceptions');
    $reasons = array_values(array_filter($registered, static fn (?string $reason): bool => $reason !== null));

    expect($registered)->toHaveCount(2)
        ->and($reasons)->toHaveCount(2)
        ->and(implode("\n", $reasons))->toContain('every class of the repository in it is shared with the package');
})->skip(
    version_compare(\Pest\version(), '4.0.0', '<'),
    'Registering tests while a test runs is refused by Pest 3 (it asserts on the call trace); Pest 4 accepts it.'
);

it('tells a class the package also has under the same name, and only that one', function (): void {
    $foreign = foreignProject(['Pollora\\Media\\' => 'vendor/acme/pkg/src', 'Pollora\\' => 'src']);
    $root = dirname(array_values($foreign->directories)[0][0], 4);
    mkdir($root.'/vendor/acme/pkg/src/Domain/Exceptions', 0o755, true);
    file_put_contents($root.'/vendor/acme/pkg/src/Domain/Exceptions/Same.php', "<?php\n");

    expect($foreign->directories)->toHaveKey('Pollora\\Media')
        ->and($foreign->shadows('Pollora\\Media\\Domain\\Exceptions\\Same'))->toBeTrue()
        ->and($foreign->shadows('Pollora\\Media\\Domain\\Exceptions'))->toBeTrue()
        ->and($foreign->shadows('Pollora\\Media\\Domain\\Exceptions\\Mine'))->toBeFalse()
        ->and($foreign->shadows('Pollora\\Hook\\Domain\\Same'))->toBeFalse();
});

/**
 * What the engine of Pest does with a namespace a package also maps, and with the list of the repository's classes.
 * The prefixes are added to the class loader the engine reads, with names no other test uses.
 */
it('judges only the repository classes when it is given their names, where the namespace brings in the package (the 0.1.32 crash)', function (): void {
    $root = harnessTempDir();
    mkdir($root.'/src/Domain/Exceptions', 0o755, true);
    mkdir($root.'/vendor/pkg/Exceptions', 0o755, true);
    file_put_contents($root.'/src/Domain/Exceptions/MineException.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Cov\\Listed\\Domain\\Exceptions;\n\nfinal class MineException extends \\Exception {}\n");
    file_put_contents($root.'/vendor/pkg/Exceptions/TheirError.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Cov\\Listed\\Domain\\Exceptions;\n\nfinal class TheirError extends \\Exception {}\n");
    $loader = Composer::loader();
    $loader->addPsr4('Cov\\Listed\\', [$root.'/src']);
    $loader->addPsr4('Cov\\Listed\\Domain\\', [$root.'/vendor/pkg']);
    // the engine keeps the prefixes of the class loader from its first use: make it read the two just added
    $instance = new ReflectionProperty(ObjectsRepository::class, 'instance');
    $instance->setValue(null, null);
    $namespace = 'Cov\\Listed\\Domain\\Exceptions';
    $classes = ['Cov\\Listed\\Domain\\Exceptions\\MineException'];

    $byNamespace = fn () => expect($namespace)->toHaveSuffix('Exception');
    $byList = fn () => expect($classes)->toHaveSuffix('Exception');

    expect($byNamespace)->toThrow(Error::class, 'ObjectDescriptionBase::$path must not be accessed before initialization')
        ->and($byList)->not->toThrow(Throwable::class);
    expect(fn () => expect($classes)->not->toUse(['app', 'resolve', 'env']))->not->toThrow(Throwable::class);

    $instance->setValue(null, null);
});

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

/**
 * The case of the framework with pollora/media 0.9, run for real: a project whose Domain\Exceptions namespace
 * is also mapped by a package under vendor/ (a class of its own, a class of the package's), judged by Pest in a
 * process of its own. Pest takes the project root from the place of its autoloader, so the project has a
 * vendor/autoload.php of its own (which loads the harness's and adds the two prefixes, as Composer's would) and
 * a copy of Pest's launcher that looks there. It sits under /tmp: the namespace Pest builds from the path of a
 * test file cannot have a segment that starts with a digit.
 */
it('runs the naming and layer rules of a namespace shared with an installed package, on the repository classes only', function (): void {
    $root = '/tmp/pollora-harness-shared-'.bin2hex(random_bytes(5));
    mkdir($root.'/src/Domain/Exceptions', 0o755, true);
    mkdir($root.'/vendor/composer', 0o755, true);
    mkdir($root.'/vendor/acme/pkg/Exceptions', 0o755, true);
    mkdir($root.'/tests', 0o755, true);
    $harness = dirname(__DIR__, 2);
    $head = "<?php\n\ndeclare(strict_types=1);\n\nnamespace Acme\\Shared\\Domain\\Exceptions;\n\n";

    file_put_contents($root.'/src/Domain/Exceptions/MineException.php', $head."final class MineException extends \\Exception {}\n");
    file_put_contents($root.'/vendor/acme/pkg/Exceptions/TheirError.php', $head."final class TheirError extends \\Exception {}\n");
    file_put_contents($root.'/composer.json', '{"autoload": {"psr-4": {"Acme\\\\Shared\\\\": "src/"}}}');
    file_put_contents($root.'/vendor/composer/autoload_psr4.php', "<?php\n\nreturn [\n    'Acme\\\\Shared\\\\Domain\\\\' => ['{$root}/vendor/acme/pkg'],\n    'Acme\\\\Shared\\\\' => ['{$root}/src'],\n];\n");
    // Pest reads the second to last line of vendor/autoload.php to get the class loader
    file_put_contents($root.'/vendor/autoload.php', "<?php\n\n\$loader = require '{$harness}/vendor/autoload.php';\n\$loader->addPsr4('Acme\\\\Shared\\\\', dirname(__DIR__).'/src');\n\$loader->addPsr4('Acme\\\\Shared\\\\Domain\\\\', __DIR__.'/acme/pkg');\n\nreturn array_values(\\Composer\\Autoload\\ClassLoader::getRegisteredLoaders())[0];\n");
    file_put_contents($root.'/pest-bin.php', str_replace("dirname(__DIR__, 4).'/vendor/autoload.php'", "'{$root}/vendor/autoload.php'", (string) file_get_contents($harness.'/vendor/pestphp/pest/bin/pest')));
    file_put_contents($root.'/phpunit.xml', '<?xml version="1.0"?><phpunit><testsuites><testsuite name="A"><directory>tests</directory></testsuite></testsuites></phpunit>');
    file_put_contents($root.'/tests/Arch.php', "<?php\n\nPollora\\Harness\\Pest\\Architecture::for(dirname(__DIR__), Pollora\\Harness\\Profile::Core, [])->register();\n");

    $process = proc_open([PHP_BINARY, '-d', 'memory_limit=2G', 'pest-bin.php', 'tests/Arch.php', '--colors=never'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
    $output = (string) stream_get_contents($pipes[1]).(string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);

    expect($output)->toContain('✓ CONV-03 Acme\\Shared\\Domain\\Exceptions names its classes ...Exception')
        ->and($output)->toContain('✓ ARCH-01 Acme\\Shared\\Domain stays independent')
        ->and($output)->toContain('✓ CONV-07 Acme\\Shared\\Domain receives its dependencies')
        ->and($output)->not->toContain('not evaluated')
        ->and($output)->not->toContain('ObjectDescriptionBase')
        ->and($code)->toBe(0);
});
