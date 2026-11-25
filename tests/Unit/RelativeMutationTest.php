<?php

declare(strict_types=1);

use Pollora\Harness\Check\MutationMeasure;
use Pollora\Harness\Check\MutationRun;
use Pollora\Harness\Check\Plan;
use Pollora\Harness\Check\RelativeMutation;
use Pollora\Harness\Check\Step;
use Pollora\Harness\Check\StepResult;
use Pollora\Harness\Config\HarnessConfig;
use Pollora\Harness\Config\InvalidConfig;
use Pollora\Harness\Config\MutationSettings;
use Pollora\Harness\Console\Commands\CheckCommand;
use Pollora\Harness\Support\BaseCheckout;
use Pollora\Harness\Support\ChangedSources;

require_once __DIR__.'/../Support/RelativeMutationFixture.php';

/**
 * Runs the relative gate on a repository, with the fake Pest of the fixture unless a runner is given.
 *
 * @param  array<string, mixed>  $options  The `mutation` key of .harness.json, over `scope` and a minimum of 80
 * @param  list<string>|null  $calls  Filled with what the fake Pest was asked: "<directory> <file>", in order
 */
function relativeGate(string $root, array $options = [], ?array &$calls = null, ?callable $runner = null, ?callable $clock = null, ?callable $dependencies = null, string $base = 'HEAD^1'): StepResult
{
    $calls = [];
    $settings = MutationSettings::fromArray(['scope' => 'changed-relative', 'min' => 80, ...$options], 'x.json');
    $logging = static function (string $cwd, array $paths) use (&$calls, $runner, $root): array {
        $calls[] = ($cwd === $root ? 'change' : 'base').' '.implode(',', $paths);

        return ($runner ?? MutationRun::run(...))($cwd, $paths);
    };

    return (new RelativeMutation($root, $settings, $base, $logging, $clock, $dependencies))->run();
}

describe('the settings of the changed-relative scope', function (): void {
    it('reads the scope with its defaults', function (): void {
        $settings = MutationSettings::fromArray(['scope' => 'changed-relative'], 'x.json');

        expect($settings->relative())->toBeTrue()
            ->and($settings->limited())->toBeTrue()
            ->and($settings->changedOnly())->toBeFalse()
            ->and($settings->addedOnly())->toBeFalse()
            ->and($settings->tolerance)->toBe(2.0)
            ->and($settings->minMutants)->toBe(5)
            ->and($settings->maxFiles)->toBe(3)
            ->and($settings->maxMinutes)->toBe(15)
            ->and($settings->toArray())->toBe(['scope' => 'changed-relative']);
    });

    it('reads and writes back the options that differ from the defaults', function (): void {
        $data = ['scope' => 'changed-relative', 'min' => 80, 'tolerance' => 1.5, 'min_mutants' => 8, 'max_files' => 6, 'max_minutes' => 25];
        $settings = MutationSettings::fromArray($data, 'x.json');
        $config = HarnessConfig::fromArray(['profile' => 'core', 'mutation' => $data]);

        expect($settings->tolerance)->toBe(1.5)
            ->and($settings->minMutants)->toBe(8)
            ->and($settings->maxFiles)->toBe(6)
            ->and($settings->maxMinutes)->toBe(25)
            ->and($settings->toArray())->toBe($data)
            ->and($config->toArray()['mutation'])->toBe($data);
    });

    it('keeps its options when the command line chooses the scope', function (): void {
        $settings = (new MutationSettings(MutationSettings::CHANGED, null, 1.0, 7, 4, 9))->withScope('changed-relative');

        expect($settings->relative())->toBeTrue()
            ->and($settings->min)->toBe(MutationSettings::PULL_REQUEST_MINIMUM)
            ->and([$settings->tolerance, $settings->minMutants, $settings->maxFiles, $settings->maxMinutes])->toBe([1.0, 7, 4, 9]);
    });

    it('names the new scope among the ones it accepts', function (): void {
        expect(MutationSettings::isScope('changed-relative'))->toBeTrue()
            ->and(MutationSettings::isScope('relative'))->toBeFalse();
    });

    it('refuses an option that is not in its range', function (string $key, mixed $value): void {
        MutationSettings::fromArray(['scope' => 'changed-relative', $key => $value], 'x.json');
    })->with([
        'tolerance above 100' => ['tolerance', 101],
        'negative tolerance' => ['tolerance', -1],
        'tolerance as text' => ['tolerance', 'low'],
        'no mutant to judge with' => ['min_mutants', 0],
        'fractional files' => ['max_files', 2.5],
        'no file' => ['max_files', 0],
        'minutes as text' => ['max_minutes', '15'],
    ])->throws(InvalidConfig::class, 'x.json: mutation.');

    it('says which option is wrong and what it expects', function (): void {
        expect(fn () => MutationSettings::fromArray(['scope' => 'changed-relative', 'max_files' => 0], 'x.json'))
            ->toThrow(InvalidConfig::class, 'mutation.max_files is "0". Give a whole number, at least 1.');
    });

    it('lists the new scope when it refuses another', function (): void {
        expect(fn () => MutationSettings::fromArray(['scope' => 'diff'], 'x.json'))->toThrow(InvalidConfig::class, '"changed-relative"');
    });
});

