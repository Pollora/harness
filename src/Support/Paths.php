<?php

declare(strict_types=1);

namespace Pollora\Harness\Support;

final class Paths
{
    /** The harness package directory, wherever Composer installed it. */
    public static function package(): string
    {
        return dirname(__DIR__, 2);
    }

    public static function preset(string $relative): string
    {
        return self::package().'/presets/'.$relative;
    }

    public static function stub(string $relative): string
    {
        return self::package().'/stubs/'.$relative;
    }

    /**
     * Finds an executable on the PATH.
     */
    public static function which(string $binary): ?string
    {
        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $directory) {
            $candidate = rtrim($directory, '/').'/'.$binary;

            if ($directory !== '' && is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * PHP files changed in the working tree (staged, unstaged or untracked),
     * relative to the repository root.
     *
     * @return list<string>
     */
    public static function changedPhpFiles(string $cwd): array
    {
        $commands = [
            ['git', 'diff', '--name-only', '--diff-filter=ACMR', 'HEAD'],
            ['git', 'ls-files', '--others', '--exclude-standard'],
        ];

        $files = [];

        foreach ($commands as $command) {
            $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);

            if (! is_resource($process)) {
                continue;
            }

            $output = (string) stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);

            array_push($files, ...array_filter(explode("\n", $output)));
        }

        return array_values(array_unique(array_filter(
            $files,
            static fn (string $file): bool => str_ends_with($file, '.php')
                && ! str_starts_with($file, 'vendor/')
                && is_file($cwd.'/'.$file),
        )));
    }
}
