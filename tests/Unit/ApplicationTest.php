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

it('keeps the observed gates and the protected paths when init runs again', function (): void {
    $cwd = harnessTempDir();
    file_put_contents($cwd.'/composer.json', '{"name": "pollora/example", "require": {"php": "^8.3"}}');
    file_put_contents($cwd.'/.harness.json', json_encode([
        'profile' => 'core',
        'mode' => 'enforce',
        'observed' => ['Rector'],
        'protected' => ['api-surface.json'],
    ]));

    [$code] = polloraQa($cwd, 'init');
    $config = json_decode((string) file_get_contents($cwd.'/.harness.json'), true);

    expect($code)->toBe(0)
        ->and($config['mode'])->toBe('enforce')
        ->and($config['observed'])->toBe(['Rector'])
        ->and($config['protected'])->toBe(['api-surface.json']);
});

it('reads the attribute classes of a plugin whose files stop on a missing ABSPATH', function (): void {
    [$code, $output] = polloraQa(dirname(__DIR__).'/Fixtures/Guarded', 'surface', '--print');

    expect($code)->toBe(0)
        ->and($output)->toContain('attribute:Pollora\\Harness\\Tests\\Fixtures\\Guarded\\Attributes\\GuardedAttribute');
});

it('fails, and says why, when a file the command loads ends the process', function (): void {
    [$code, $output] = polloraQa(dirname(__DIR__).'/Fixtures/Quitting', 'surface', '--print');

    expect($code)->toBe(1)
        ->and($output)->toContain('stopped before it finished', 'exit() or die()');
});

it('says the coverage report is missing instead of printing a bare "unmeasured"', function (): void {
    $cwd = harnessTempDir();
    file_put_contents($cwd.'/.harness.json', '{"profile": "core", "ratchet": {"coverage": 70}}');

    [$code, $output] = polloraQa($cwd, 'ratchet');

    expect($code)->toBe(0)
        ->and($output)->toContain('no report')
        ->and($output)->toContain('no Clover report found')
        ->and($output)->toContain('.harness/coverage.xml')
        ->and($output)->toContain('check --coverage')
        ->and($output)->not->toMatch('/Line coverage \(%\)\s+70\s+-\s+unmeasured/');
});

it('compares the report found under .harness/ with the ratchet', function (): void {
    $cwd = harnessTempDir();
    mkdir($cwd.'/.harness');
    copy(fixtureProject().'/coverage.xml', $cwd.'/.harness/coverage.xml');
    file_put_contents($cwd.'/.harness.json', '{"profile": "core", "ratchet": {"coverage": 90}}');

    [$code, $output] = polloraQa($cwd, 'ratchet');

    expect($code)->toBe(1)
        ->and($output)->toContain('regressed')
        ->and($output)->not->toContain('no report');
});

it('reports Semgrep missing from the PATH, and fails with --strict', function (): void {
    $cwd = harnessTempDir();
    mkdir($cwd.'/src');
    file_put_contents($cwd.'/.harness.json', '{"profile": "core", "mode": "enforce"}');

    $run = static function (string ...$flags) use ($cwd): array {
        $command = [PHP_BINARY, dirname(__DIR__, 2).'/bin/pollora-qa', 'check', ...$flags, '--no-ansi'];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, ['PATH' => '/nonexistent']);
        $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);

        return [proc_close($process), (string) $output];
    };

    [$code, $output] = $run();

    expect($output)->toContain('pipx install semgrep')
        ->and($output)->toContain('composer require --dev laravel/pint')
        ->and($output)->toContain('not every gate ran');

    [$strictCode, $strictOutput] = $run('--strict');

    expect($strictCode)->toBe(1)
        ->and($strictOutput)->toContain('Strict mode');
});

it('runs the fast style gate on the whole tree, not on --dirty, where git does not work', function (): void {
    $cwd = harnessTempDir();
    mkdir($cwd.'/vendor/bin', 0o755, true);
    file_put_contents($cwd.'/vendor/bin/pint', "#!/bin/sh\necho \"pint called with: \$*\"\nexit 0\n");
    chmod($cwd.'/vendor/bin/pint', 0o755);
    file_put_contents($cwd.'/.git', 'gitdir: /nonexistent/.git/worktrees/x');
    file_put_contents($cwd.'/.harness.json', '{"profile": "core", "mode": "enforce"}');

    [, $output] = polloraQa($cwd, 'check', '--fast');

    expect($output)->toContain('git is not usable here')
        ->and($output)->toContain('pint called with: --test --config')
        ->and($output)->not->toContain('--dirty')
        ->and($output)->toMatch('/✔ passed\s+Code style/');
});