describe('the files a change renames', function (): void {
    it('maps a renamed file to its path on the base', function (): void {
        $root = relRepository(['src/Old.php' => relMarker(5, 5)."// a long enough body to be seen as the same file\n// more\n// more\n"], ['src/New.php' => relMarker(5, 5)."// a long enough body to be seen as the same file\n// more\n// more\n"], renamed: ['src/New.php' => 'src/Old.php']);

        expect(ChangedSources::renamed($root, 'HEAD^1'))->toBe(['src/New.php' => 'src/Old.php'])
            ->and(ChangedSources::renamed($root, 'no-such-ref'))->toBe([])
            ->and(ChangedSources::renamed($root, '--stat'))->toBe([])
            ->and(ChangedSources::renamed(harnessTempDir(), 'HEAD^1'))->toBe([]);
    });
});

describe('the temporary checkout of the base', function (): void {
    it('holds the base sources and a copy of vendor, and leaves the working tree alone', function (): void {
        $root = relRepository(['src/A.php' => relMarker(1, 1)], ['src/A.php' => relMarker(2, 2)]);

        $checkout = BaseCheckout::create($root, 'HEAD^1');

        expect($checkout)->not->toBeNull()
            ->and(file_get_contents($checkout->path.'/src/A.php'))->toBe(relMarker(1, 1))
            ->and(file_get_contents($root.'/src/A.php'))->toBe(relMarker(2, 2))
            ->and(is_file($checkout->path.'/vendor/bin/pest'))->toBeTrue()
            ->and(is_link($checkout->path.'/vendor'))->toBeFalse()
            ->and(dirname($checkout->path))->not->toBe($root);

        $checkout->remove();
        $checkout->remove();

        expect(is_dir($checkout->path))->toBeFalse()
            ->and(substr_count(relGit($root, ['worktree', 'list']), "\n"))->toBe(0);
    });

    it('gives none when git cannot check the base out', function (string $base): void {
        $root = relRepository(['src/A.php' => relMarker(1, 1)], ['src/A.php' => relMarker(2, 2)]);

        expect(BaseCheckout::create($root, $base))->toBeNull();
    })->with(['an unknown ref' => ['no-such-ref'], 'an option' => ['--force'], 'nothing' => ['']]);

    it('gives none, and leaves nothing behind, when the dependencies cannot be copied', function (): void {
        $root = relRepository(['src/A.php' => relMarker(1, 1)], ['src/A.php' => relMarker(2, 2)]);
        $seen = null;

        $checkout = BaseCheckout::create($root, 'HEAD^1', static function (string $from, string $to) use (&$seen): bool {
            $seen = $to;

            return false;
        });

        expect($checkout)->toBeNull()
            ->and(is_dir((string) $seen))->toBeFalse()
            ->and(relGit($root, ['worktree', 'list']))->not->toContain('pollora-mutation-base');
    });

    it('gives none when the repository has no vendor directory', function (): void {
        $root = relRepository(['src/A.php' => relMarker(1, 1)], ['src/A.php' => relMarker(2, 2)]);
        exec('rm -rf '.escapeshellarg($root.'/vendor'));

        expect(BaseCheckout::create($root, 'HEAD^1'))->toBeNull();
    });

    it('gives none outside a git repository', function (): void {
        expect(BaseCheckout::create(harnessTempDir(), 'HEAD^1'))->toBeNull();
    });
});

