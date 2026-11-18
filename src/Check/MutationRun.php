<?php

declare(strict_types=1);

namespace Pollora\Harness\Check;

use Pollora\Harness\Support\InterpreterFlags;

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
 */
final class MutationRun
{
    private const string ANSI = '/\x1b\[[0-9;]*m/';

    /**
     * @param  list<string>  $paths  Source files (relative to $cwd) to mutate; empty mutates everything the tests cover
     * @return array{score: float|null, summary: string, output: string, empty: bool}
     */
    public static function run(string $cwd, array $paths = []): array
    {
        $result = self::execute($cwd, true, $paths);

        if ($result['score'] === null && ! $result['empty']) {
            $result = self::execute($cwd, false, $paths);
        }

        return $result;
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
     * @return array{score: float|null, summary: string, output: string, empty: bool}
     */
    private static function execute(string $cwd, bool $parallel, array $paths): array
    {
        $command = [
            PHP_BINARY,
            ...InterpreterFlags::inherited(),
            ...InterpreterFlags::coverageScope($cwd),
            'vendor/bin/pest',
            '--mutate',
            '--everything',
            '--covered-only',
            ...($paths === [] ? [] : ['--path='.implode(',', $paths)]),
            ...($parallel ? ['--parallel'] : []),
        ];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $cwd);

        if (! is_resource($process)) {
            return ['score' => null, 'summary' => '', 'output' => '', 'empty' => false];
        }

        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        proc_close($process);

        $empty = self::createdNoMutation($output);
        $parsed = self::parse($output);

        return ['score' => $empty ? null : $parsed['score'], 'summary' => $parsed['summary'], 'output' => $output, 'empty' => $empty];
    }
}
