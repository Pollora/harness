<?php

declare(strict_types=1);

namespace Pollora\Harness\Support;

/**
 * The source files a change touches, for the mutation run limited to them (`mutation.scope: "changed"`).
 *
 * `Paths::changedPhpFiles()` reads the working tree, which is empty on a clean CI checkout. This one compares
 * `HEAD` with a base commit: the default `HEAD^1` is, on the merge commit a pull request's CI checks out (and on
 * the merge commit of a push to `develop`), the target branch before the change, so the diff is the change itself.
 * It needs a history of two commits (`fetch-depth: 2` in `actions/checkout`).
 */
final class ChangedSources
{
    /** The base compared with `HEAD` when none is given: the first parent. */
    public const string DEFAULT_BASE = 'HEAD^1';

    /** The git diff filter of every file a change touches: added, copied, modified, renamed. */
    public const string CHANGED = 'ACMR';

    /** The git diff filter of the files a change adds. */
    public const string ADDED = 'A';

    /** The name Pest's mutation bootstrap gives the copy of a file it mutates; never a source file. */
    private const string MUTANT_PREFIX = '.mutant-';

    /**
     * The PHP source files added, copied, modified or renamed between `$base` and `HEAD`, relative to `$cwd`.
     *
     * Tests, `vendor/`, the files Composer loads with `autoload.files` (Pest cannot replace a file PHP included
     * before the tests start, so its mutants always survive) and files outside the autoloaded source directories
     * are left out, as is a file that no longer exists.
     *
     * @param  string  $filter  `self::CHANGED` for every file a change touches, `self::ADDED` for the files it adds only
     * @return list<string>|null null when git cannot say: not a repository, an unknown base, a history too short
     */
    public static function since(string $cwd, string $base = self::DEFAULT_BASE, string $filter = self::CHANGED): ?array
    {
        if ($base === '' || str_starts_with($base, '-') || ! Paths::gitUsable($cwd)) {
            return null;
        }

        $process = proc_open(
            ['git', 'diff', '--name-only', '--diff-filter='.$filter, $base, 'HEAD'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $cwd,
        );

        if (! is_resource($process)) {
            return null;
        }

        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        if (proc_close($process) !== 0) {
            return null;
        }

        $directories = self::sourceDirectories($cwd);
        $loaded = self::autoloadFiles($cwd);

        return array_values(array_filter(
            explode("\n", $output),
            static fn (string $file): bool => str_ends_with($file, '.php')
                && ! str_starts_with(basename($file), self::MUTANT_PREFIX)
                && ! in_array($file, $loaded, true)
                && self::isUnder($file, $directories)
                && is_file(rtrim($cwd, '/').'/'.$file),
        ));
    }

    /**
     * The files a change renames, as new path => path on the base, for the source files `since()` lists.
     *
     * A renamed file has no score on the base under its new name: the relative mutation gate measures its old path
     * instead, so that a rename does not make an old file look new.
     *
     * @return array<string, string> Empty when nothing was renamed or git cannot say
     */
    public static function renamed(string $cwd, string $base = self::DEFAULT_BASE): array
    {
        if ($base === '' || str_starts_with($base, '-') || ! Paths::gitUsable($cwd)) {
            return [];
        }

        $process = proc_open(['git', 'diff', '--name-status', '-M', '--diff-filter=R', $base, 'HEAD'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);

        if (! is_resource($process)) {
            return [];
        }

        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        if (proc_close($process) !== 0) {
            return [];
        }

        $renamed = [];

        foreach (explode("\n", $output) as $line) {
            $columns = explode("\t", $line);

            if (count($columns) === 3 && str_starts_with($columns[0], 'R')) {
                $renamed[$columns[2]] = $columns[1];
            }
        }

        return $renamed;
    }

    /**
     * The directories Composer autoloads classes from (`autoload.psr-4`, `autoload.psr-0`, `autoload.classmap`), as
     * `dir/`; `src/` when composer.json names none.
     *
     * @return list<string>
     */
    public static function sourceDirectories(string $cwd): array
    {
        $autoload = self::autoload($cwd);
        $paths = [];

        foreach (['psr-4', 'psr-0'] as $kind) {
            $map = $autoload[$kind] ?? [];

            foreach (is_array($map) ? $map : [] as $value) {
                array_push($paths, ...(is_array($value) ? $value : [$value]));
            }
        }

        $classmap = $autoload['classmap'] ?? [];
        array_push($paths, ...(is_array($classmap) ? $classmap : []));

        $directories = [];

        foreach ($paths as $path) {
            if (is_string($path) && $path !== '' && ! str_ends_with($path, '.php')) {
                $directories[] = trim($path, '/').'/';
            }
        }

        $directories = array_values(array_unique(array_filter($directories, static fn (string $directory): bool => $directory !== '/')));

        return $directories === [] ? ['src/'] : $directories;
    }

    /**
     * The files Composer includes at startup (`autoload.files`), relative to the repository root.
     *
     * @return list<string>
     */
    public static function autoloadFiles(string $cwd): array
    {
        $files = self::autoload($cwd)['files'] ?? [];

        return is_array($files) ? array_values(array_filter(array_map(
            static fn (mixed $file): ?string => is_string($file) ? ltrim($file, './') : null,
            $files,
        ), static fn (?string $file): bool => $file !== null)) : [];
    }

    /**
     * @return array<mixed>
     */
    private static function autoload(string $cwd): array
    {
        $path = rtrim($cwd, '/').'/composer.json';
        $composer = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        $autoload = is_array($composer) ? ($composer['autoload'] ?? null) : null;

        return is_array($autoload) ? $autoload : [];
    }

    /**
     * @param  list<string>  $directories
     */
    private static function isUnder(string $file, array $directories): bool
    {
        foreach ($directories as $directory) {
            if (str_starts_with($file, $directory)) {
                return true;
            }
        }

        return false;
    }
}
