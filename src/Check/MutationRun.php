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
 */
final class MutationRun
{
    private const string ANSI = '/\x1b\[[0-9;]*m/';

    /**
     * @return array{score: float|null, summary: string, output: string}
     */
    public static function run(string $cwd): array
    {
        $result = self::execute($cwd, parallel: true);

        if ($result['score'] === null) {
            $result = self::execute($cwd, parallel: false);
        }

        return $result;
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
     * @return array{score: float|null, summary: string, output: string}
     */
    private static function execute(string $cwd, bool $parallel): array
    {
        $command = [
            PHP_BINARY,
            ...InterpreterFlags::inherited(),
            ...InterpreterFlags::coverageScope($cwd),
            'vendor/bin/pest',
            '--mutate',
            '--everything',
            '--covered-only',
            ...($parallel ? ['--parallel'] : []),
        ];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $cwd);

        if (! is_resource($process)) {
            return ['score' => null, 'summary' => '', 'output' => ''];
        }

        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        proc_close($process);

        return [...self::parse($output), 'output' => $output];
    }
}
