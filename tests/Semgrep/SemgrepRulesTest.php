<?php

declare(strict_types=1);

use Pollora\Harness\Support\Paths;

/*
 * Each fixture line annotated `// ruleid: <rule>` (or `{{-- ruleid: <rule> --}}`
 * in Blade) must be reported on the next line, and nothing else may be.
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

    foreach (glob(__DIR__.'/../Fixtures/semgrep/src/*') ?: [] as $fixture) {
        copy($fixture, $copy.'/src/'.basename($fixture));

        foreach (file($fixture) ?: [] as $index => $line) {
            if (preg_match('/ruleid:\s*([a-z0-9-]+)/', $line, $match) === 1) {
                $expected[] = $match[1].' src/'.basename($fixture).':'.($index + 2);
            }
        }
    }

    $command = [$semgrep, 'scan', '--metrics=off', '--disable-version-check', '--quiet', '--json', '--config', Paths::preset('semgrep/common.yml'), 'src'];
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
