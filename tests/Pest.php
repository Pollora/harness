<?php

declare(strict_types=1);
use Pollora\Harness\Console\Command;
use Pollora\Harness\Console\Io;

/**
 * A fresh, empty directory for a test that writes files.
 */
function harnessTempDir(): string
{
    $directory = sys_get_temp_dir().'/pollora-harness-'.bin2hex(random_bytes(6));
    mkdir($directory, 0o755, true);

    return $directory;
}

function fixtureProject(): string
{
    return __DIR__.'/Fixtures/Project';
}

/**
 * Runs a command in this process (so that its code is measured), with its output captured.
 *
 * @return array{int, string, string} The exit code, what it printed, what it wrote to stderr
 */
function harnessRun(Command $command, string $cwd, string ...$argv): array
{
    $stdout = fopen('php://memory', 'w+');
    $stderr = fopen('php://memory', 'w+');
    $io = Io::fromArgv($cwd, $argv, $stdout, $stderr);
    $code = $command->run($io);
    rewind($stdout);
    rewind($stderr);
    $out = (string) stream_get_contents($stdout);
    $err = (string) stream_get_contents($stderr);
    fclose($stdout);
    fclose($stderr);

    return [$code, $out, $err];
}

/**
 * A temporary git repository with one commit, holding the given files.
 *
 * @param  array<string, string>  $files  Path => contents
 */
function harnessGitRepository(array $files): string
{
    $root = harnessTempDir();

    foreach ($files as $path => $contents) {
        if (! is_dir(dirname($root.'/'.$path))) {
            mkdir(dirname($root.'/'.$path), 0o755, true);
        }

        file_put_contents($root.'/'.$path, $contents);
    }

    $git = 'cd '.escapeshellarg($root).' && git init -q -b main && git config user.email t@example.test && git config user.name T && git config commit.gpgsign false && git add -A && git commit -q -m init 2>&1';
    exec($git, $output, $status);

    if ($status !== 0) {
        throw new RuntimeException('git is not usable: '.implode("\n", $output));
    }

    return $root;
}
