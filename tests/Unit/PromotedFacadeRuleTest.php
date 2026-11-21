<?php

declare(strict_types=1);
use Pollora\Harness\Rules\Catalogue;

/*
 * CONV-09 is enforced: the same `tests/Arch.php` that reported a fat facade as incomplete now fails the run.
 * These tests run a real `pest tests/Arch.php` on a small repository, with the catalogue as it ships.
 */

if (! function_exists('facadeArchRun')) {
    /**
     * Runs the CONV-09 test of a repository (namespace Acme\Fac) by a real Pest run, in a process of its own.
     *
     * The project is under /tmp because the namespace Pest generates from the path of a test file cannot have a
     * segment that starts with a digit (macOS temporary directories have one).
     *
     * @param  array<string, string>  $files  Path under the project => PHP source (the opening tag is added)
     * @param  list<array<string, mixed>>  $allowRules  The `allow_rules` of the repository's .harness.json
     * @return array{int, string} Exit code and output
     */
    function facadeArchRun(array $files, array $allowRules = []): array
    {
        $root = '/tmp/pollora-harness-facade-'.bin2hex(random_bytes(5));
        mkdir($root.'/tests', 0o755, true);

        foreach ($files as $path => $source) {
            is_dir(dirname("{$root}/{$path}")) || mkdir(dirname("{$root}/{$path}"), 0o755, true);
            file_put_contents("{$root}/{$path}", "<?php\n\ndeclare(strict_types=1);\n\n{$source}\n");
        }

        $autoload = dirname(__DIR__, 2).'/vendor/autoload.php';
        file_put_contents($root.'/composer.json', '{"autoload": {"psr-4": {"Acme\\\\Fac\\\\": "src/"}}}');
        file_put_contents($root.'/.harness.json', json_encode(['profile' => 'core', 'allow_rules' => $allowRules]));
        file_put_contents($root.'/bootstrap.php', "<?php\n\n\$loader = require '{$autoload}';\n\$loader->addPsr4('Acme\\\\Fac\\\\', __DIR__.'/src');\n");
        file_put_contents($root.'/phpunit.xml', '<?xml version="1.0"?><phpunit bootstrap="bootstrap.php"><testsuites><testsuite name="A"><file>tests/Arch.php</file></testsuite></testsuites></phpunit>');
        file_put_contents($root.'/tests/Arch.php', "<?php\n\nPollora\\Harness\\Pest\\Architecture::for(dirname(__DIR__))->register();\n");

        $process = proc_open([PHP_BINARY, '-d', 'memory_limit=2G', dirname(__DIR__, 2).'/vendor/bin/pest', 'tests/Arch.php', '--filter=CONV-09', '--colors=never'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, [...getenv(), 'COLUMNS' => '200']);
        $output = (string) stream_get_contents($pipes[1]).(string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $output];
    }
}

if (! function_exists('facadeFiles')) {
    /**
     * A contract, a thin facade over it, and the facades given by name (each resolves a concrete class and forgets something).
     *
     * @param  list<string>  $fat
     * @return array<string, string>
     */
    function facadeFiles(array $fat = []): array
    {
        $files = [
            'src/Contracts/Mailer.php' => "namespace Acme\\Fac\\Contracts;\n\ninterface Mailer {}",
            'src/Concrete.php' => "namespace Acme\\Fac;\n\nfinal class Concrete {}",
            'src/Facades/Mail.php' => "namespace Acme\\Fac\\Facades;\n\nuse Acme\\Fac\\Contracts\\Mailer;\nuse Illuminate\\Support\\Facades\\Facade;\n\nfinal class Mail extends Facade\n{\n    protected static function getFacadeAccessor(): string\n    {\n        return Mailer::class;\n    }\n}",
        ];

        foreach ($fat as $name) {
            $files["src/Facades/{$name}.php"] = "namespace Acme\\Fac\\Facades;\n\nuse Acme\\Fac\\Concrete;\nuse Illuminate\\Support\\Facades\\Facade;\n\nfinal class {$name} extends Facade\n{\n    public static function forget(): void {}\n\n    protected static function getFacadeAccessor(): string\n    {\n        return Concrete::class;\n    }\n}";
        }

        return $files;
    }
}

it('fails the run on a facade with logic of its own and on a facade over a concrete class (CONV-09 is enforced)', function (): void {
    [$code, $output] = facadeArchRun(facadeFiles(['Fat']));

    expect($code)->not->toBe(0)
        ->and($output)->toContain('Acme\\Fac\\Facades\\Fat::forget() is logic of its own')
        ->and($output)->toContain('Acme\\Fac\\Facades\\Fat resolves Acme\\Fac\\Concrete, a concrete class')
        ->and($output)->not->toContain('observed, not blocking: CONV-09')
        ->and($output)->not->toContain('1 incomplete');
});

it('passes a repository whose facades are thin fronts for a contract', function (): void {
    [$code, $output] = facadeArchRun(facadeFiles());

    expect($code)->toBe(0)->and($output)->toContain('1 passed')->and($output)->not->toContain('incomplete');
});

it('exempts the classes an allow_rules entry names and still fails on the others', function (): void {
    $files = facadeFiles(['Fat', 'Fatter']);

    [$code, $output] = facadeArchRun($files, [['rule' => 'CONV-09', 'reason' => 'legacy facade, replaced in the next major', 'classes' => ['Acme\\Fac\\Facades\\Fat']]]);

    expect($code)->not->toBe(0)
        ->and($output)->toContain('Acme\\Fac\\Facades\\Fatter resolves')
        ->and($output)->not->toContain('Acme\\Fac\\Facades\\Fat resolves')
        ->and($output)->toContain('exempted: CONV-09 — legacy facade, replaced in the next major — 1 class: Acme\\Fac\\Facades\\Fat');

    [$all, $allOutput] = facadeArchRun($files, [['rule' => 'CONV-09', 'reason' => 'legacy facades', 'classes' => ['Acme\\Fac\\Facades\\*']]]);

    expect($all)->toBe(0)->and($allOutput)->toContain('exempted: CONV-09 — legacy facades — 2 classes');
});

it('skips the rule with its reason when the whole rule is exempted', function (): void {
    [$code, $output] = facadeArchRun(facadeFiles(['Fat']), [['rule' => 'CONV-09', 'reason' => 'no facade will be fixed']]);

    expect($code)->toBe(0)->and($output)->toContain('no facade will be fixed');
});

it('keeps CONV-02, CONV-08 and CONV-12 observed: they report and never block', function (): void {
    expect(array_map(static fn (string $rule): string => Catalogue::all()[$rule]['status'], ['CONV-02', 'CONV-08', 'CONV-11', 'CONV-12', 'CONV-09']))
        ->toBe(['observed', 'observed', 'observed', 'observed', 'enforced']);
});