describe('the measure of one Pest run', function (): void {
    /**
     * @return array{score: float|null, summary: string, output: string, empty: bool, unwritable: bool}
     */
    function pestRun(?float $score, string $summary, string $output = '', bool $empty = false, bool $unwritable = false): array
    {
        return ['score' => $score, 'summary' => $summary, 'output' => $output, 'empty' => $empty, 'unwritable' => $unwritable];
    }

    it('counts the mutants a score rests on, timeouts as caught', function (): void {
        $measure = MutationMeasure::fromRun(pestRun(80.0, "5 untested, 1 uncovered, 3 timeout, 31 tested\n"));

        expect($measure->state)->toBe(MutationMeasure::MEASURED)
            ->and($measure->killed)->toBe(34)
            ->and($measure->survived)->toBe(6)
            ->and($measure->total())->toBe(40)
            ->and($measure->describe())->toBe('80.00 % (34 caught of 40)');
    });

    it('does not mistake untested for tested', function (): void {
        $measure = MutationMeasure::fromRun(pestRun(0.0, '2 untested'));

        expect([$measure->killed, $measure->survived])->toBe([0, 2])
            ->and($measure->state)->toBe(MutationMeasure::MEASURED);
    });

    it('believes a real 100 %', function (): void {
        $measure = MutationMeasure::fromRun(pestRun(100.0, '12 tested'));

        expect($measure->state)->toBe(MutationMeasure::MEASURED)
            ->and($measure->score)->toBe(100.0);
    });

    it('refuses a score when Pest could not write its files', function (): void {
        $measure = MutationMeasure::fromRun(pestRun(null, '12 tested', unwritable: true));

        expect($measure->state)->toBe(MutationMeasure::ENVIRONMENT)
            ->and($measure->why)->toBe(MutationRun::unwritableMessage());
    });

    it('does not read the Patchwork line a healthy run prints as a failure', function (): void {
        $output = "  ...........;\\Patchwork\\CodeManipulation\\Stream::reinstateWrapper();.................\n  Mutations: 10 untested, 39 tested\n";
        $measure = MutationMeasure::fromRun(pestRun(79.59, '10 untested, 39 tested', $output));

        expect($measure->state)->toBe(MutationMeasure::MEASURED)
            ->and($measure->score)->toBe(79.59);
    });

    it('refuses a run whose mutants all survived, as Patchwork makes them', function (): void {
        $measure = MutationMeasure::fromRun(pestRun(0.0, '859 untested, 0 tested'));

        expect($measure->state)->toBe(MutationMeasure::UNMEASURED)
            ->and($measure->why)->toContain('All 859 mutants survived');
    });

    it('judges a genuine 0 % on a handful of mutants', function (): void {
        $measure = MutationMeasure::fromRun(pestRun(0.0, '4 untested, 0 tested'));

        expect($measure->state)->toBe(MutationMeasure::MEASURED);
    });

    it('is empty when Pest created no mutation, whatever score it printed', function (): void {
        $measure = MutationMeasure::fromRun(pestRun(null, '0 tested', empty: true));

        expect($measure->state)->toBe(MutationMeasure::EMPTY)
            ->and($measure->score)->toBeNull();
    });

    it('has no measure without a score, or without counts to rest it on', function (?float $score, string $summary, string $why): void {
        $measure = MutationMeasure::fromRun(pestRun($score, $summary));

        expect($measure->state)->toBe(MutationMeasure::UNMEASURED)
            ->and($measure->why)->toContain($why);
    })->with([
        'no score' => [null, '', 'tests must pass first'],
        'no count line' => [75.0, '', 'no mutant count'],
        'an unreadable count line' => [75.0, 'many', 'many'],
    ]);
});

