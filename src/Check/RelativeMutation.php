<?php

declare(strict_types=1);

namespace Pollora\Harness\Check;

use Pollora\Harness\Config\MutationSettings;
use Pollora\Harness\Config\Ratchet;
use Pollora\Harness\Support\BaseCheckout;
use Pollora\Harness\Support\ChangedSources;

/**
 * The mutation gate of `mutation.scope: "changed-relative"`: a file a change edits must not score lower than it did.
 *
 * `added` holds new files only, and `changed` holds every touched file to one fixed minimum, which blocks a change
 * to an old file for mutants it did not write. This one asks the question a reviewer asks: did this change make the
 * tests of this file weaker? Each modified file is measured on the change and on the base (a temporary checkout of
 * `--mutation-base`, with its own tests) and the gate refuses a drop, with these rules:
 *
 * - **Tolerance.** A drop of at most `tolerance` points is noise: a timeout that counts as killed or not with the
 *   load of the machine moved a score by 1.35 points on the same code. Beyond it the change is refused, but only
 *   when more mutants survive than on the base: deleting well-tested code lowers the score without adding a single
 *   surviving mutant, and that is not a regression.
 * - **Few mutants.** A file with fewer than `min_mutants` mutants on either side is listed as too small to judge:
 *   neither failed nor counted as green.
 * - **New files** (absent from the base) are held to `min`, as `added` does. A renamed file is compared with its old
 *   path.
 * - **No mutant** on the change (an interface): listed, not failed. No mutant on the base: the file is held to `min`.
 * - **Base unmeasured** (its tests fail, a measuring guard trips): the file is held to `min` and says so; a
 *   regression cannot be proved, so it is the fixed floor or nothing.
 * - **Change unmeasured**: the gate fails. A score is refused rather than believed when Pest could not write its
 *   files, when Patchwork took over the stream wrapper, when every mutant survived, or when the tests fail.
 * - **Nothing is measured twice for nothing.** The change is measured first; a file at or above `100 - tolerance`
 *   cannot have dropped beyond the tolerance, so its base is not measured.
 * - **Cost.** One Pest pass per file and per side (each repeats the suite's own run first): the gate refuses a change
 *   that touches more than `max_files` files before running anything, and stops, failing, before a pass its
 *   predecessors say would overrun `max_minutes`. It never reports the files it did not reach as fine.
 *
 * What it does not see: a change that only edits tests (no source file changed, so nothing is measured) and a
 * regression spread over files the change does not touch.
 */
final class RelativeMutation
{
    /** The base and the change are compared as soon as it is clear that a file cannot have dropped. */
    private const float EPSILON = Ratchet::TOLERANCE;

    /** @var callable(string, list<string>): array{score: float|null, summary: string, output: string, empty: bool, unwritable: bool} */
    private $runner;

    /** @var callable(): float */
    private $clock;

    /** @var (callable(string, string): bool)|null */
    private $dependencies;

    /**
     * @param  string  $cwd  The repository, at the change
     * @param  string  $base  The commit the change is counted from (`HEAD^1` on a pull request's merge commit)
     * @param  (callable(string, list<string>): array{score: float|null, summary: string, output: string, empty: bool, unwritable: bool})|null  $runner  One Pest mutation run; `MutationRun::run()` by default
     * @param  (callable(): float)|null  $clock  Seconds; `microtime(true)` by default
     * @param  (callable(string, string): bool)|null  $dependencies  Gives the base checkout its dependencies; `BaseCheckout`'s copy of `vendor/` by default
     */
    public function __construct(
        private readonly string $cwd,
        private readonly MutationSettings $settings,
        private readonly string $base,
        ?callable $runner = null,
        ?callable $clock = null,
        ?callable $dependencies = null,
    ) {
        $this->runner = $runner ?? MutationRun::run(...);
        $this->clock = $clock ?? static fn (): float => microtime(true);
        $this->dependencies = $dependencies;
    }

