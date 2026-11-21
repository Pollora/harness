<?php

declare(strict_types=1);

/**
 * A fake `composer` that answers each call with the next scripted outcome (the last one repeats) and counts its calls.
 *
 * @param  list<array{int, string}>  $outcomes  Exit code and output of the first call, the second, and so on
 * @return array{string, string} The path of the fake executable and the directory that holds its call counter
 */
function fakeComposer(array $outcomes): array
{
    $directory = harnessTempDir();

    foreach ($outcomes as $index => [$code, $output]) {
        file_put_contents($directory.'/out-'.($index + 1), $output);
        file_put_contents($directory.'/code-'.($index + 1), (string) $code);
    }

    file_put_contents($directory.'/last', (string) count($outcomes));
    file_put_contents($directory.'/composer', <<<'SH'
        #!/bin/sh
        d=$(dirname "$0")
        n=$(cat "$d/count" 2>/dev/null || echo 0)
        n=$((n + 1))
        echo "$n" > "$d/count"
        [ -f "$d/out-$n" ] || n=$(cat "$d/last")
        cat "$d/out-$n"
        exit "$(cat "$d/code-$n")"
        SH);
    chmod($directory.'/composer', 0o755);

    return [$directory.'/composer', $directory];
}

/**
 * How many times the fake composer was called.
 */
function fakeComposerCalls(string $directory): int
{
    return (int) @file_get_contents($directory.'/count');
}