describe('the relative gate on a change', function (): void {
    it('passes when the change touches no source file, without running Pest', function (): void {
        $root = relRepository(['src/A.php' => relMarker(5, 5)], ['README.md' => 'x', 'tests/ATest.php' => "<?php\n"]);

        $result = relativeGate($root, calls: $calls);

        expect($result->status)->toBe(StepResult::PASSED)
            ->and($result->detail)->toContain('nothing to mutate')
            ->and($calls)->toBe([]);
    });

    it('passes a file whose score did not move, comparing it with the base', function (): void {
        $root = relRepository(['src/A.php' => relMarker(37, 13)], ['src/A.php' => relMarker(37, 13)."// edited\n"]);

        $result = relativeGate($root, calls: $calls);

        expect($result->status)->toBe(StepResult::PASSED)
            ->and($result->detail)->toContain('74.00 % (37 caught of 50) on the change, 74.00 % (37 caught of 50) on the base')
            ->and($calls)->toBe(['change src/A.php', 'base src/A.php']);
    });

    it('absorbs the loss of one mutant out of two hundred', function (): void {
        $root = relRepository(['src/A.php' => relMarker(150, 50)], ['src/A.php' => relMarker(149, 51)]);

        $result = relativeGate($root);

        expect($result->status)->toBe(StepResult::PASSED)
            ->and($result->detail)->toContain('down 0.5 points, within the tolerance of 2');
    });

    it('refuses the loss of one mutant out of eight, and lists what survives', function (): void {
        $root = relRepository(['src/A.php' => relMarker(7, 1)], ['src/A.php' => relMarker(6, 2)]);

        $result = relativeGate($root);

        expect($result->status)->toBe(StepResult::FAILED)
            ->and($result->detail)->toContain('src/A.php: 75.00 % (6 caught of 8) on the change, 87.50 % (7 caught of 8) on the base')
            ->and($result->detail)->toContain('down 12.5 points (tolerance 2), 2 mutants survive, was 1')
            ->and($result->detail)->toContain('whole files')
            ->and($result->detail)->toContain('src/A.php UNTESTED  src/A.php  > Line 1: PlusToMinus');
    });

    it('takes the tolerance from the settings', function (string $tolerance, string $status): void {
        $root = relRepository(['src/A.php' => relMarker(45, 5)], ['src/A.php' => relMarker(43, 7)]);

        expect(relativeGate($root, ['tolerance' => (float) $tolerance])->status)->toBe($status);
    })->with([
        'exactly the drop' => ['4', StepResult::PASSED],
        'above the drop' => ['10', StepResult::PASSED],
        'below the drop' => ['3.9', StepResult::FAILED],
        'none' => ['0', StepResult::FAILED],
    ]);

    it('does not fail a drop that adds no surviving mutant: tested code was removed', function (): void {
        $root = relRepository(['src/A.php' => relMarker(40, 10)], ['src/A.php' => relMarker(20, 10)]);

        $result = relativeGate($root);

        expect($result->status)->toBe(StepResult::PASSED)
            ->and($result->detail)->toContain('no more mutant survives (10, was 10)');
    });

    it('passes a file that gained', function (): void {
        $root = relRepository(['src/A.php' => relMarker(5, 15)], ['src/A.php' => relMarker(15, 5)]);

        expect(relativeGate($root)->status)->toBe(StepResult::PASSED);
    });

    it('does not measure the base of a file the change cannot have lowered beyond the tolerance', function (): void {
        $root = relRepository(['src/A.php' => relMarker(10, 0)], ['src/A.php' => relMarker(99, 1)]);

        $result = relativeGate($root, calls: $calls);

        expect($result->status)->toBe(StepResult::PASSED)
            ->and($result->detail)->toContain('cannot have dropped by more than 2 points')
            ->and($calls)->toBe(['change src/A.php']);
    });

    it('holds a new file to the minimum, without measuring a base it does not have', function (string $killed, string $status): void {
        $root = relRepository(['src/Old.php' => relMarker(5, 5)], ['src/New.php' => relMarker((int) $killed, 10 - (int) $killed)]);

        $result = relativeGate($root, calls: $calls);

        expect($result->status)->toBe($status)
            ->and($result->detail)->toContain('(new file)')
            ->and($calls)->toBe(['change src/New.php']);
    })->with([
        'at the minimum' => ['8', StepResult::PASSED],
        'above it' => ['10', StepResult::PASSED],
        'below it' => ['7', StepResult::FAILED],
    ]);

    it('only reports a new file when no minimum is set', function (): void {
        $root = relRepository(['src/Old.php' => relMarker(5, 5)], ['src/New.php' => relMarker(1, 9)]);

        $result = relativeGate($root, ['min' => null]);

        expect($result->status)->toBe(StepResult::PASSED)
            ->and($result->detail)->toContain('no minimum set');
    });

    it('lists a file with no mutant, neither failing nor measuring a base for it', function (): void {
        $root = relRepository(['src/Contract.php' => relMarker(5, 5)], ['src/Contract.php' => relReport("  Mutations: 0 tested\n  Score:     0.00%\n   INFO  No mutations created.\n")]);

        $result = relativeGate($root, calls: $calls);

        expect($result->status)->toBe(StepResult::PASSED)
            ->and($result->detail)->toContain('- src/Contract.php: no mutant')
            ->and($calls)->toBe(['change src/Contract.php']);
    });

    it('lists a file too small to judge, as neither failed nor green', function (): void {
        $root = relRepository(['src/A.php' => relMarker(3, 0)], ['src/A.php' => relMarker(2, 1)]);

        $result = relativeGate($root);

        expect($result->status)->toBe(StepResult::PASSED)
            ->and($result->detail)->toContain('? src/A.php')
            ->and($result->detail)->toContain('fewer than 5 mutants, too few to judge')
            ->and($result->detail)->toContain('1 file too small to judge: neither failed nor counted as green')
            ->and($result->detail)->not->toContain('✓ src/A.php');
    });

    it('judges a small file once the minimum number of mutants says so', function (): void {
        $root = relRepository(['src/A.php' => relMarker(3, 0)], ['src/A.php' => relMarker(2, 1)]);

        expect(relativeGate($root, ['min_mutants' => 3])->status)->toBe(StepResult::FAILED);
    });

    it('holds the file to the minimum when the base had no mutant to compare with', function (string $killed, string $status): void {
        $root = relRepository(['src/A.php' => relReport("  Mutations: 0 tested\n  Score:     0.00%\n")], ['src/A.php' => relMarker((int) $killed, 10 - (int) $killed)]);

        $result = relativeGate($root);

        expect($result->status)->toBe($status)
            ->and($result->detail)->toContain('the base had no mutant to compare with');
    })->with([['9', StepResult::PASSED], ['5', StepResult::FAILED]]);

    it('holds the file to the minimum, and says so, when the base cannot be measured', function (string $report, string $why): void {
        $root = relRepository(['src/A.php' => relReport($report)], ['src/A.php' => relMarker(5, 5)]);

        $result = relativeGate($root);

        expect($result->status)->toBe(StepResult::FAILED)
            ->and($result->detail)->toContain('base unmeasured')
            ->and($result->detail)->toContain($why)
            ->and($result->detail)->toContain('is below the minimum of 80 %');
    })->with([
        'its tests fail' => ["  Tests:    2 failed, 1 passed\n", 'the tests must pass first'],
        'every mutant survives' => ["  Mutations: 12 untested, 0 tested\n  Score:     0.00%\n", 'All 12 mutants survived'],
    ]);

    it('passes a file whose base cannot be measured once it reaches the minimum', function (): void {
        $root = relRepository(['src/A.php' => relReport("  Tests:    2 failed, 1 passed\n")], ['src/A.php' => relMarker(9, 1)]);

        $result = relativeGate($root);

        expect($result->status)->toBe(StepResult::PASSED)
            ->and($result->detail)->toContain('base unmeasured');
    });

    it('compares a renamed file with its old path', function (): void {
        $body = "// a body long enough for git to see a rename, not a deletion and an addition\n// 1\n// 2\n// 3\n";
        $root = relRepository(['src/Old.php' => relMarker(7, 1).$body], ['src/New.php' => relMarker(6, 2).$body], renamed: ['src/New.php' => 'src/Old.php']);

        $result = relativeGate($root, calls: $calls);

        expect($result->status)->toBe(StepResult::FAILED)
            ->and($result->detail)->toContain('on the base')
            ->and($calls)->toBe(['change src/New.php', 'base src/Old.php']);
    });

    it('treats a file that is not on the base as new', function (): void {
        $root = relRepository(['src/A.php' => relMarker(5, 5)], ['src/A.php' => relMarker(5, 5)."// edited\n", 'src/B.php' => relMarker(9, 1)]);
        $log = [];

        $result = relativeGate($root, calls: $log);

        expect($result->detail)->toContain('(new file)')
            ->and($log)->toBe(['change src/A.php', 'change src/B.php', 'base src/A.php']);
    });

    it('removes the base checkout when it is done, and when it fails', function (): void {
        $root = relRepository(['src/A.php' => relMarker(7, 1)], ['src/A.php' => relMarker(6, 2)]);

        relativeGate($root);

        expect(relGit($root, ['worktree', 'list']))->not->toContain('pollora-mutation-base');

        $boom = static fn (string $cwd, array $paths): array => str_contains($cwd, 'pollora-mutation-base') ? throw new RuntimeException('Pest died') : MutationRun::run($cwd, $paths);

        expect(fn () => relativeGate($root, runner: $boom))->toThrow(RuntimeException::class, 'Pest died')
            ->and(relGit($root, ['worktree', 'list']))->not->toContain('pollora-mutation-base');
    });

    it('fails, saying what to do, when the base cannot be checked out', function (): void {
        $root = relRepository(['src/A.php' => relMarker(7, 1)], ['src/A.php' => relMarker(6, 2)]);

        $result = relativeGate($root, dependencies: static fn (): bool => false);

        expect($result->status)->toBe(StepResult::FAILED)
            ->and($result->detail)->toContain('Cannot check HEAD^1 out')
            ->and($result->detail)->toContain('fetch-depth: 2');
    });

    it('fails, saying what to do, when the changed files cannot be listed', function (): void {
        $root = relRepository(['src/A.php' => relMarker(7, 1)], ['src/A.php' => relMarker(6, 2)]);

        $result = relativeGate($root, calls: $calls, base: 'no-such-ref');

        expect($result->status)->toBe(StepResult::FAILED)
            ->and($result->detail)->toContain('Cannot list the files changed since no-such-ref')
            ->and($result->detail)->toContain('--mutation-base')
            ->and($calls)->toBe([]);
    });
});