    /**
     * Measures the files the change touches and judges them.
     */
    public function run(): StepResult
    {
        $changed = ChangedSources::since($this->cwd, $this->base);

        if ($changed === null) {
            return StepResult::failed(sprintf('Cannot list the files changed since %s. The checkout needs a history of two commits (`fetch-depth: 2` in actions/checkout) or a base given with --mutation-base=<ref>.', $this->base));
        }

        if ($changed === []) {
            return StepResult::passed(sprintf('  No source file changed since %s: nothing to mutate.', $this->base));
        }

        if (count($changed) > $this->settings->maxFiles) {
            return StepResult::failed(sprintf(
                "The change touches %d source files and mutation.max_files is %d: each takes two Pest passes (one on the base, one on the change), so measuring them all would not fit the time of a pull request, and measuring some would let the others through.\nRaise mutation.max_files, split the change, or measure by hand: pollora-qa check --mutation --mutation-scope=changed-relative --mutation-base=%s\n%s",
                count($changed),
                $this->settings->maxFiles,
                $this->base,
                implode("\n", array_map(static fn (string $file): string => '  '.$file, $changed)),
            ));
        }

        $guard = self::patchworkGuard($this->cwd);

        if ($guard !== null) {
            return StepResult::failed($guard);
        }

        $started = ($this->clock)();
        $passes = [];
        $added = ChangedSources::since($this->cwd, $this->base, ChangedSources::ADDED) ?? [];
        $renamed = ChangedSources::renamed($this->cwd, $this->base);
        $rows = [];
        $after = [];

        foreach ($changed as $file) {
            if ($this->overruns($started, $passes)) {
                break;
            }

            $passes[] = $this->timed(fn (): MutationMeasure => $this->measure($this->cwd, $file));
            $after[$file] = $passes[array_key_last($passes)]['measure'];

            if ($after[$file]->state === MutationMeasure::ENVIRONMENT) {
                return StepResult::failed($after[$file]->why);
            }
        }

        $base = null;

        try {
            foreach ($changed as $file) {
                if (! isset($after[$file])) {
                    $rows[$file] = ['status' => 'budget', 'line' => 'not measured: the time budget ran out before this file'];

                    continue;
                }

                $now = $after[$file];
                $new = in_array($file, $added, true);
                $before = null;

                if ($this->needsBase($now, $new)) {
                    $oldPath = $renamed[$file] ?? $file;
                    $base ??= BaseCheckout::create($this->cwd, $this->base, $this->dependencies);

                    if (! $base instanceof BaseCheckout) {
                        return StepResult::failed(sprintf('Cannot check %s out to measure the base: git could not create a worktree, or vendor/ could not be copied. The checkout needs the base commit (`fetch-depth: 2` in actions/checkout).', $this->base));
                    }

                    if (! is_file($base->path.'/'.$oldPath)) {
                        $new = true;
                    } elseif ($this->overruns($started, $passes)) {
                        $rows[$file] = ['status' => 'budget', 'line' => 'not compared: the time budget ran out before the base of this file was measured'];

                        continue;
                    } else {
                        $passes[] = $this->timed(fn (): MutationMeasure => $this->measure($base->path, $oldPath));
                        $before = $passes[array_key_last($passes)]['measure'];

                        if ($before->state === MutationMeasure::ENVIRONMENT) {
                            return StepResult::failed($before->why);
                        }
                    }
                }

                $rows[$file] = $this->judge($file, $now, $before, $new);
            }
        } finally {
            $base?->remove();
        }

        return $this->report($changed, $rows, ($this->clock)() - $started);
    }

