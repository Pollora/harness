<?php

declare(strict_types=1);

use Pollora\Harness\Architecture\FacadeClasses;
use Pollora\Harness\Architecture\FinalClasses;
use Pollora\Harness\Architecture\Layers;
use Pollora\Harness\Architecture\TestClasses;

/**
 * A repository (namespace Acme\Conv) whose files are given by relative path.
 *
 * @param  array<string, string>  $files  Path under the project => PHP source (the opening tag is added)
 */
function conventionProject(array $files): string
{
    $root = harnessTempDir();

    foreach ($files as $path => $source) {
        is_dir(dirname("{$root}/{$path}")) || mkdir(dirname("{$root}/{$path}"), 0o755, true);
        file_put_contents("{$root}/{$path}", "<?php\n\ndeclare(strict_types=1);\n\n{$source}\n");
    }

    file_put_contents($root.'/composer.json', json_encode([
        'autoload' => ['psr-4' => ['Acme\\Conv\\' => 'src/']],
        'autoload-dev' => ['psr-4' => ['Acme\\Conv\\Tests\\' => 'tests/']],
    ], JSON_UNESCAPED_SLASHES));

    return $root;
}

it('asks for final on a concrete class and on nothing else (CONV-08)', function (): void {
    $root = conventionProject([
        'src/Open.php' => "namespace Acme\\Conv;\n\nclass Open {}",
        'src/Closed.php' => "namespace Acme\\Conv;\n\nfinal class Closed {}",
        'src/Base.php' => "namespace Acme\\Conv;\n\nabstract class Base {}",
        'src/QueryBuilder.php' => "namespace Acme\\Conv;\n\nclass QueryBuilder {}",
        'src/Service.php' => "namespace Acme\\Conv;\n\nuse Illuminate\\Support\\Traits\\Macroable;\n\nclass Service\n{\n    use Macroable;\n}",
        'src/Parent.php' => "namespace Acme\\Conv;\n\nclass Mother {}",
        'src/Child.php' => "namespace Acme\\Conv;\n\nfinal class Child extends Mother {}",
        'src/Contract.php' => "namespace Acme\\Conv;\n\ninterface Contract {}",
        'src/Mode.php' => "namespace Acme\\Conv;\n\nenum Mode {}",
        'src/Helps.php' => "namespace Acme\\Conv;\n\ntrait Helps {}",
    ]);

    expect(FinalClasses::problems(Layers::discover($root)))->toBe([
        'Acme\\Conv\\Open is not final: make it final, or make it an extension point (abstract, a builder, or a service that uses Macroable).',
    ]);
});

it('accepts a facade that only names its service and reports the others (CONV-09)', function (): void {
    $root = conventionProject([
        'src/Contracts/Mailer.php' => "namespace Acme\\Conv\\Contracts;\n\ninterface Mailer {}",
        'src/Concrete.php' => "namespace Acme\\Conv;\n\nfinal class Concrete {}",
        'src/Facades/Mail.php' => "namespace Acme\\Conv\\Facades;\n\nuse Acme\\Conv\\Contracts\\Mailer;\nuse Illuminate\\Support\\Facades\\Facade;\n\nfinal class Mail extends Facade\n{\n    protected static function getFacadeAccessor(): string\n    {\n        return Mailer::class;\n    }\n}",
        'src/Facades/Named.php' => "namespace Acme\\Conv\\Facades;\n\nuse Illuminate\\Support\\Facades\\Facade;\n\nfinal class Named extends Facade\n{\n    protected static function getFacadeAccessor(): string\n    {\n        return 'named';\n    }\n}",
        'src/Facades/Fat.php' => "namespace Acme\\Conv\\Facades;\n\nuse Acme\\Conv\\Concrete;\nuse Illuminate\\Support\\Facades\\Facade;\n\nfinal class Fat extends Facade\n{\n    public static function forget(): void {}\n\n    protected static function getFacadeAccessor(): string\n    {\n        return Concrete::class;\n    }\n}",
        'src/Facades/Clever.php' => "namespace Acme\\Conv\\Facades;\n\nuse Illuminate\\Support\\Facades\\Facade;\n\nfinal class Clever extends Facade\n{\n    protected static function getFacadeAccessor(): string\n    {\n        return config('x') ? 'a' : 'b';\n    }\n}",
    ]);

    expect(FacadeClasses::problems(Layers::discover($root)))->toBe([
        'Acme\\Conv\\Facades\\Clever::getFacadeAccessor() does more than return what the container resolves.',
        'Acme\\Conv\\Facades\\Fat resolves Acme\\Conv\\Concrete, a concrete class: a facade resolves a contract (an interface).',
        'Acme\\Conv\\Facades\\Fat::forget() is logic of its own: a facade only names the service it fronts.',
    ]);
});

