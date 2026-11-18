<?php

declare(strict_types=1);

/**
 * A repository with one package-lock.json per directory and a fake `npm` on the PATH that prints a captured
 * `npm audit --json` report for each: the real output, since the gate reads that format.
 *
 * @param  array<string, string>  $reports  Directory ("." for the root) => fixture name (tests/Fixtures/npm-audit/<name>.json), or raw output
 * @return string The repository root
 */
function npmAuditRepository(array $reports): string
{
    $root = harnessTempDir();
    $bin = harnessTempDir();

    foreach ($reports as $directory => $report) {
        $dir = $directory === '.' ? $root : $root.'/'.$directory;
        is_dir($dir) || mkdir($dir, 0o755, true);
        file_put_contents($dir.'/package-lock.json', '{}');

        $fixture = __DIR__.'/../Fixtures/npm-audit/'.$report.'.json';
        file_put_contents($bin.'/audit-'.strtr($directory, '/.', '__').'.json', is_file($fixture) ? (string) file_get_contents($fixture) : $report);
    }

    // The gate calls: npm audit --json --prefix <directory>
    file_put_contents($bin.'/npm', "#!/bin/sh\ncat \"\$(dirname \"\$0\")/audit-\$(echo \"\$4\" | tr '/.' '__').json\"\nexit 1\n");
    chmod($bin.'/npm', 0o755);
    putenv('PATH='.$bin.PATH_SEPARATOR.(string) getenv('PATH'));

    return $root;
}