    /**
     * Whether a suite that loads Patchwork gives Pest's stream wrapper back when it is mutated.
     *
     * Brain Monkey (and Patchwork) rewrites what PHP loads through a stream wrapper on `file`, as Pest does for a
     * mutant. When Patchwork's wins, the mutant is never loaded and every mutant survives, or the two collide on
     * a directory function, the mutant's process dies and counts as killed: the score reads 0 % or 100 % whatever
     * the tests assert, and nothing in Pest's report says so. A repository that has Patchwork must say, in a test
     * bootstrap, that it handles `PEST_MUTATION_TESTING`; this gate refuses to measure one that does not.
     *
     * @return string|null The message of the refusal, null when the repository is fine
     */
    public static function patchworkGuard(string $cwd): ?string
    {
        $root = rtrim($cwd, '/');

        if (! is_file($root.'/vendor/antecedent/patchwork/Patchwork.php')) {
            return null;
        }

        $candidates = [...self::files($root.'/tests/*.php'), ...self::files($root.'/tests/*/*.php'), ...self::files($root.'/phpunit.xml*')];

        foreach ($candidates as $candidate) {
            if (is_file($candidate) && str_contains((string) file_get_contents($candidate), 'PEST_MUTATION_TESTING')) {
                return null;
            }
        }

        return "The mutation score cannot be measured here: antecedent/patchwork is installed (Brain Monkey loads it) and no test bootstrap reads PEST_MUTATION_TESTING.\nPatchwork and Pest both rewrite what PHP loads through a stream wrapper on `file`: the mutant is never loaded and every mutant survives, or the process dies and counts as killed, so the score would be wrong in a way Pest does not report. Give the wrapper back to Pest in the tests' bootstrap when PEST_MUTATION_TESTING is set (see the framework's tests/bootstrap.php).";
    }

    /**
     * The files a glob pattern matches.
     *
     * @return list<string>
     */
    private static function files(string $pattern): array
    {
        $matches = glob($pattern);

        return $matches === false ? [] : $matches;
    }

    /**
     * One Pest run limited to one file, turned into a measure.
     *
     * @param  string  $directory  The checkout the run happens in
     * @param  string  $file  The source file, relative to it
     */
    private function measure(string $directory, string $file): MutationMeasure
    {
        return MutationMeasure::fromRun(($this->runner)($directory, [$file]));
    }

    /**
     * Whether the base must be measured to judge a file: not when the change cannot have lowered it beyond the tolerance.
     */
    private function needsBase(MutationMeasure $now, bool $new): bool
    {
        if ($new || $now->state !== MutationMeasure::MEASURED) {
            return false;
        }

        return ($now->score ?? 0.0) + $this->settings->tolerance + self::EPSILON < 100.0;
    }

    /**
     * Runs one pass and records how long it took.
     *
     * @param  callable(): MutationMeasure  $pass
     * @return array{measure: MutationMeasure, seconds: float}
     */
    private function timed(callable $pass): array
    {
        $before = ($this->clock)();
        $measure = $pass();

        return ['measure' => $measure, 'seconds' => ($this->clock)() - $before];
    }

    /**
     * Whether another pass, as long as the average of the passes so far, would run past `max_minutes`.
     *
     * @param  list<array{measure: MutationMeasure, seconds: float}>  $passes
     */
    private function overruns(float $started, array $passes): bool
    {
        $elapsed = ($this->clock)() - $started;
        $average = $passes === [] ? 0.0 : array_sum(array_column($passes, 'seconds')) / count($passes);

        return $elapsed + $average > $this->settings->maxMinutes * 60;
    }

