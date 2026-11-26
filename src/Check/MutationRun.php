<?php

declare(strict_types=1);

namespace Pollora\Harness\Check;

use FilesystemIterator;
use Pollora\Harness\Support\InterpreterFlags;
use SplFileInfo;

/**
 * Runs Pest's mutation testing and reads the score it prints.
 *
 * `pest --mutate --everything --covered-only` needs no `covers()` declaration
 * and ignores the code no test reaches, which the coverage gate already counts.
 * It runs in parallel first; a suite whose tests share global state (a function
 * a helper declares, a test that depends on the order) fails that way, so a run
 * that prints no score is repeated once in a single process.
 *
 * A run can be limited to some files (`--path=a,b`): the mutation gate of a repository whose whole run is too
 * long for a pull request mutates only the files the change touches. Files without a mutant (an interface, a
 * file with nothing to mutate) make Pest print `Mutations: 0 tested` and `Score: 0.00%`; that is no score, so the
 * result says `empty` and carries a null score instead of a 0 that would fail any threshold.
 *
 * Pest writes every mutant under `vendor/pestphp/pest-plugin-mutate/.temp/mutations`. When it cannot (the
 * directory is missing or read-only), each mutant's process fails, and a failed process counts as a killed
 * mutant: the score reads 100 % whatever the tests assert, with only a PHP warning to say so. The result then says
 * `unwritable` and carries a null score, and the run is not repeated. That warning only shows when the interpreter
 * displays errors, so the run forces `display_errors`, and does not rely on it: the directory is written to before
 * Pest starts and checked again once it has stopped.
 *
 * Pest keeps the mutants it generated in a cache that names files it does not check again: after a run that could not
 * write them, the next one reads 100 % with no warning. The run therefore passes `--no-cache`.
 *
 * Last, a score is only returned once `MutationCanary` has seen a mutated source being executed: when something else
 * serves the original file (Patchwork replacing Pest's stream wrapper), the result has a null score and says why in
 * `unloaded`.
 */
final class MutationRun
{
    private const string ANSI = '/\x1b\[[0-9;]*m/';

    /**
     * @param  list<string>  $paths  Source files (relative to $cwd) to mutate; empty mutates everything the tests cover
     * @return array{score: float|null, summary: string, output: string, empty: bool, unwritable: bool, unloaded?: string}
     */
    public static function run(string $cwd, array $paths = []): array
    {
        if (! self::temporaryDirectoryWritable($cwd)) {
            return ['score' => null, 'summary' => '', 'output' => '', 'empty' => false, 'unwritable' => true];
        }

        $started = time();
        $result = self::execute($cwd, true, $paths);

        if ($result['score'] === null && ! $result['empty'] && ! $result['unwritable']) {
            $result = self::execute($cwd, false, $paths);
        }

        // A directory that stopped being writable, or a mutant left empty (a full disk), while Pest was running
        if (! self::temporaryDirectoryWritable($cwd, $started)) {
            return [...$result, 'score' => null, 'empty' => false, 'unwritable' => true];
        }

        $refusal = $result['score'] === null ? null : MutationCanary::refusal($cwd, $paths);

        return $refusal === null ? $result : [...$result, 'score' => null, 'unloaded' => $refusal];
    }

