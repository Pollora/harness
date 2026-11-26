<?php

declare(strict_types=1);

/**
 * The jobs of a reusable workflow, as the text of each one.
 *
 * @return array<string, string>
 */
function resultWorkflowJobs(string $workflow): array
{
    $text = (string) file_get_contents(dirname(__DIR__, 2).'/.github/workflows/'.$workflow);
    $jobs = [];

    foreach (preg_split('/^  (?=[a-z-]+:\n)/m', substr($text, (int) strpos($text, "\njobs:\n"))) ?: [] as $block) {
        if (preg_match('/^([a-z-]+):\n/', $block, $id) === 1) {
            $jobs[$id[1]] = $block;
        }
    }

    return $jobs;
}

function resultScript(string $workflow): string
{
    preg_match('/^        run: \|\n((?:          .*\n?|\n)+)/m', resultWorkflowJobs($workflow)['result'] ?? '', $match);

    return rtrim((string) preg_replace('/^ {10}/m', '', $match[1] ?? ''))."\n";
}

/**
 * Runs the script of the Result job of php-package.yml with these job results.
 *
 * @param  array<string, string>  $results  Job id => result, over a run where everything that applies passed
 * @param  list<string>  $advisory  The jobs whose continue-on-error is true in that run
 * @return array{int, string}
 */
function resultRun(array $results = [], array $advisory = []): array
{
    $results = [...['setup' => 'success', 'quality' => 'success', 'tests' => 'success', 'wordpress' => 'skipped', 'contract' => 'success', 'secrets' => 'success', 'downstream' => 'skipped'], ...$results];
    $needs = array_map(static fn (string $result): array => ['result' => $result, 'outputs' => []], $results);
    $environment = ['PATH' => (string) getenv('PATH'), 'NEEDS' => (string) json_encode($needs, JSON_PRETTY_PRINT | JSON_FORCE_OBJECT)];

    foreach (array_keys($results) as $job) {
        $environment['ADVISORY_'.strtoupper(str_replace('-', '_', $job))] = in_array($job, $advisory, true) ? 'true' : 'false';
    }

    // The shell GitHub gives a `run:` step
    $process = proc_open(['bash', '--noprofile', '--norc', '-eo', 'pipefail', '-c', resultScript('php-package.yml')], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
    $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), (string) $output];
}

/** The jobs with continue-on-error in observe mode: all but setup and quality. */
const RESULT_OBSERVE = ['tests', 'wordpress', 'contract', 'secrets', 'downstream'];

it('has one job with a stable name, always run, that needs every other job', function (string $workflow): void {
    $jobs = resultWorkflowJobs($workflow);
    preg_match('/^    needs: \[(.+)\]$/m', $jobs['result'], $needs);

    expect($jobs['result'])->toContain("    name: Result\n", "    if: always()\n")
        ->and(explode(', ', $needs[1] ?? ''))->toBe(array_values(array_diff(array_keys($jobs), ['result'])));
})->with(['php-package.yml', 'template.yml']);

it('ignores a failure exactly where the job itself has continue-on-error', function (string $workflow): void {
    $jobs = resultWorkflowJobs($workflow);
    $expected = [];

    foreach ($jobs as $id => $block) {
        if ($id !== 'result' && preg_match('/^    continue-on-error: (.+)$/m', $block, $match) === 1) {
            $expected[strtoupper(str_replace('-', '_', $id))] = $match[1];
        }
    }

    preg_match_all('/^          ADVISORY_([A-Z_]+): (.+)$/m', $jobs['result'], $declared);

    expect($expected)->not->toBe([])
        ->and(array_combine($declared[1], $declared[2]))->toBe($expected);
})->with(['php-package.yml', 'template.yml']);

it('runs the same script in both workflows', function (): void {
    expect(resultScript('template.yml'))->toBe(resultScript('php-package.yml'))->toContain('NEEDS');
});

it('passes when everything passed or was legitimately skipped', function (array $results): void {
    [$code, $output] = resultRun($results);

    expect($code)->toBe(0, $output);
})->with([
    'no integration suite, no downstream' => [[]],
    'both of them run' => [['wordpress' => 'success', 'downstream' => 'success']],
]);

it('fails when a job failed or was cancelled in enforce mode', function (string $job, string $result): void {
    [$code, $output] = resultRun([$job => $result]);

    expect($code)->toBe(1)
        ->and($output)->toContain("::error::{$job} ended in {$result}.");
})->with(['setup', 'quality', 'tests', 'wordpress', 'contract', 'secrets', 'downstream'])->with(['failure', 'cancelled']);

it('keeps observe mode: a failure continue-on-error ignores does not fail it', function (): void {
    [$code, $output] = resultRun(array_fill_keys(RESULT_OBSERVE, 'failure'), RESULT_OBSERVE);

    expect($code)->toBe(0, $output)
        ->and($output)->toContain('tests: failure (reported, not blocking)');
});

it('still fails in observe mode on what blocks the run there, and on a cancelled job', function (string $job, string $result): void {
    expect(resultRun([$job => $result], RESULT_OBSERVE)[0])->toBe(1);
})->with([
    ['setup', 'failure'],
    ['quality', 'failure'],
    ['quality', 'cancelled'],
    ['tests', 'cancelled'],
    ['secrets', 'cancelled'],
]);

it('ignores the API contract alone on a pull request labelled breaking', function (): void {
    expect(resultRun(['contract' => 'failure'], ['contract'])[0])->toBe(0)
        ->and(resultRun(['contract' => 'failure', 'tests' => 'failure'], ['contract'])[0])->toBe(1);
});

it('fails when setup did not run, with everything after it skipped', function (string $result): void {
    expect(resultRun(['setup' => $result, 'quality' => 'skipped', 'tests' => 'skipped', 'contract' => 'skipped', 'secrets' => 'skipped'])[0])->toBe(1);
})->with(['failure', 'cancelled', 'skipped']);

it('fails when there is no result to read', function (): void {
    $process = proc_open(['bash', '-eo', 'pipefail', '-c', resultScript('php-package.yml')], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['PATH' => (string) getenv('PATH'), 'NEEDS' => '{}']);
    stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    expect(proc_close($process))->toBe(1);
});
