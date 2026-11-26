<?php

declare(strict_types=1);

namespace Pollora\Harness\Support;

/**
 * Which files of a repository PHPStan is configured to analyse.
 *
 * PHPStan analyses any file it is given on the command line, whatever `paths` says, so the fast gate and the post-edit
 * hook have to narrow the changed files themselves. The answer comes from PHPStan (`dump-parameters`), which resolves
 * the configuration with its includes, not from reading the neon files here.
 */
final readonly class PhpStanScope
{
    /**
     * @param  list<string>  $paths  The configured `paths`, absolute
     * @param  list<string>  $excluded  Both lists of `excludePaths`: files, directories and fnmatch patterns
     */
    private function __construct(
        private string $cwd,
        private array $paths,
        private array $excluded,
    ) {}

    /**
     * Null when it cannot be determined: no PHPStan here, a configuration it cannot read, an output that is not
     * the expected JSON, or no `paths` at all. The caller then keeps every file, so that nothing silently goes
     * unanalysed.
     */
    public static function of(string $cwd): ?self
    {
        if (! is_file($cwd.'/vendor/bin/phpstan')) {
            return null;
        }

        $process = proc_open(
            ['vendor/bin/phpstan', 'dump-parameters', '--json', '--memory-limit=1G'],
            [1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            $cwd,
        );

        if (! is_resource($process)) {
            return null;
        }

        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        if (proc_close($process) !== 0) {
            return null;
        }

        $parameters = json_decode($output, true);

        if (! is_array($parameters)) {
            return null;
        }

        $paths = self::strings($parameters['paths'] ?? null);

        if ($paths === []) {
            return null;
        }

        $excludePaths = is_array($parameters['excludePaths'] ?? null) ? $parameters['excludePaths'] : [];

        return new self($cwd, $paths, [
            ...self::strings($excludePaths['analyse'] ?? null),
            ...self::strings($excludePaths['analyseAndScan'] ?? null),
        ]);
    }

    /**
     * The files PHPStan would analyse, among these.
     *
     * @param  list<string>  $files  Relative to the repository root
     * @return list<string>
     */
    public function analysed(array $files): array
    {
        return array_values(array_filter($files, fn (string $file): bool => $this->analyses($this->cwd.'/'.$file)));
    }

    private function analyses(string $file): bool
    {
        $real = self::resolved($file);

        foreach ($this->excluded as $pattern) {
            // Both spellings: a pattern may name the path through a symbolic link (/var on macOS), or not
            if (self::excludes($pattern, $file, $real)) {
                return false;
            }
        }

        foreach ($this->paths as $path) {
            if (self::isWithin($real, self::resolved($path))) {
                return true;
            }
        }

        return false;
    }

    private static function isWithin(string $file, string $path): bool
    {
        return $file === $path || str_starts_with($file, rtrim($path, '/').'/');
    }

    /**
     * As PHPStan reads an exclusion: an fnmatch() pattern, or a file or directory (one that does not exist excludes nothing).
     */
    private static function excludes(string $pattern, string $file, string $real): bool
    {
        if (preg_match('~[*?[\]]~', $pattern) === 1) {
            return fnmatch($pattern, $file) || fnmatch($pattern, $real);
        }

        return file_exists($pattern) && self::isWithin($real, self::resolved($pattern));
    }

    private static function resolved(string $path): string
    {
        $real = realpath($path);

        return $real === false ? $path : $real;
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, is_string(...))) : [];
    }
}