describe('the measuring guards of the relative gate', function (): void {
    it('fails rather than believe a score of the change that cannot be trusted', function (string $report, string $why): void {
        $root = relRepository(['src/A.php' => relMarker(5, 5)], ['src/A.php' => relReport($report)]);

        $result = relativeGate($root);

        expect($result->status)->toBe(StepResult::FAILED)
            ->and($result->detail)->toContain($why);
    })->with([
        'the tests fail' => ["  Tests:    2 failed, 1 passed\n", 'unmeasured on the change: Pest printed no mutation score'],
        'every mutant survived, as with Patchwork' => ["  Mutations: 859 untested, 0 tested\n  Score:     0.00%\n", 'All 859 mutants survived'],
    ]);

    it('fails, whatever the score, when Pest cannot write its temporary files', function (): void {
        $warning = "  Warning: file_put_contents(/app/vendor/pestphp/pest-plugin-mutate/.temp/mutations/x.php): Failed to open stream: No such file or directory\n  Mutations: 12 tested\n  Score:     100.00%\n";
        $root = relRepository(['src/A.php' => relMarker(5, 5)], ['src/A.php' => relReport($warning)]);

        $result = relativeGate($root);

        expect($result->status)->toBe(StepResult::FAILED)
            ->and($result->detail)->toBe(MutationRun::unwritableMessage());
    });

    it('fails, whatever the file scored, when Pest cannot write its files on the base', function (): void {
        $warning = "  Warning: file_put_contents(/app/vendor/pestphp/pest-plugin-mutate/.temp/mutations/x.php): Failed to open stream\n  Mutations: 12 tested\n  Score:     100.00%\n";
        $root = relRepository(['src/A.php' => relReport($warning)], ['src/A.php' => relMarker(5, 5)]);

        $result = relativeGate($root);

        expect($result->status)->toBe(StepResult::FAILED)
            ->and($result->detail)->toBe(MutationRun::unwritableMessage());
    });

    it('refuses a repository that loads Patchwork without giving the wrapper back to Pest', function (): void {
        $root = relRepository(['src/A.php' => relMarker(7, 1)], ['src/A.php' => relMarker(7, 1)."// edited\n"]);
        relWrite($root, ['vendor/antecedent/patchwork/Patchwork.php' => "<?php\n", 'tests/bootstrap.php' => "<?php\n"]);

        $result = relativeGate($root, calls: $calls);

        expect($result->status)->toBe(StepResult::FAILED)
            ->and($result->detail)->toContain('antecedent/patchwork is installed')
            ->and($result->detail)->toContain('PEST_MUTATION_TESTING')
            ->and($calls)->toBe([]);
    });

    it('measures a repository whose bootstrap gives the wrapper back', function (string $file): void {
        $root = relRepository(['src/A.php' => relMarker(7, 1)], ['src/A.php' => relMarker(7, 1)."// edited\n"]);
        relWrite($root, ['vendor/antecedent/patchwork/Patchwork.php' => "<?php\n", $file => "<?php\n\$mutating = getenv('PEST_MUTATION_TESTING');\n"]);

        expect(relativeGate($root)->status)->toBe(StepResult::PASSED);
    })->with(['a bootstrap' => ['tests/bootstrap.php'], 'a nested one' => ['tests/Unit/bootstrap.php'], 'phpunit.xml' => ['phpunit.xml']]);

    it('has no guard to apply without Patchwork', function (): void {
        expect(RelativeMutation::patchworkGuard(harnessTempDir()))->toBeNull();
    });
});

