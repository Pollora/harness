<?php

declare(strict_types=1);

use Pollora\Harness\Check\MutationRun;
use Pollora\Harness\Check\Plan;
use Pollora\Harness\Check\Step;
use Pollora\Harness\Check\StepResult;
use Pollora\Harness\Config\HarnessConfig;
use Pollora\Harness\Config\InvalidConfig;
use Pollora\Harness\Config\MutationSettings;
use Pollora\Harness\Console\Commands\CheckCommand;
use Pollora\Harness\Support\ChangedSources;

/**
 * Runs git in a directory and returns what it printed.
 *
 * @param  list<string>  $arguments
 */
function scopeGit(string $root, array $arguments): string
{
    $process = proc_open(['git', '-c', 'user.name=t', '-c', 'user.email=t@example.test', '-c', 'commit.gpgsign=false', ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
    $output = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    return trim($output);
}

/**
 * Writes files (path => content) under a root, creating the directories.
 *
 * @param  array<string, string>  $files
 */
function scopeWrite(string $root, array $files): void
{
    foreach ($files as $path => $content) {
        if (! is_dir(dirname($root.'/'.$path))) {
            mkdir(dirname($root.'/'.$path), 0o755, true);
        }

        file_put_contents($root.'/'.$path, $content);
    }
}

/**
 * A git repository with a composer.json and one commit, then a second commit that adds the given files.
 *
 * @param  array<string, string>  $changes  Files the second commit adds or modifies
 * @param  list<string>  $removed  Files the second commit deletes (they exist in the first)
 */
function scopedRepository(array $changes, array $removed = []): string
{
    $root = harnessTempDir();
    scopeGit($root, ['init', '-q', '-b', 'main']);
    scopeWrite($root, [
        'composer.json' => json_encode(['autoload' => ['psr-4' => ['App\\' => 'src/'], 'files' => ['src/helpers.php']]], JSON_THROW_ON_ERROR),
        'src/Old.php' => "<?php\n",
        'src/Gone.php' => "<?php\n",
        'src/helpers.php' => "<?php\n",
    ]);
    scopeGit($root, ['add', '-A']);
    scopeGit($root, ['commit', '-q', '-m', 'first']);

    scopeWrite($root, $changes);

    foreach ($removed as $file) {
        unlink($root.'/'.$file);
    }

    scopeGit($root, ['add', '-A']);
    scopeGit($root, ['commit', '-q', '-m', 'second']);

    return $root;
}

describe('the mutation settings of .harness.json', function (): void {
    it('mutates the whole repository without a mutation key', function (): void {
        $config = HarnessConfig::fromArray(['profile' => 'core']);

        expect($config->mutation->scope)->toBe(MutationSettings::ALL)
            ->and($config->mutation->changedOnly())->toBeFalse()
            ->and($config->mutation->min)->toBeNull()
            ->and($config->toArray())->not->toHaveKey('mutation');
    });

    it('reads the changed scope and its minimum', function (): void {
        $config = HarnessConfig::fromArray(['profile' => 'core', 'mutation' => ['scope' => 'changed', 'min' => 80]]);

        expect($config->mutation->changedOnly())->toBeTrue()
            ->and($config->mutation->min)->toBe(80.0)
            ->and($config->toArray()['mutation'])->toBe(['scope' => 'changed', 'min' => 80]);
    });

    it('keeps a fractional minimum as it is', function (): void {
        $settings = MutationSettings::fromArray(['scope' => 'changed', 'min' => 82.5], 'x');

        expect($settings->toArray())->toBe(['scope' => 'changed', 'min' => 82.5]);
    });

    it('keeps the key when saving and loading the file', function (): void {
        $root = harnessTempDir();
        HarnessConfig::fromArray(['profile' => 'core', 'mutation' => ['scope' => 'changed', 'min' => 75]])->save($root);

        expect(HarnessConfig::load($root)->mutation->min)->toBe(75.0);
    });

    it('is kept when the ratchet is replaced', function (): void {
        $config = HarnessConfig::fromArray(['profile' => 'core', 'mutation' => ['scope' => 'changed', 'min' => 75]]);

        expect($config->withRatchet($config->ratchet)->mutation->min)->toBe(75.0);
    });

    it('reads the added scope, which also limits the run', function (): void {
        $settings = MutationSettings::fromArray(['scope' => 'added', 'min' => 80], 'x');

        expect($settings->addedOnly())->toBeTrue()
            ->and($settings->changedOnly())->toBeFalse()
            ->and($settings->limited())->toBeTrue()
            ->and($settings->toArray())->toBe(['scope' => 'added', 'min' => 80])
            ->and((new MutationSettings)->limited())->toBeFalse();
    });

    it('refuses a scope it does not know', function (): void {
        HarnessConfig::fromArray(['profile' => 'core', 'mutation' => ['scope' => 'diff']], 'x.json');
    })->throws(InvalidConfig::class, 'mutation.scope is "diff"');

    it('refuses a minimum outside 0 to 100', function (mixed $min): void {
        MutationSettings::fromArray(['scope' => 'changed', 'min' => $min], 'x.json');
    })->with([[101], [-1], ['high']])->throws(InvalidConfig::class, 'mutation.min');
});

describe('the source files a change touches', function (): void {
    it('lists the files the last commit added or modified, in the source directories', function (): void {
        $root = scopedRepository([
            'src/New.php' => "<?php\n",
            'src/Old.php' => "<?php // changed\n",
            'src/Sub/Deep.php' => "<?php\n",
        ]);

        expect(ChangedSources::since($root))->toBe(['src/New.php', 'src/Old.php', 'src/Sub/Deep.php']);
    });

    it('leaves out tests, other languages, autoload files, mutation copies and deleted files', function (): void {
        $root = scopedRepository([
            'src/Kept.php' => "<?php\n",
            'src/helpers.php' => "<?php // changed\n",
            'src/notes.md' => 'x',
            'src/.mutant-123-Kept.php' => "<?php\n",
            'tests/KeptTest.php' => "<?php\n",
            'vendor/a/b.php' => "<?php\n",
            'build/generated.php' => "<?php\n",
        ], ['src/Gone.php']);

        expect(ChangedSources::since($root))->toBe(['src/Kept.php']);
    });

    it('lists only the files a change adds when asked, not the ones it modifies', function (): void {
        $root = scopedRepository(['src/New.php' => "<?php\n", 'src/Old.php' => "<?php // changed\n"]);

        expect(ChangedSources::since($root, ChangedSources::DEFAULT_BASE, ChangedSources::ADDED))->toBe(['src/New.php'])
            ->and(ChangedSources::since($root))->toBe(['src/New.php', 'src/Old.php']);
    });

    it('counts the change from the base it is given', function (): void {
        $root = scopedRepository(['src/Second.php' => "<?php\n"]);
        $first = scopeGit($root, ['rev-parse', 'HEAD^1']);

        expect(ChangedSources::since($root, $first))->toBe(['src/Second.php'])
            ->and(ChangedSources::since($root, 'HEAD'))->toBe([]);
    });

    it('is the change of a pull request on the merge commit a CI checks out', function (): void {
        $root = scopedRepository([]);
        scopeGit($root, ['checkout', '-q', '-b', 'feature']);
        scopeWrite($root, ['src/Feature.php' => "<?php\n"]);
        scopeGit($root, ['add', '-A']);
        scopeGit($root, ['commit', '-q', '-m', 'feature']);
        scopeGit($root, ['checkout', '-q', 'main']);
        scopeWrite($root, ['src/Other.php' => "<?php\n"]);
        scopeGit($root, ['add', '-A']);
        scopeGit($root, ['commit', '-q', '-m', 'main moves on']);
        scopeGit($root, ['merge', '-q', '--no-ff', '-m', 'merge', 'feature']);

        expect(ChangedSources::since($root))->toBe(['src/Feature.php']);
    });

    it('says nothing when git cannot', function (string $base): void {
        $root = scopedRepository(['src/New.php' => "<?php\n"]);

        expect(ChangedSources::since($root, $base))->toBeNull();
    })->with(['an unknown base' => ['no-such-ref'], 'an option' => ['--stat'], 'an empty base' => ['']]);

    it('says nothing outside a git repository', function (): void {
        expect(ChangedSources::since(harnessTempDir()))->toBeNull();
    });

    it('reads the source directories of composer.json, src/ by default', function (): void {
        $root = harnessTempDir();
        scopeWrite($root, ['composer.json' => json_encode(['autoload' => ['psr-4' => ['A\\' => 'lib/', 'B\\' => ['app/', 'extra/']], 'classmap' => ['database/'], 'files' => ['./lib/functions.php']]], JSON_THROW_ON_ERROR)]);

        expect(ChangedSources::sourceDirectories($root))->toBe(['lib/', 'app/', 'extra/', 'database/'])
            ->and(ChangedSources::autoloadFiles($root))->toBe(['lib/functions.php'])
            ->and(ChangedSources::sourceDirectories(harnessTempDir()))->toBe(['src/'])
            ->and(ChangedSources::autoloadFiles(harnessTempDir()))->toBe([]);
    });
});

/** What the fake Pest does as the process of one mutant: it loads it, as Pest does. */
const LOAD_MUTANT = "if (getenv('PEST_MUTATION_FILE') !== false) {\n    require getenv('PEST_MUTATION_FILE');\n}\n";

describe('the Pest run limited to some files', function (): void {
    /**
     * A project whose vendor/bin/pest prints its own arguments, then a report.
     *
     * @param  string  $report  What follows the arguments
     */
    function projectEchoingPestArguments(string $report): string
    {
        $root = harnessTempDir();
        mkdir($root.'/vendor/bin', 0o777, true);
        mkdir($root.'/vendor/pestphp/pest-plugin-mutate', 0o777, true);
        scopeWrite($root, ['src/A.php' => "<?php\n", 'src/B.php' => "<?php\n"]);
        file_put_contents($root.'/vendor/bin/pest', "<?php\n".LOAD_MUTANT."fwrite(STDOUT, 'ARGS '.implode(' ', array_slice(\$argv, 1)).\"\\n\");\nfwrite(STDOUT, ".var_export($report, true).");\n");

        return $root;
    }

    it('gives Pest the files, separated by commas', function (): void {
        $root = projectEchoingPestArguments("  Mutations: 1 untested, 3 tested\n  Score:     75.00%\n");

        $run = MutationRun::run($root, ['src/A.php', 'src/B.php']);

        expect($run['output'])->toContain('--path=src/A.php,src/B.php')
            ->and($run['score'])->toBe(75.0);
    });

    it('gives Pest no path when it is not limited', function (): void {
        $root = projectEchoingPestArguments("  Mutations: 1 tested\n  Score:     100.00%\n");

        expect(MutationRun::run($root)['output'])->not->toContain('--path');
    });

    it('has no score, and does not repeat the run, when Pest created no mutation', function (string $report): void {
        $root = projectEchoingPestArguments($report);

        $run = MutationRun::run($root, ['src/CollectionInterface.php']);

        expect($run['empty'])->toBeTrue()
            ->and($run['score'])->toBeNull()
            ->and(substr_count($run['output'], 'ARGS'))->toBe(1);
    })->with([
        'the count' => ["  Mutations: 0 tested\n  Score:     0.00%\n"],
        'the notice' => ["   INFO  No mutations created.\n  Score:     0.00%\n"],
    ]);

    it('lists the mutants that were not killed, without their ids, and at most the limit', function (): void {
        $output = "   UNTESTED  src/A.php  > Line 61: InstanceOfToTrue - ID: 38fe59d773d13833\n  ⨯ Line 12: PlusToMinus\n  ✓ Line 13: Killed\n   UNTESTED  src/A.php  > Line 70: ConcatRemoveLeft - ID: 83f1f22f1af06888\n";

        expect(MutationRun::survivors($output))->toBe([
            'UNTESTED  src/A.php  > Line 61: InstanceOfToTrue',
            '⨯ Line 12: PlusToMinus',
            'UNTESTED  src/A.php  > Line 70: ConcatRemoveLeft',
        ])->and(MutationRun::survivors($output, 2))->toHaveCount(2);
    });
});

describe('the mutation gate of the changed files', function (): void {
    /**
     * The gate of a repository whose last commit changed `src/A.php`, run with a fake Pest.
     *
     * @param  string  $report  What the fake Pest prints
     * @param  array<string, mixed>  $mutation  The `mutation` key of .harness.json
     * @param  array<string, string>  $changes  Files of the last commit
     */
    function changedFilesStep(string $report, array $mutation = ['scope' => 'changed', 'min' => 80], array $changes = ['src/A.php' => "<?php\n"]): Step
    {
        $root = scopedRepository($changes);
        mkdir($root.'/vendor/bin', 0o777, true);
        mkdir($root.'/vendor/pestphp/pest-plugin-mutate', 0o777, true);
        file_put_contents($root.'/vendor/bin/pest', "<?php\n".LOAD_MUTANT.'fwrite(STDOUT, '.var_export($report, true).");\n");
        $steps = (new Plan(HarnessConfig::fromArray(['profile' => 'core', 'mutation' => $mutation]), $root))->full(null, null, true);

        return array_values(array_filter($steps, static fn (Step $step): bool => str_starts_with($step->name, 'Mutation testing')))[0];
    }

    it('replaces the whole-repository gate, and is not observed by default', function (): void {
        $step = changedFilesStep('');

        expect($step->name)->toBe(Plan::MUTATION_CHANGED)
            ->and(Plan::OBSERVED_BY_DEFAULT)->not->toContain(Plan::MUTATION_CHANGED);
    });

    it('passes at or above the minimum', function (string $score): void {
        $result = (changedFilesStep("  Mutations: 2 untested, 8 tested\n  Score:     {$score}%\n")->action)();

        expect($result->status)->toBe(StepResult::PASSED)
            ->and($result->detail)->toContain('1 changed file')
            ->and($result->detail)->toContain('minimum 80');
    })->with(['80.00', '93.10', '100.00']);

    it('fails below the minimum and lists what survived', function (): void {
        $result = (changedFilesStep("   UNTESTED  src/A.php  > Line 61: InstanceOfToTrue - ID: 38fe59d773d13833\n  Mutations: 8 untested, 2 tested\n  Score:     20.00%\n")->action)();

        expect($result->status)->toBe(StepResult::FAILED)
            ->and($result->detail)->toContain('below the minimum of 80')
            ->and($result->detail)->toContain('whole files')
            ->and($result->detail)->toContain('Line 61: InstanceOfToTrue');
    });

    it('only reports the score when no minimum is set', function (): void {
        $result = (changedFilesStep("  Mutations: 8 untested, 2 tested\n  Score:     20.00%\n", ['scope' => 'changed'])->action)();

        expect($result->status)->toBe(StepResult::PASSED)
            ->and($result->detail)->toContain('no minimum set');
    });

    it('passes when the change touches no source file', function (): void {
        $result = (changedFilesStep('', changes: ['README.md' => 'x', 'tests/ATest.php' => "<?php\n"])->action)();

        expect($result->status)->toBe(StepResult::PASSED)
            ->and($result->detail)->toContain('nothing to mutate');
    });

    it('passes when Pest creates no mutation in the changed files', function (): void {
        $result = (changedFilesStep("  Mutations: 0 tested\n  Score:     0.00%\n   INFO  No mutations created.\n")->action)();

        expect($result->status)->toBe(StepResult::PASSED)
            ->and($result->detail)->toContain('nothing to measure');
    });

    it('fails when the tests fail, rather than pass without a score', function (): void {
        $result = (changedFilesStep("  Tests:    2 failed, 1 passed\n")->action)();

        expect($result->status)->toBe(StepResult::FAILED)
            ->and($result->detail)->toContain('the tests must pass first');
    });

    it('fails, saying what to do, when the changed files cannot be listed', function (): void {
        $root = harnessTempDir();
        $steps = (new Plan(HarnessConfig::fromArray(['profile' => 'core', 'mutation' => ['scope' => 'changed', 'min' => 80]]), $root))->full(null, null, true);
        $step = array_values(array_filter($steps, static fn (Step $step): bool => $step->name === Plan::MUTATION_CHANGED))[0];

        $result = ($step->action)();

        expect($result->status)->toBe(StepResult::FAILED)
            ->and($result->detail)->toContain('fetch-depth: 2')
            ->and($result->detail)->toContain('--mutation-base');
    });

    it('counts the change from the base given to the plan', function (): void {
        $root = scopedRepository(['src/A.php' => "<?php\n"]);
        mkdir($root.'/vendor/bin', 0o777, true);
        mkdir($root.'/vendor/pestphp/pest-plugin-mutate', 0o777, true);
        file_put_contents($root.'/vendor/bin/pest', "<?php\n".LOAD_MUTANT."fwrite(STDOUT, \"  Mutations: 1 tested\\n  Score:     100.00%\\n\");\n");
        $steps = (new Plan(HarnessConfig::fromArray(['profile' => 'core', 'mutation' => ['scope' => 'changed', 'min' => 80]]), $root))->full(null, null, true, 'HEAD');
        $step = array_values(array_filter($steps, static fn (Step $step): bool => $step->name === Plan::MUTATION_CHANGED))[0];

        expect((($step->action)())->detail)->toContain('No source file changed since HEAD');
    });

    it('holds only the new files to the minimum, and says how many modified files it did not mutate', function (): void {
        $root = scopedRepository(['src/New.php' => "<?php\n", 'src/Old.php' => "<?php // changed\n"]);
        mkdir($root.'/vendor/bin', 0o777, true);
        mkdir($root.'/vendor/pestphp/pest-plugin-mutate', 0o777, true);
        file_put_contents($root.'/vendor/bin/pest', "<?php\n".LOAD_MUTANT."fwrite(STDOUT, 'ARGS '.implode(' ', array_slice(\$argv, 1)).\"\\n\");\nfwrite(STDOUT, \"  Mutations: 1 untested, 9 tested\\n  Score:     90.00%\\n\");\n");
        $steps = (new Plan(HarnessConfig::fromArray(['profile' => 'core', 'mutation' => ['scope' => 'added', 'min' => 80]]), $root))->full(null, null, true);
        $step = array_values(array_filter($steps, static fn (Step $step): bool => str_starts_with($step->name, 'Mutation testing')))[0];

        $result = ($step->action)();

        expect($step->name)->toBe(Plan::MUTATION_ADDED)
            ->and(Plan::OBSERVED_BY_DEFAULT)->not->toContain(Plan::MUTATION_ADDED)
            ->and($result->status)->toBe(StepResult::PASSED)
            ->and($result->detail)->toContain('1 new file')
            ->and($result->detail)->toContain('1 modified file not mutated');
    });

    it('passes without running Pest when the change adds no source file', function (): void {
        $root = scopedRepository(['src/Old.php' => "<?php // changed\n"]);
        $steps = (new Plan(HarnessConfig::fromArray(['profile' => 'core', 'mutation' => ['scope' => 'added', 'min' => 80]]), $root))->full(null, null, true);
        $step = array_values(array_filter($steps, static fn (Step $step): bool => $step->name === Plan::MUTATION_ADDED))[0];

        $result = ($step->action)();

        expect($result->status)->toBe(StepResult::PASSED)
            ->and($result->detail)->toContain('No new source file added')
            ->and($result->detail)->toContain('1 modified file not mutated');
    });

    it('does not feed the ratchet with the score of some files', function (): void {
        $step = changedFilesStep("  Mutations: 1 tested\n  Score:     100.00%\n");
        ($step->action)();

        $root = scopedRepository(['src/A.php' => "<?php\n"]);
        $config = HarnessConfig::fromArray(['profile' => 'core', 'mutation' => ['scope' => 'changed', 'min' => 80], 'ratchet' => ['msi' => 95]]);
        $names = array_map(static fn (Step $step): string => $step->name, (new Plan($config, $root))->full(null, null, true));

        expect($names)->toContain(Plan::MUTATION_CHANGED)
            ->and($names)->not->toContain(Plan::MUTATION);
    });
});

it('fails the gate of the new files when Pest cannot write its temporary files, instead of reading 100 %', function (): void {
    $root = scopedRepository(['src/New.php' => "<?php\n"]);
    mkdir($root.'/vendor/bin', 0o777, true);
    mkdir($root.'/vendor/pestphp/pest-plugin-mutate', 0o777, true);
    $output = "Warning: fopen(/app/vendor/pestphp/pest-plugin-mutate/src/../.temp/mutations/225429bccf85eb2c): Failed to open stream: No such file or directory\n  Mutations: 5 tested\n  Score:     100.00%\n";
    file_put_contents($root.'/vendor/bin/pest', "<?php\n".LOAD_MUTANT.'fwrite(STDOUT, '.var_export($output, true).");\n");
    $steps = (new Plan(HarnessConfig::fromArray(['profile' => 'core', 'mutation' => ['scope' => 'added', 'min' => 80]]), $root))->full(null, null, true);
    $step = array_values(array_filter($steps, static fn (Step $step): bool => $step->name === Plan::MUTATION_ADDED))[0];

    $result = ($step->action)();

    expect($result->status)->toBe(StepResult::FAILED)
        ->and($result->detail)->toContain('would read 100 % whatever the tests assert');
});

describe('the mutation scope a run chooses on the command line', function (): void {
    /**
     * The mutation step of a plan built the way `check --mutation-scope=` builds it, with a fake Pest.
     *
     * @param  string|null  $scope  The `--mutation-scope` value, null when the option is not given
     * @param  array<string, mixed>|null  $mutation  The `mutation` key of .harness.json
     * @param  array<string, string>  $changes  Files of the last commit
     */
    function stepChosenBy(?string $scope, ?array $mutation, string $report = "  Mutations: 2 untested, 8 tested\n  Score:     80.00%\n", array $changes = ['src/A.php' => "<?php\n"], ?string $base = null): Step
    {
        $root = scopedRepository($changes);
        mkdir($root.'/vendor/bin', 0o777, true);
        mkdir($root.'/vendor/pestphp/pest-plugin-mutate', 0o777, true);
        file_put_contents($root.'/vendor/bin/pest', "<?php\n".LOAD_MUTANT.'fwrite(STDOUT, '.var_export($report, true).");\n");
        $config = HarnessConfig::fromArray(['profile' => 'core', 'mutation' => $mutation, 'ratchet' => ['msi' => 90]]);

        if ($scope !== null) {
            $config = $config->withMutation($config->mutation->withScope($scope));
        }

        $steps = (new Plan($config, $root))->full(null, null, true, $base);

        return array_values(array_filter($steps, static fn (Step $step): bool => str_starts_with($step->name, 'Mutation testing')))[0];
    }

    it('wins over the file, which wins over the default', function (?string $scope, ?array $file, string $step): void {
        expect(stepChosenBy($scope, $file)->name)->toBe($step);
    })->with([
        'no option, no key: the whole repository' => [null, null, Plan::MUTATION],
        'no option, the file says added' => [null, ['scope' => 'added', 'min' => 70], Plan::MUTATION_ADDED],
        'no option, the file says changed' => [null, ['scope' => 'changed'], Plan::MUTATION_CHANGED],
        'changed over no key' => ['changed', null, Plan::MUTATION_CHANGED],
        'changed over a file that says all' => ['changed', ['scope' => 'all'], Plan::MUTATION_CHANGED],
        'all over a file that says changed' => ['all', ['scope' => 'changed', 'min' => 80], Plan::MUTATION],
        'added over a file that says changed' => ['added', ['scope' => 'changed', 'min' => 80], Plan::MUTATION_ADDED],
    ]);

    it('holds a limited run to mutation.min when the file sets one, and to 50 % when it does not', function (): void {
        $kept = (new MutationSettings('all', 85.0))->withScope('changed');
        $fallback = (new MutationSettings)->withScope('changed');

        expect($kept->min)->toBe(85.0)
            ->and($fallback->min)->toBe(MutationSettings::PULL_REQUEST_MINIMUM)
            ->and(MutationSettings::PULL_REQUEST_MINIMUM)->toBe(50.0)
            ->and((new MutationSettings)->withScope('all')->min)->toBeNull();
    });

    it('fails a changed file under the fallback minimum, and passes one at it', function (): void {
        $below = (stepChosenBy('changed', null, "  Mutations: 6 untested, 4 tested\n  Score:     40.00%\n")->action)();
        $at = (stepChosenBy('changed', null, "  Mutations: 5 untested, 5 tested\n  Score:     50.00%\n")->action)();

        expect($below->status)->toBe(StepResult::FAILED)->and($below->detail)->toContain('minimum of 50')
            ->and($at->status)->toBe(StepResult::PASSED);
    });

    it('passes, saying there is nothing to mutate, when the pull request changes no PHP source file', function (): void {
        $result = (stepChosenBy('changed', null, '', ['README.md' => "x\n", 'tests/AThing.php' => "<?php\n"])->action)();

        expect($result->status)->toBe(StepResult::PASSED)
            ->and($result->detail)->toContain('nothing to mutate')
            ->not->toContain('unmeasured');
    });

    it('fails, saying what to do, when the base is not in the checkout, instead of passing', function (): void {
        $result = (stepChosenBy('changed', null, '', base: 'origin/no-such-branch')->action)();

        expect($result->status)->toBe(StepResult::FAILED)
            ->and($result->detail)->toContain('origin/no-such-branch', 'fetch-depth: 2', '--mutation-base');
    });

    it('mutates the whole repository, as before, without the option', function (): void {
        $step = stepChosenBy(null, null);

        expect($step->name)->toBe(Plan::MUTATION)
            ->and(Plan::OBSERVED_BY_DEFAULT)->toContain(Plan::MUTATION)
            ->and((($step->action)())->detail)->toContain('Mutation score 80');
    });

    it('says the msi of the ratchet is not measured on purpose when the run is limited', function (): void {
        $root = scopedRepository(['src/A.php' => "<?php\n"]);
        mkdir($root.'/vendor/bin', 0o777, true);
        mkdir($root.'/vendor/pestphp/pest-plugin-mutate', 0o777, true);
        file_put_contents($root.'/vendor/bin/pest', "<?php\n".LOAD_MUTANT."fwrite(STDOUT, \"  Mutations: 1 tested\\n  Score:     100.00%\\n\");\n");
        $config = HarnessConfig::fromArray(['profile' => 'core', 'ratchet' => ['msi' => 90]]);
        $limited = array_values(array_filter((new Plan($config->withMutation($config->mutation->withScope('changed')), $root))->full(null, null, true), static fn (Step $step): bool => $step->name === 'Ratchet'))[0];
        $whole = array_values(array_filter((new Plan($config, $root))->full(null, null, false), static fn (Step $step): bool => $step->name === 'Ratchet'))[0];

        expect((($limited->action)())->detail)->toContain('mutated only the files of the change')->not->toContain('Infection')
            ->and((($whole->action)())->detail)->toContain('no Infection log');
    });

    it('refuses a scope it does not know, or an option without value, before running any gate', function (string ...$arguments): void {
        $cwd = harnessTempDir();
        file_put_contents($cwd.'/.harness.json', '{"profile": "core"}');

        [$code, $output, $errors] = harnessRun(new CheckCommand, $cwd, ...$arguments);

        expect($code)->toBe(1)->and($errors)->toContain('--mutation-scope')->and($output)->not->toContain('Pollora harness ·');
    })->with([
        'unknown value' => ['--mutation-scope=everything'],
        'no value' => ['--mutation-scope'],
    ]);

    it('knows the three scopes', function (): void {
        expect(MutationSettings::isScope('all'))->toBeTrue()
            ->and(MutationSettings::isScope('changed'))->toBeTrue()
            ->and(MutationSettings::isScope('added'))->toBeTrue()
            ->and(MutationSettings::isScope('CHANGED'))->toBeFalse()
            ->and(MutationSettings::isScope(''))->toBeFalse();
    });
});
