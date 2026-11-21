<?php

declare(strict_types=1);

use Pollora\Harness\Support\Paths;

/*
 * Each fixture line annotated `// ruleid: <rule>` (or `{{-- ruleid: <rule> --}}`
 * in Blade) must be reported on the next line, and nothing else may be. A line two
 * rules report carries two `ruleid:` markers.
 * Semgrep skips tests/ by default, so the fixtures are scanned from a copy.
 */

it('reports exactly the annotated lines', function (): void {
    $semgrep = Paths::which('semgrep');

    if ($semgrep === null) {
        $this->markTestSkipped('semgrep is not installed (pipx install semgrep)');
    }

    $copy = harnessTempDir();
    mkdir($copy.'/src');

    $expected = [];
    $root = realpath(__DIR__.'/../Fixtures/semgrep/src');
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

    foreach ($files as $fixture) {
        $relative = substr($fixture->getPathname(), strlen($root) + 1);
        $target = $copy.'/src/'.$relative;
        is_dir(dirname($target)) || mkdir(dirname($target), 0777, true);
        copy($fixture->getPathname(), $target);

        foreach (file($fixture->getPathname()) ?: [] as $index => $line) {
            if (preg_match_all('/ruleid:\s*([a-z0-9-]+)/', $line, $matches) > 0) {
                foreach ($matches[1] as $rule) {
                    $expected[] = $rule.' src/'.$relative.':'.($index + 2);
                }
            }
        }
    }

    $command = [$semgrep, 'scan', '--metrics=off', '--disable-version-check', '--quiet', '--json', '--config', Paths::preset('semgrep/common.yml'), '--config', Paths::preset('semgrep/observed.yml'), 'src'];
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $copy);
    $json = json_decode((string) stream_get_contents($pipes[1]), true);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    $found = array_map(
        static fn (array $result): string => explode('.', $result['check_id'])[array_key_last(explode('.', $result['check_id'])) - 1].' '.$result['path'].':'.$result['start']['line'],
        $json['results'] ?? [],
    );

    sort($expected);
    sort($found);

    expect($json['errors'] ?? [])->toBe([])
        ->and($found)->toBe($expected);
});
