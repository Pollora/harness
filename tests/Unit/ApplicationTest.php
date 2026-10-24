<?php

declare(strict_types=1);

/**
 * @return array{int, string}
 */
function polloraQa(string $cwd, string ...$arguments): array
{
    $command = array_merge([PHP_BINARY, dirname(__DIR__, 2).'/bin/pollora-qa'], $arguments, ['--no-ansi']);
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
    $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), (string) $output];
}

it('lists its commands', function (): void {
    [$code, $output] = polloraQa(harnessTempDir(), 'help');

    expect($code)->toBe(0)
        ->and($output)->toContain('init', 'check', 'ratchet', 'deps', 'rules', 'agents', 'hook');
});

it('explains a rule by its identifier', function (): void {
    [$code, $output] = polloraQa(harnessTempDir(), 'rules', 'ARCH-02');

    expect($code)->toBe(0)
        ->and($output)->toContain('Domain code calls no global WordPress function')
        ->not->toContain('ARCH-01');
});

it('asks for a profile instead of guessing one', function (): void {
    [$code, $output] = polloraQa(harnessTempDir(), 'init');

    expect($code)->toBe(1)
        ->and($output)->toContain('--profile=core|framework|integration|wp-plugin|template|project');
});

it('says how to start when .harness.json is missing', function (): void {
    [$code, $output] = polloraQa(harnessTempDir(), 'check');

    expect($code)->toBe(1)
        ->and($output)->toContain('pollora-qa init --profile=<profile>');
});

it('reports a ratchet regression and fails', function (): void {
    $cwd = harnessTempDir();
    file_put_contents($cwd.'/.harness.json', '{"profile": "core", "ratchet": {"phpstan_level": 8}}');
    file_put_contents($cwd.'/phpstan.neon', "parameters:\n    level: 6\n");

    [$code, $output] = polloraQa($cwd, 'ratchet');

    expect($code)->toBe(1)
        ->and($output)->toContain('regressed');
});

it('tightens the ratchet without ever loosening it', function (): void {
    $cwd = harnessTempDir();
    file_put_contents($cwd.'/.harness.json', '{"profile": "core", "ratchet": {"phpstan_level": 5}}');
    file_put_contents($cwd.'/phpstan.neon', "parameters:\n    level: 6\n");

    polloraQa($cwd, 'ratchet', '--update', '--metric=coverage:81.2');

    expect(json_decode((string) file_get_contents($cwd.'/.harness.json'), true)['ratchet'])->toBe([
        'phpstan_level' => 6,
        'phpstan_baseline' => 0,
        'coverage' => 81.2,
    ]);
});

it('records the public surface, then passes while it is unchanged and asks for a label once it changes', function (): void {
    $cwd = harnessTempDir();
    mkdir($cwd.'/src');
    file_put_contents($cwd.'/composer.json', '{"autoload": {"psr-4": {"Acme\\\\": "src/"}}}');
    file_put_contents($cwd.'/src/Events.php', "<?php\n\ndo_action('pollora/orders/placed');\n");

    [$missing] = polloraQa($cwd, 'surface');
    [$recorded] = polloraQa($cwd, 'surface', '--update');
    [$unchanged, $unchangedOutput] = polloraQa($cwd, 'surface');

    file_put_contents($cwd.'/src/Events.php', "<?php\n\ndo_action('pollora/orders/shipped');\n");
    [$broken, $brokenOutput] = polloraQa($cwd, 'surface');
    [$labelled] = polloraQa($cwd, 'surface', '--allow=all');

    expect($missing)->toBe(1)
        ->and($recorded)->toBe(0)
        ->and(json_decode((string) file_get_contents($cwd.'/api-surface.json'), true))->toBe(['version' => 1, 'surface' => ['hook:pollora/orders/placed' => 'action']])
        ->and($unchanged)->toBe(0)
        ->and($unchangedOutput)->toContain('unchanged (1 entries)')
        ->and($broken)->toBe(1)
        ->and($brokenOutput)->toContain('- hook:pollora/orders/placed', '+ hook:pollora/orders/shipped', 'label the pull request "breaking"')
        ->and($labelled)->toBe(0);
});
