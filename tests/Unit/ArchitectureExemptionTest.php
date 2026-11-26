<?php

declare(strict_types=1);

/**
 * A repository with one ARCH-08 problem (an open attribute class) and one
 * ARCH-09 problem (a file without strict types), checked by a real Pest run.
 *
 * @param  list<array{rule: string, reason: string}>  $allowRules
 * @param  string|null  $root  An empty directory for the project; a fresh temporary one when null
 * @return array{int, string}
 */
function archRun(array $allowRules, ?string $root = null): array
{
    $root ??= harnessTempDir();
    mkdir($root.'/src');
    mkdir($root.'/tests');
    mkdir($root.'/vendor');
    file_put_contents($root.'/vendor/autoload.php', "<?php\n\n\$loader = require '".dirname(__DIR__, 2)."/vendor/autoload.php';\n\$loader->addPsr4('Acme\\\\Shared\\\\', __DIR__.'/../src');\n\nreturn \$loader;\n");
    // a cacheDirectory keeps Pest from looking for its own .temp folder, which a fresh install may not have
    file_put_contents($root.'/phpunit.xml', '<phpunit cacheDirectory=".phpunit.cache"><testsuites><testsuite name="Arch"><file>tests/Arch.php</file></testsuite></testsuites></phpunit>');
    file_put_contents($root.'/composer.json', '{"autoload": {"psr-4": {"Acme\\\\Shared\\\\": "src/"}}}');
    file_put_contents($root.'/.harness.json', json_encode(['profile' => 'core', 'allow_rules' => $allowRules]));
    file_put_contents($root.'/src/Open.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Acme\\Shared;\n\nuse Attribute;\n\n#[Attribute]\nclass Open {}\n");
    file_put_contents($root.'/src/Loose.php', "<?php\n\nnamespace Acme\\Shared;\n\nfinal class Loose {}\n");
    file_put_contents($root.'/tests/Arch.php', "<?php\n\ndeclare(strict_types=1);\n\nPollora\\Harness\\Pest\\Architecture::for(dirname(__DIR__))->register();\n");

    // Pest takes its root from where its binary is. Run from the harness's vendor, this project would be outside that
    // root and Pest would turn its absolute path into a namespace: a segment starting with a digit (the default TMPDIR
    // of macOS has one) is a parse error. A copy of the binary makes the project its own root, as an installed one is.
    mkdir($root.'/vendor/pestphp/pest/bin', 0o755, true);
    copy(dirname(__DIR__, 2).'/vendor/pestphp/pest/bin/pest', $root.'/vendor/pestphp/pest/bin/pest');

    $command = [PHP_BINARY, $root.'/vendor/pestphp/pest/bin/pest', 'tests/Arch.php', '--colors=never', '--filter=ARCH-'];
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, [...getenv(), 'COLUMNS' => '200']);
    $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), (string) $output];
}

it('runs every rule when nothing is exempted', function (): void {
    [$code, $output] = archRun([]);

    expect($code)->not->toBe(0)
        ->and($output)->toContain('ARCH-08')
        ->and($output)->toContain('Acme\\Shared\\Loose')
        ->and($output)->not->toContain('exempted');
});

it('skips the exempted rule with its reason and still runs the others', function (): void {
    [$code, $output] = archRun([['rule' => 'ARCH-08', 'reason' => 'published classes cannot become final']]);

    expect($output)->toContain('exempted: ARCH-08 — published classes cannot become final')
        ->and($output)->toContain('1 skipped')
        ->and($output)->not->toContain('Acme\\Shared\\Open is not final')
        ->and($output)->toContain('Acme\\Shared\\Loose')
        ->and($code)->not->toBe(0);
});

it('passes once every failing rule is exempted, and says so', function (): void {
    [$code, $output] = archRun([
        ['rule' => 'ARCH-08', 'reason' => 'published classes cannot become final'],
        ['rule' => 'ARCH-09', 'reason' => 'generated file'],
    ]);

    expect($code)->toBe(0)
        ->and($output)->toContain('exempted: ARCH-08 — published classes cannot become final')
        ->and($output)->toContain('exempted: ARCH-09 — generated file');
});

it('runs from a directory whose path has a segment starting with a digit', function (): void {
    $root = harnessTempDir().'/7f';
    mkdir($root);

    [$code, $output] = archRun([], $root);

    expect(str_contains($output, 'Unable to create test case'))->toBeFalse($output)
        ->and($code)->not->toBe(0)
        ->and($output)->toContain('Acme\\Shared\\Loose');
});