    /**
     * The verdict on one file.
     *
     * @return array{status: string, line: string, survivors?: list<string>}
     */
    private function judge(string $file, MutationMeasure $now, ?MutationMeasure $before, bool $new): array
    {
        $settings = $this->settings;

        if ($now->state === MutationMeasure::EMPTY) {
            return ['status' => 'info', 'line' => 'no mutant (an interface, or nothing to mutate): nothing to measure'];
        }

        if ($now->state !== MutationMeasure::MEASURED) {
            return ['status' => 'unmeasured', 'line' => 'unmeasured on the change: '.$now->why];
        }

        $floor = fn (string $why): array => $this->againstMinimum($now, $why);

        if ($new) {
            return $floor('new file');
        }

        if (! $before instanceof MutationMeasure) {
            return ['status' => 'ok', 'line' => sprintf('%s on the change, at or above %s %%: it cannot have dropped by more than %s points', $now->describe(), self::points(100.0 - $settings->tolerance), self::points($settings->tolerance))];
        }

        if ($before->state === MutationMeasure::EMPTY) {
            return $floor('the base had no mutant to compare with');
        }

        if ($before->state !== MutationMeasure::MEASURED) {
            return $floor('base unmeasured ('.$before->why.')');
        }

        $line = sprintf('%s on the change, %s on the base', $now->describe(), $before->describe());

        if ($now->total() < $settings->minMutants || $before->total() < $settings->minMutants) {
            return ['status' => 'few', 'line' => sprintf('%s: fewer than %d mutants, too few to judge', $line, $settings->minMutants)];
        }

        $drop = ($before->score ?? 0.0) - ($now->score ?? 0.0);

        if ($drop <= $settings->tolerance + self::EPSILON) {
            return ['status' => 'ok', 'line' => $line.($drop > 0 ? sprintf(', down %s points, within the tolerance of %s', self::points($drop), self::points($settings->tolerance)) : '')];
        }

        if ($now->survived <= $before->survived) {
            return ['status' => 'ok', 'line' => sprintf('%s, down %s points but no more mutant survives (%d, was %d): code with caught mutants was removed', $line, self::points($drop), $now->survived, $before->survived)];
        }

        return [
            'status' => 'refused',
            'line' => sprintf('%s: down %s points (tolerance %s), %d mutants survive, was %d', $line, self::points($drop), self::points($settings->tolerance), $now->survived, $before->survived),
            'survivors' => MutationRun::survivors($now->output, 10),
        ];
    }

    /**
     * A file the base cannot be compared with, held to `min` as a new file is.
     *
     * @return array{status: string, line: string}
     */
    private function againstMinimum(MutationMeasure $now, string $why): array
    {
        $min = $this->settings->min;
        $line = sprintf('%s (%s)', $now->describe(), $why);

        if ($min === null) {
            return ['status' => 'ok', 'line' => $line.': no minimum set (mutation.min), reported only'];
        }

        if ($min <= ($now->score ?? 0.0) + self::EPSILON) {
            return ['status' => 'ok', 'line' => sprintf('%s, minimum %s %%', $line, self::points($min))];
        }

        return ['status' => 'refused', 'line' => sprintf('%s is below the minimum of %s %% (mutation.min)', $line, self::points($min)), 'survivors' => MutationRun::survivors($now->output, 10)];
    }

    /**
     * Assembles the lines and the verdict of the step.
     *
     * @param  list<string>  $files
     * @param  array<string, array{status: string, line: string, survivors?: list<string>}>  $rows
     */
    private function report(array $files, array $rows, float $seconds): StepResult
    {
        $failed = array_filter($rows, static fn (array $row): bool => in_array($row['status'], ['refused', 'unmeasured', 'budget'], true));
        $few = array_filter($rows, static fn (array $row): bool => $row['status'] === 'few');
        $lines = [sprintf(
            '  Mutation score of %d changed file%s against %s (tolerance %s points, %s s):',
            count($files),
            count($files) === 1 ? '' : 's',
            $this->base,
            self::points($this->settings->tolerance),
            number_format($seconds, 0, '.', ''),
        )];

        foreach ($files as $file) {
            $row = $rows[$file];
            $lines[] = sprintf('  %s %s: %s', match ($row['status']) {
                'ok' => '✓',
                'info' => '-',
                'few' => '?',
                default => '✗',
            }, $file, $row['line']);
        }

        if ($few !== []) {
            $lines[] = sprintf('  %d file%s too small to judge: neither failed nor counted as green.', count($few), count($few) === 1 ? '' : 's');
        }

        if ($failed === []) {
            return StepResult::passed(implode("\n", $lines));
        }

        $lines[] = 'A score is computed on whole files: a mutant that survives in a file you touched counts even if you did not write it. Add the test that would fail without that line, or tighten the tests of the file you changed.';

        foreach ($failed as $file => $row) {
            foreach ($row['survivors'] ?? [] as $survivor) {
                $lines[] = sprintf('    %s %s', $file, $survivor);
            }
        }

        return StepResult::failed(implode("\n", $lines));
    }

    /**
     * A number of points without a useless decimal part: `2`, `2.5`.
     */
    private static function points(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
