<?php

declare(strict_types=1);

use Pollora\Harness\Support\InterpreterFlags;

it('leaves pcov alone when it is not the driver', function (): void {
    expect(InterpreterFlags::pcovScope('/work/repo', false, ''))->toBe([]);
});

it('gives pcov the project root, minus what is not the code under test, when no directory is set or the guessed one is', function (string $configured): void {
    $flags = InterpreterFlags::pcovScope('/work/repo', true, $configured);

    expect($flags)->toHaveCount(4)
        ->and($flags[0])->toBe('-d')
        ->and($flags[1])->toBe('pcov.directory=/work/repo')
        ->and($flags[2])->toBe('-d')
        ->and($flags[3])->toStartWith('pcov.exclude=~^/work/repo/(?:vendor|node_modules|\.git|\.harness)/~');
})->with([
    'nothing set' => [''],
    'the guessed src' => ['/work/repo/src'],
    'the guessed lib' => ['/work/repo/lib'],
    'the guessed app' => ['/work/repo/app'],
    'the guess with a trailing slash' => ['/work/repo/src/'],
    'the root itself' => ['/work/repo'],
]);

it('respects a directory the project chose itself', function (string $configured): void {
    expect(InterpreterFlags::pcovScope('/work/repo', true, $configured))->toBe([]);
})->with([
    'a custom folder of the project' => ['/work/repo/packages/core'],
    'a folder outside the project' => ['/elsewhere/src'],
    'a similar name' => ['/work/repo/src-extra'],
]);

it('accepts a root written with a trailing separator', function (): void {
    expect(InterpreterFlags::pcovScope('/work/repo/', true, '')[1])->toBe('pcov.directory=/work/repo');
});

it('excludes the vendor folder of the project and nothing else on its path', function (): void {
    $pattern = InterpreterFlags::excludePattern('/home/runner/vendor/tests/repo');

    expect(preg_match($pattern, '/home/runner/vendor/tests/repo/vendor/laravel/framework/Foo.php'))->toBe(1)
        ->and(preg_match($pattern, '/home/runner/vendor/tests/repo/node_modules/x/y.php'))->toBe(1)
        ->and(preg_match($pattern, '/home/runner/vendor/tests/repo/src/Foo.php'))->toBe(0)
        ->and(preg_match($pattern, '/home/runner/vendor/tests/repo/vocabulary/Access.php'))->toBe(0)
        ->and(preg_match($pattern, '/home/runner/vendor/tests/repo/support/WpGlobals.php'))->toBe(0);
});

it('escapes what a root path holds, so the pattern still matches that path', function (): void {
    $root = '/work/my repo (v1.0)+x';

    expect(preg_match(InterpreterFlags::excludePattern($root), $root.'/vendor/a.php'))->toBe(1);
});

/**
 * pcov really instruments a folder outside src/ once the harness widens it: the bug is that it did not.
 *
 * @param  list<string>  $flags
 * @return list<int> The lines of extra/Late.php pcov recorded
 */
function pcovLinesOfExtraFile(string $root, array $flags): array
{
    $file = $root.'/extra/Late.php';
    $script = sprintf('\pcov\start(); require %s; (new Late)->run(); \pcov\stop(); echo json_encode(array_keys(\pcov\collect(\pcov\inclusive, [%s])[%s] ?? []));', var_export($file, true), var_export($file, true), var_export($file, true));
    $command = [PHP_BINARY, ...InterpreterFlags::inherited(), '-d', 'pcov.enabled=1', ...$flags, '-r', $script];
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
    $output = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    return json_decode($output, true) ?? [];
}

it('makes pcov instrument a folder outside src/, which it ignored before', function (): void {
    $root = realpath(harnessTempDir());
    mkdir($root.'/src');
    mkdir($root.'/extra');
    file_put_contents($root.'/src/Early.php', "<?php\n\nfinal class Early {}\n");
    file_put_contents($root.'/extra/Late.php', "<?php\n\nfinal class Late\n{\n    public function run(): int\n    {\n        return 1;\n    }\n}\n");

    expect(pcovLinesOfExtraFile($root, []))->toBe([])
        ->and(pcovLinesOfExtraFile($root, InterpreterFlags::pcovScope($root, true, $root.'/src')))->not->toBe([]);
})->skip(! extension_loaded('pcov'), 'pcov is not loaded');