describe('the cost cap of the relative gate', function (): void {
    it('refuses a change that touches more files than max_files, before running anything', function (): void {
        $root = relRepository(['src/A.php' => relMarker(5, 5), 'src/B.php' => relMarker(5, 5), 'src/C.php' => relMarker(5, 5)], ['src/A.php' => relMarker(5, 5).'//', 'src/B.php' => relMarker(5, 5).'//', 'src/C.php' => relMarker(5, 5).'//']);

        $result = relativeGate($root, ['max_files' => 2], $calls);

        expect($result->status)->toBe(StepResult::FAILED)
            ->and($result->detail)->toContain('touches 3 source files and mutation.max_files is 2')
            ->and($result->detail)->toContain('src/B.php')
            ->and($result->detail)->toContain('measuring some would let the others through')
            ->and($calls)->toBe([]);
    });

    it('measures a change that touches exactly max_files files', function (): void {
        $root = relRepository(['src/A.php' => relMarker(5, 5), 'src/B.php' => relMarker(5, 5)], ['src/A.php' => relMarker(5, 5).'//', 'src/B.php' => relMarker(5, 5).'//']);

        expect(relativeGate($root, ['max_files' => 2])->status)->toBe(StepResult::PASSED);
    });

    /**
     * A runner that takes `$seconds` of a clock it moves by hand, and a gate on `$files` modified files.
     *
     * @return array{StepResult, list<string>}
     */
    function timedRelativeGate(int $files, float $seconds, int $minutes): array
    {
        $names = array_slice(['A', 'B', 'C'], 0, $files);
        $root = relRepository(
            array_combine(array_map(static fn (string $n): string => "src/{$n}.php", $names), array_fill(0, $files, relMarker(40, 10))),
            array_combine(array_map(static fn (string $n): string => "src/{$n}.php", $names), array_fill(0, $files, relMarker(40, 10).'//')),
        );
        $now = 1000.0;
        $runner = static function (string $cwd, array $paths) use (&$now, $seconds): array {
            $now += $seconds;

            return ['score' => 80.0, 'summary' => '10 untested, 40 tested', 'output' => '', 'empty' => false, 'unwritable' => false];
        };

        $result = relativeGate($root, ['max_minutes' => $minutes], $calls, $runner, static function () use (&$now): float {
            return $now;
        });

        return [$result, $calls];
    }

    it('stops before a pass that would overrun the budget, and fails instead of passing what it did not measure', function (): void {
        [$result, $calls] = timedRelativeGate(3, 400.0, 15);

        expect($result->status)->toBe(StepResult::FAILED)
            ->and($calls)->toBe(['change src/A.php', 'change src/B.php'])
            ->and($result->detail)->toContain('✗ src/A.php: not compared: the time budget ran out before the base of this file')
            ->and($result->detail)->toContain('✗ src/C.php: not measured: the time budget ran out before this file');
    });

    it('stops before measuring a base it has no time for', function (): void {
        [$result, $calls] = timedRelativeGate(2, 400.0, 15);

        expect($result->status)->toBe(StepResult::FAILED)
            ->and($calls)->toBe(['change src/A.php', 'change src/B.php'])
            ->and($result->detail)->toContain('the time budget ran out before the base of this file was measured');
    });

    it('measures everything when the budget allows it', function (): void {
        [$result, $calls] = timedRelativeGate(2, 100.0, 15);

        expect($result->status)->toBe(StepResult::PASSED)
            ->and($calls)->toBe(['change src/A.php', 'change src/B.php', 'base src/A.php', 'base src/B.php'])
            ->and($result->detail)->toContain('400 s');
    });
});

