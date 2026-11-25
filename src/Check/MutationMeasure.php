<?php

declare(strict_types=1);

namespace Pollora\Harness\Check;

/**
 * What one Pest mutation run says about one file, or why it says nothing that can be believed.
 *
 * The relative mutation gate compares two such measures. A score that cannot be trusted must stop the gate rather
 * than feed it, so a run only counts as `measured` when none of the known ways to read a false score applies:
 *
 * - Pest could not write its temporary files: every mutant process fails, a failed process counts as killed and the
 *   score reads 100 % whatever the tests assert (`environment`).
 * - Patchwork replaced Pest's stream wrapper (a suite that loads it before Pest, as Brain Monkey does): the mutant is
 *   never loaded and every mutant survives, or the two wrappers meet on a directory function, the process dies and
 *   the mutant counts as killed. Pest's compact report shows neither the crash nor the wrapper, and a line such as
 *   `\Patchwork\CodeManipulation\Stream::reinstateWrapper();` also appears in the output of a healthy run (the
 *   framework's), so the output is no witness. What is seen here is the first case, a run in which every mutant
 *   survives (`859 untested, 0 tested`, `unmeasured`); the second, a score of 100 % that no test earned, is refused
 *   by `RelativeMutation::patchworkGuard()` before any run, from the repository.
 * - Pest created no mutation: `Score: 0.00%` measures nothing (`empty`).
 *
 * `RelativeMutation` adds the one check that needs the repository, not the output: a suite that loads Patchwork
 * without handing the stream wrapper back to Pest.
 */
final readonly class MutationMeasure
{
    /** The score is Pest's, and the counts say how many mutants it rests on. */
    public const string MEASURED = 'measured';

    /** Pest created no mutation in the file (an interface, a file with nothing to mutate): there is no score. */
    public const string EMPTY = 'empty';

    /** A score that cannot be believed, or no score: the reason is in `$why`. */
    public const string UNMEASURED = 'unmeasured';

    /** Pest could not write its temporary files, which no file's score survives: the whole gate stops. */
    public const string ENVIRONMENT = 'environment';

    /**
     * From this many mutants, every one of them surviving is read as "the mutants never ran" (Patchwork), not as
     * a file whose tests catch nothing. Below it, a genuine 0 % is possible and is judged like any other score.
     */
    public const int SUSPECT_ALL_SURVIVED_FROM = 5;

    /**
     * @param  'measured'|'empty'|'unmeasured'|'environment'  $state
     * @param  int  $killed  Mutants a test caught (tested plus timeouts, which Pest counts as caught)
     * @param  int  $survived  Mutants no test noticed
     */
    public function __construct(
        public string $state,
        public ?float $score = null,
        public int $killed = 0,
        public int $survived = 0,
        public string $why = '',
        public string $output = '',
    ) {}

    /**
     * The measure of one run of `MutationRun::run()`.
     *
     * @param  array{score: float|null, summary: string, output: string, empty: bool, unwritable: bool}  $run
     */
    public static function fromRun(array $run): self
    {
        if ($run['unwritable']) {
            return new self(self::ENVIRONMENT, why: MutationRun::unwritableMessage());
        }

        if ($run['empty']) {
            return new self(self::EMPTY, output: $run['output']);
        }

        if ($run['score'] === null) {
            return new self(self::UNMEASURED, why: 'Pest printed no mutation score: the tests must pass first.', output: $run['output']);
        }

        $counts = self::counts($run['summary']);
        $killed = $counts['tested'] + $counts['timeout'];
        $survived = $counts['untested'] + $counts['uncovered'] + $counts['pending'];

        if ($killed + $survived === 0) {
            return new self(self::UNMEASURED, why: sprintf('Pest printed a score of %s %% but no mutant count to rest it on (%s).', $run['score'], $run['summary'] === '' ? 'no count line' : $run['summary']), output: $run['output']);
        }

        if ($killed === 0 && $survived >= self::SUSPECT_ALL_SURVIVED_FROM) {
            return new self(self::UNMEASURED, why: sprintf('All %d mutants survived and none was caught: that is what a run whose mutants never ran looks like (Patchwork replacing the stream wrapper, tests not loading the mutated file), not a measure. Check that the tests of this file run under `pest --mutate`.', $survived), output: $run['output']);
        }

        return new self(self::MEASURED, $run['score'], $killed, $survived, output: $run['output']);
    }

    /**
     * Mutants the score rests on.
     */
    public function total(): int
    {
        return $this->killed + $this->survived;
    }

    /**
     * "74.00 % (37 caught of 50)", for the gate's lines.
     */
    public function describe(): string
    {
        return sprintf('%.2f %% (%d caught of %d)', $this->score ?? 0.0, $this->killed, $this->total());
    }

    /**
     * The counts of Pest's `Mutations:` line (`5 untested, 2 timeout, 39 tested`).
     *
     * @return array{tested: int, untested: int, timeout: int, uncovered: int, pending: int}
     */
    private static function counts(string $summary): array
    {
        $counts = ['tested' => 0, 'untested' => 0, 'timeout' => 0, 'uncovered' => 0, 'pending' => 0];

        foreach (array_keys($counts) as $label) {
            if (preg_match('/(\d+)\s+'.$label.'\b/', $summary, $match) === 1) {
                $counts[$label] = (int) $match[1];
            }
        }

        return $counts;
    }
}