it('finds the PHPUnit test classes and leaves Pest files, separate-process tests, fixtures and test doubles alone (CONV-12)', function (): void {
    $root = conventionProject([
        'tests/OldTest.php' => "namespace Acme\\Conv\\Tests;\n\nuse PHPUnit\\Framework\\TestCase;\n\nfinal class OldTest extends TestCase\n{\n    public function testWorks(): void {}\n}",
        'tests/Nested/AttributeTest.php' => "namespace Acme\\Conv\\Tests\\Nested;\n\nuse PHPUnit\\Framework\\Attributes\\Test;\nuse Acme\\Conv\\Tests\\BaseTestCase;\n\nfinal class AttributeTest extends BaseTestCase\n{\n    #[Test]\n    public function it_works(): void {}\n}",
        'tests/Annotated.php' => "namespace Acme\\Conv\\Tests;\n\nfinal class AnnotatedTest extends \\PHPUnit\\Framework\\TestCase\n{\n    /** @test */\n    public function works(): void {}\n}",
        'tests/Isolated.php' => "namespace Acme\\Conv\\Tests;\n\nuse PHPUnit\\Framework\\Attributes\\RunInSeparateProcess;\n\nfinal class IsolatedTest extends \\PHPUnit\\Framework\\TestCase\n{\n    #[RunInSeparateProcess]\n    public function testWorks(): void {}\n}",
        'tests/IsolatedClass.php' => "namespace Acme\\Conv\\Tests;\n\n/** @runTestsInSeparateProcesses */\nfinal class IsolatedClassTest extends \\PHPUnit\\Framework\\TestCase\n{\n    public function testWorks(): void {}\n}",
        'tests/BaseTestCase.php' => "namespace Acme\\Conv\\Tests;\n\nabstract class BaseTestCase extends \\PHPUnit\\Framework\\TestCase\n{\n    public function testNothing(): void {}\n}",
        'tests/Fixtures/Bad.php' => "namespace Acme\\Conv\\Tests\\Fixtures;\n\nfinal class BadTest extends \\PHPUnit\\Framework\\TestCase\n{\n    public function testWorks(): void {}\n}",
        'tests/Rule.php' => "namespace Acme\\Conv\\Tests;\n\nfinal class RuleTest extends \\PHPStan\\Testing\\RuleTestCase\n{\n    public function testRule(): void {}\n}",
        'tests/Double.php' => "namespace Acme\\Conv\\Tests;\n\nfinal class Double extends \\Acme\\Conv\\Hook\n{\n    public function testHook(): void {}\n}",
        'tests/Helper.php' => "namespace Acme\\Conv\\Tests;\n\nfinal class Helper extends \\PHPUnit\\Framework\\TestCase\n{\n    public function setUp(): void {}\n}",
        'tests/Pest.php' => "it('works', function (): void { expect(true)->toBeTrue(); });",
    ]);

    expect(TestClasses::problems($root))->toBe([
        'Acme\\Conv\\Tests\\AnnotatedTest is a PHPUnit test class (PHPUnit\\Framework\\TestCase): write its tests as it()/test() functions with datasets.',
        'Acme\\Conv\\Tests\\Nested\\AttributeTest is a PHPUnit test class (Acme\\Conv\\Tests\\BaseTestCase): write its tests as it()/test() functions with datasets.',
        'Acme\\Conv\\Tests\\OldTest is a PHPUnit test class (PHPUnit\\Framework\\TestCase): write its tests as it()/test() functions with datasets.',
    ]);
});