describe('the relative gate in the plan', function (): void {
    /**
     * @return list<Step>
     */
    function relativePlanSteps(string $root, array $config): array
    {
        return (new Plan(HarnessConfig::fromArray(['profile' => 'core', ...$config]), $root))->full(null, null, true);
    }

    it('replaces the whole-repository gate, blocks, and does not feed the ratchet', function (): void {
        $root = relRepository(['src/A.php' => relMarker(7, 1)], ['src/A.php' => relMarker(6, 2)]);
        $steps = relativePlanSteps($root, ['mutation' => ['scope' => 'changed-relative', 'min' => 80], 'ratchet' => ['msi' => 95]]);
        $names = array_map(static fn (Step $step): string => $step->name, $steps);
        $step = array_values(array_filter($steps, static fn (Step $step): bool => $step->name === Plan::MUTATION_RELATIVE))[0];

        expect($names)->toContain(Plan::MUTATION_RELATIVE)
            ->and($names)->not->toContain(Plan::MUTATION)
            ->and($names)->not->toContain(Plan::MUTATION_CHANGED)
            ->and(Plan::OBSERVED_BY_DEFAULT)->not->toContain(Plan::MUTATION_RELATIVE)
            ->and(($step->action)()->status)->toBe(StepResult::FAILED);
    });

    it('counts the change from the base the plan is given', function (): void {
        $root = relRepository(['src/A.php' => relMarker(7, 1)], ['src/A.php' => relMarker(6, 2)]);
        $steps = (new Plan(HarnessConfig::fromArray(['profile' => 'core', 'mutation' => ['scope' => 'changed-relative']]), $root))->full(null, null, true, 'HEAD');
        $step = array_values(array_filter($steps, static fn (Step $step): bool => $step->name === Plan::MUTATION_RELATIVE))[0];

        expect(($step->action)()->detail)->toContain('No source file changed since HEAD');
    });

    it('is chosen from the command line over .harness.json', function (?array $file): void {
        $root = relRepository(['src/A.php' => relMarker(7, 1)], ['src/A.php' => relMarker(6, 2)]);
        $config = HarnessConfig::fromArray(['profile' => 'core', 'mutation' => $file]);
        $steps = (new Plan($config->withMutation($config->mutation->withScope('changed-relative')), $root))->full(null, null, true);
        $step = array_values(array_filter($steps, static fn (Step $step): bool => str_starts_with($step->name, 'Mutation testing')))[0];

        expect($step->name)->toBe(Plan::MUTATION_RELATIVE);
    })->with([
        'no key' => [null],
        'a file that says added' => [['scope' => 'added', 'min' => 70]],
        'a file that says all' => [['scope' => 'all']],
    ]);

    it('is a scope the command line names, and the refusal of an unknown one lists it', function (): void {
        $cwd = harnessTempDir();
        file_put_contents($cwd.'/.harness.json', '{"profile": "core"}');

        [$code, $output, $errors] = harnessRun(new CheckCommand, $cwd, '--mutation-scope=relative');

        expect($code)->toBe(1)
            ->and($errors)->toContain('"changed-relative"')
            ->and($output)->not->toContain('Pollora harness ·');
    });
});