    /**
     * Whether Pest can write its mutants, and read back the ones already there. A file is really written: permission
     * bits alone say nothing to root, nor about a full disk.
     *
     * @param  int|null  $since  When given, a mutant written since then and left empty counts as a failed write
     */
    public static function temporaryDirectoryWritable(string $cwd, ?int $since = null): bool
    {
        $plugin = $cwd.'/vendor/pestphp/pest-plugin-mutate';
        $directory = $plugin.'/.temp/mutations';

        if (! is_dir($plugin) || (! is_dir($directory) && (file_exists($directory) || (! @mkdir($directory, 0o777, true) && ! is_dir($directory))))) {
            return false;
        }

        $probe = $directory.'/.pollora-probe-'.bin2hex(random_bytes(6));
        $written = @file_put_contents($probe, 'probe') === 5;

        if (is_file($probe)) {
            unlink($probe);
        }

        if (! $written) {
            return false;
        }

        clearstatcache();

        foreach (new FilesystemIterator($directory) as $file) {
            if (! $file instanceof SplFileInfo || ! $file->isFile()) {
                continue;
            }

            if (! $file->isReadable() || ! $file->isWritable()) {
                return false;
            }

            if ($since !== null && $file->getMTime() >= $since && $file->getSize() === 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether Pest created no mutation at all: `Mutations: 0 tested`, or its "No mutations created" notice.
     *
     * The score it prints then (0.00 %) measures nothing.
     */
    public static function createdNoMutation(string $output): bool
    {
        $clean = (string) preg_replace(self::ANSI, '', $output);

        return preg_match('/No mutations created/i', $clean) === 1
            || preg_match('/^\s*Mutations:\s+0 tested\s*$/m', $clean) === 1;
    }

    /**
     * Whether Pest warned that it could not write its temporary mutation files, which makes every mutant count as killed.
     */
    public static function cannotWriteTemporaryFiles(string $output): bool
    {
        $clean = (string) preg_replace(self::ANSI, '', $output);

        return preg_match('/pest-plugin-mutate[^\n]*\.temp[^\n]*Failed to open stream|(?:bytes failed with errno|bytes written)[^\n]*pest-plugin-mutate/i', $clean) === 1;
    }

    /**
     * What a gate says when the score cannot be trusted because Pest could not write its temporary files.
     */
    public static function unwritableMessage(): string
    {
        return 'Pest could not write its temporary mutation files (vendor/pestphp/pest-plugin-mutate/.temp/mutations): each mutant process fails, and a failed process counts as killed, so the score would read 100 % whatever the tests assert. Check that the directory exists and is writable, then run again.';
    }

    /**
     * The mutants a report lists as not killed, one line each (`UNTESTED file > Line 61: Mutator` or `⨯ Line 61: Mutator`).
     *
     * @return list<string>
     */
    public static function survivors(string $output, int $limit = 25): array
    {
        $clean = (string) preg_replace(self::ANSI, '', $output);
        preg_match_all('/^\s*(?:(?:UNTESTED|SURVIVED|TIMEOUT)\s+\S.*>\s+Line\s+\d+:.*|⨯\s+Line\s+\d+:.*)$/m', $clean, $matches);

        return array_slice(array_map(static fn (string $line): string => trim((string) preg_replace('/\s+- ID: [0-9a-f]+\s*$/', '', $line)), $matches[0]), 0, $limit);
    }

    /**
     * The score and the count line of a Pest mutation report, null when it printed none.
     *
     * @return array{score: float|null, summary: string}
     */
    public static function parse(string $output): array
    {
        $clean = (string) preg_replace(self::ANSI, '', $output);
        $score = preg_match_all('/^\s*Score:\s+([0-9]+(?:\.[0-9]+)?)%/m', $clean, $scores) > 0 ? (float) end($scores[1]) : null;
        $summary = preg_match_all('/^\s*Mutations:\s+(.+)$/m', $clean, $counts) > 0 ? trim((string) end($counts[1])) : '';

        return ['score' => $score, 'summary' => $summary];
    }

    /**
     * @param  list<string>  $paths
     * @return array{score: float|null, summary: string, output: string, empty: bool, unwritable: bool}
     */
    private static function execute(string $cwd, bool $parallel, array $paths): array
    {
        $command = [
            PHP_BINARY,
            ...InterpreterFlags::inherited(),
            ...InterpreterFlags::coverageScope($cwd),
            // The only sign of a mutant Pest could not write is a warning, which the ini files may hide
            '-d', 'display_errors=1',
            'vendor/bin/pest',
            '--mutate',
            '--everything',
            '--covered-only',
            '--no-cache',
            ...($paths === [] ? [] : ['--path='.implode(',', $paths)]),
            ...($parallel ? ['--parallel'] : []),
        ];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $cwd);

        if (! is_resource($process)) {
            return ['score' => null, 'summary' => '', 'output' => '', 'empty' => false, 'unwritable' => false];
        }

        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        proc_close($process);

        $empty = self::createdNoMutation($output);
        $unwritable = self::cannotWriteTemporaryFiles($output);
        $parsed = self::parse($output);

        return ['score' => $empty || $unwritable ? null : $parsed['score'], 'summary' => $parsed['summary'], 'output' => $output, 'empty' => $empty, 'unwritable' => $unwritable];
    }
}