it('finds nothing to report in a repository without tests directory or composer.json', function (): void {
    $root = harnessTempDir();

    expect(TestClasses::problems($root))->toBe([])
        ->and(TestClasses::files($root))->toBe([]);
});

/**
 * Runs `pest tests/Arch.php` on a project of its own, in a process of its own: the project is under /tmp
 * because the namespace Pest generates from the path of a test file cannot have a segment that starts with a digit.
 *
 * @return array{int, string} Exit code and output
 */
function runArchitectureOf(?string $source): array
{
    $root = '/tmp/pollora-harness-arch-'.bin2hex(random_bytes(5));
    mkdir($root.'/src', 0o755, true);
    mkdir($root.'/tests', 0o755, true);
    $autoload = dirname(__DIR__, 2).'/vendor/autoload.php';

    if ($source !== null) {
        file_put_contents($root.'/src/Thing.php', $source);
    }

    file_put_contents($root.'/composer.json', '{"autoload": {"psr-4": {"Acme\\\\Obs\\\\": "src/"}}}');
    file_put_contents($root.'/bootstrap.php', "<?php\n\n\$loader = require '{$autoload}';\n\$loader->addPsr4('Acme\\\\Obs\\\\', __DIR__.'/src');\n");
    file_put_contents($root.'/phpunit.xml', '<?xml version="1.0"?><phpunit bootstrap="bootstrap.php"><testsuites><testsuite name="A"><file>tests/Arch.php</file></testsuite></testsuites></phpunit>');
    file_put_contents($root.'/tests/Arch.php', "<?php\n\nPollora\\Harness\\Pest\\Architecture::for(dirname(__DIR__), Pollora\\Harness\\Profile::Core, [])->register();\n");

    $process = proc_open([PHP_BINARY, '-d', 'memory_limit=2G', dirname(__DIR__, 2).'/vendor/bin/pest', 'tests/Arch.php', '--filter=CONV-(08|09|12)', '--colors=never'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
    $output = (string) stream_get_contents($pipes[1]).(string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), $output];
}

it('reports an observed rule without failing the run, and passes a clean repository (observed mechanism)', function (): void {
    [$code, $output] = runArchitectureOf("<?php\n\ndeclare(strict_types=1);\n\nnamespace Acme\\Obs;\n\nclass Thing {}\n");
    [$cleanCode, $cleanOutput] = runArchitectureOf("<?php\n\ndeclare(strict_types=1);\n\nnamespace Acme\\Obs;\n\nfinal class Thing {}\n");

    expect($code)->toBe(0)
        ->and($output)->toContain('observed, not blocking: CONV-08')
        ->and($output)->toContain('Acme\\Obs\\Thing is not final')
        ->and($output)->toContain('1 incomplete')
        ->and($cleanCode)->toBe(0)
        ->and($cleanOutput)->not->toContain('incomplete')
        ->and($cleanOutput)->toContain('3 passed');
});

it('shows a rule with nothing to read as not evaluated, never as a pass', function (): void {
    [$code, $output] = runArchitectureOf(null);

    expect($code)->toBe(0)
        ->and($output)->toContain('not evaluated: CONV-08 — no class under the PSR-4 roots of composer.json')
        ->and($output)->toContain('not evaluated: CONV-09 — no class under the PSR-4 roots of composer.json')
        ->and($output)->not->toContain('✓ CONV-08');
});
