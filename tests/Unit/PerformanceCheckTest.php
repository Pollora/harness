<?php

declare(strict_types=1);

use Pollora\Harness\Check\Plan;
use Pollora\Harness\Check\StepResult;
use Pollora\Harness\Config\HarnessConfig;
use Pollora\Harness\Config\InvalidConfig;
use Pollora\Harness\Config\PerformanceSettings;
use Pollora\Harness\Performance\Budgets;
use Pollora\Harness\Performance\NightlyMeasures;
use Pollora\Harness\Performance\PerformanceCheck;

if (! defined('PERF_BUDGETS')) {
    define('PERF_BUDGETS', '{"pages":{"home":{"wordpress":24,"laravel":0},"rest":{"wordpress":19,"laravel":null}}}');
}

if (! function_exists('perfWorkflow')) {
    /**
     * A scheduled workflow shaped like the framework's: measures, compares, caches the reference.
     */
    function perfWorkflow(string $compare = 'node bin/perf-compare.ts "$RUNNER_TEMP/current.json" --tolerance=0.1', string $trigger = "on:\n  push:\n    branches: [main]\n  schedule:\n    - cron: '30 3 * * *'\n"): string
    {
        return "name: CI\n{$trigger}\njobs:\n  tests:\n    steps:\n"
            ."      - name: Measure perf-metrics\n        if: github.event_name == 'schedule'\n        env:\n          E2E_PERF_OUT: /tmp/perf-metrics.json\n        run: npx playwright test perf-metrics\n"
            ."      - name: Compare with perf-compare\n        run: {$compare}\n"
            ."      - uses: actions/upload-artifact@v7\n        with:\n          name: perf-metrics\n          path: /tmp/perf-metrics.json # perf-compare\n";
    }

}

if (! function_exists('perfRepository')) {
    /**
     * @param  array<string, string>  $files
     */
    function perfRepository(array $files): string
    {
        $root = harnessTempDir();

        foreach ($files as $path => $contents) {
            if (! is_dir(dirname($root.'/'.$path))) {
                mkdir(dirname($root.'/'.$path), 0o755, true);
            }

            file_put_contents($root.'/'.$path, $contents);
        }

        return $root;
    }

}

if (! function_exists('perfFiles')) {
    /**
     * The files of a repository that measures nightly and keeps budgets, framework layout.
     *
     * @return array<string, string>
     */
    function perfFiles(?string $workflow = null, string $budgets = PERF_BUDGETS): array
    {
        return [
            'tests/e2e/budgets.json' => $budgets,
            'tests/e2e/specs/perf-metrics.spec.ts' => '// measures',
            'tests/e2e/bin/perf-compare.ts' => '// compares',
            '.github/workflows/ci.yml' => $workflow ?? perfWorkflow(),
        ];
    }

}

if (! function_exists('perfGit')) {
    function perfGit(string $root, string ...$arguments): string
    {
        $process = proc_open(['git', '-c', 'user.name=t', '-c', 'user.email=t@example.test', '-c', 'commit.gpgsign=false', ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return $output;
    }

}

if (! function_exists('perfTwoCommits')) {
    /**
     * A repository whose first commit holds `$before` as the budgets file and whose second holds `$after`.
     */
    function perfTwoCommits(string $before, string $after): string
    {
        $root = harnessGitRepository(['tests/e2e/budgets.json' => $before]);
        file_put_contents($root.'/tests/e2e/budgets.json', $after);
        perfGit($root, 'commit', '-q', '-am', 'change the budgets');

        return $root;
    }

}

describe('the budgets file', function (): void {
    it('reads pages, engines, whole numbers and null', function (): void {
        $budgets = Budgets::parse(PERF_BUDGETS);

        expect($budgets)->toBeInstanceOf(Budgets::class)
            ->and($budgets->pages)->toBe(['home' => ['wordpress' => 24, 'laravel' => 0], 'rest' => ['wordpress' => 19, 'laravel' => null]])
            ->and($budgets->set())->toBe(3)
            ->and($budgets->unset())->toBe(['rest.laravel']);
    });

    it('refuses a file that is not a usable budgets file', function (string $json, string $problem): void {
        $problems = Budgets::parse($json);

        expect($problems)->toBeArray()->and(implode(' ', $problems))->toContain($problem);
    })->with([
        'not JSON' => ['{nope', 'not valid JSON'],
        'a list' => ['[1]', '"pages" must be'],
        'no pages' => ['{"other":1}', '"pages" must be'],
        'empty pages' => ['{"pages":{}}', '"pages" must be'],
        'pages as a list' => ['{"pages":[{"wordpress":1}]}', '"pages" must be'],
        'a page that is not an object' => ['{"pages":{"home":24}}', 'page "home" must be'],
        'a page with no engine' => ['{"pages":{"home":{}}}', 'page "home" must be'],
        'a negative budget' => ['{"pages":{"home":{"wordpress":-1}}}', 'home.wordpress is -1'],
        'a fractional budget' => ['{"pages":{"home":{"wordpress":1.5}}}', 'home.wordpress is 1.5'],
        'a string budget' => ['{"pages":{"home":{"wordpress":"24"}}}', "home.wordpress is '24'"],
        'a list budget' => ['{"pages":{"home":{"wordpress":[1]}}}', 'home.wordpress is array'],
    ]);

    it('reports every problem, not the first', function (): void {
        $problems = Budgets::parse('{"pages":{"a":{"x":-1},"b":{"y":"z"}}}');

        expect($problems)->toHaveCount(2);
    });

    it('finds the budgets a change loosens', function (string $after, array $expected): void {
        $before = Budgets::parse('{"pages":{"home":{"wordpress":24,"laravel":0},"rest":{"wordpress":null}}}');
        $now = Budgets::parse($after);

        expect($now->loosenedSince($before))->toBe($expected);
    })->with([
        'nothing changed' => ['{"pages":{"home":{"wordpress":24,"laravel":0},"rest":{"wordpress":null}}}', []],
        'a budget lowered' => ['{"pages":{"home":{"wordpress":20,"laravel":0},"rest":{"wordpress":null}}}', []],
        'a null that gets a budget' => ['{"pages":{"home":{"wordpress":24,"laravel":0},"rest":{"wordpress":50}}}', []],
        'a budget added' => ['{"pages":{"home":{"wordpress":24,"laravel":0,"extra":1},"rest":{"wordpress":null},"new":{"wordpress":5}}}', []],
        'a budget raised' => ['{"pages":{"home":{"wordpress":25,"laravel":0},"rest":{"wordpress":null}}}', ['home.wordpress: raised from 24 to 25']],
        'a zero budget raised' => ['{"pages":{"home":{"wordpress":24,"laravel":1},"rest":{"wordpress":null}}}', ['home.laravel: raised from 0 to 1']],
        'a budget turned into null' => ['{"pages":{"home":{"wordpress":null,"laravel":0},"rest":{"wordpress":null}}}', ['home.wordpress: set to null, no budget any more (was 24)']],
        'an engine removed' => ['{"pages":{"home":{"wordpress":24},"rest":{"wordpress":null}}}', ['home.laravel: removed (was 0)']],
        'a page removed' => ['{"pages":{"rest":{"wordpress":null}}}', ['home.wordpress: removed (was 24)', 'home.laravel: removed (was 0)']],
    ]);
});

describe('the settings', function (): void {
    it('has defaults and writes nothing when nothing is set', function (): void {
        $settings = PerformanceSettings::fromArray(null, '.harness.json');

        expect($settings->budgetsPath())->toBe('tests/e2e/budgets.json')
            ->and($settings->measurePath())->toBe('tests/e2e/specs/perf-metrics.spec.ts')
            ->and($settings->comparePath())->toBe('tests/e2e/bin/perf-compare.ts')
            ->and($settings->declaresBudgets())->toBeFalse()
            ->and($settings->nightlyDeclared)->toBeFalse()
            ->and($settings->toArray())->toBeNull();
    });

    it('round-trips through .harness.json, an empty nightly included', function (): void {
        $config = HarnessConfig::fromArray(['profile' => 'core', 'performance' => ['budgets' => 'perf/budgets.json', 'nightly' => []]]);
        $json = json_encode($config->toArray(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        expect($config->performance->budgetsPath())->toBe('perf/budgets.json')
            ->and($config->performance->nightlyDeclared)->toBeTrue()
            ->and($json)->toContain('"performance":{"budgets":"perf/budgets.json","nightly":{}}')
            ->and(HarnessConfig::fromArray(json_decode($json, true, 512, JSON_THROW_ON_ERROR))->performance)->toEqual($config->performance)
            ->and($config->withRatchet($config->ratchet)->performance)->toBe($config->performance);
    });

    it('reads every nightly path', function (): void {
        $settings = PerformanceSettings::fromArray(['nightly' => ['measure' => 'a.ts', 'compare' => 'b.ts', 'workflow' => '.github/workflows/n.yml']], '.harness.json');

        expect($settings->measurePath())->toBe('a.ts')
            ->and($settings->comparePath())->toBe('b.ts')
            ->and($settings->workflow)->toBe('.github/workflows/n.yml')
            ->and($settings->toArray())->toBe(['nightly' => ['measure' => 'a.ts', 'compare' => 'b.ts', 'workflow' => '.github/workflows/n.yml']]);
    });

    it('rejects what is not a safe path or an object', function (mixed $value): void {
        expect(fn () => HarnessConfig::fromArray(['profile' => 'core', 'performance' => $value]))->toThrow(InvalidConfig::class, 'performance is malformed');
    })->with([
        'a string' => ['budgets.json'],
        'a list' => [['a']],
        'an absolute path' => [['budgets' => '/etc/passwd']],
        'a parent path' => [['budgets' => '../budgets.json']],
        'an option for git' => [['budgets' => '--output=x']],
        'a number' => [['budgets' => 3]],
        'an empty path' => [['budgets' => '']],
        'a nightly string' => [['nightly' => 'yes']],
        'a nightly list' => [['nightly' => ['a']]],
        'a nightly path outside' => [['nightly' => ['measure' => 'a/../../b']]],
    ]);
});

describe('the nightly measures', function (): void {
    it('finds a schedule with a cron', function (string $workflow, bool $expected): void {
        expect(NightlyMeasures::hasSchedule($workflow))->toBe($expected);
    })->with([
        'the framework shape' => ["on:\n  push:\n  schedule:\n    - cron: '30 3 * * *'\n", true],
        'a quoted on key' => ["\"on\":\n  schedule:\n    - cron: '0 1 * * *'\n", true],
        'a schedule at the end' => ["name: x\non:\n  schedule:\n    - cron: '0 1 * * *'\njobs: {}\n", true],
        'push only' => ["on:\n  push:\n    branches: [main]\n", false],
        'an inline list has no cron' => ["on: [push, schedule]\n", false],
        'schedule commented out' => ["on:\n  push:\n  # schedule:\n  #   - cron: '0 1 * * *'\n", false],
        'a schedule key outside on' => ["on:\n  push:\njobs:\n  schedule:\n    - cron: '0 1 * * *'\n", false],
        'a schedule without a cron' => ["on:\n  schedule:\njobs:\n  a:\n    cron: x\n", false],
        'cron of another trigger' => ["on:\n  schedule:\n  push:\n    cron: x\n", false],
    ]);

    it('tells what runs a script from what only names its output', function (): void {
        $lines = NightlyMeasures::runs(perfWorkflow(), 'perf-metrics');

        expect($lines)->toBe(['        run: npx playwright test perf-metrics']);
    });

    it('does not take a longer name for the script', function (): void {
        expect(NightlyMeasures::runs("        run: node perf-metrics-old.ts\n        run: node my-perf-metrics\n", 'perf-metrics'))->toBe([]);
    });

    it('passes on the framework layout and says what it found', function (): void {
        $root = perfRepository(perfFiles());
        $result = NightlyMeasures::check($root, new PerformanceSettings);

        expect($result['problems'])->toBe([])
            ->and($result['facts'])->toBe(['.github/workflows/ci.yml is scheduled and runs perf-metrics', 'the comparison alerts above 10 %']);
    });

    it('fails on each missing piece', function (array $files, string $problem): void {
        $result = NightlyMeasures::check(perfRepository($files), new PerformanceSettings);

        expect(implode("\n", $result['problems']))->toContain($problem);
    })->with([
        'no measure file' => [array_diff_key(perfFiles(), ['tests/e2e/specs/perf-metrics.spec.ts' => 1]), 'the nightly measure file tests/e2e/specs/perf-metrics.spec.ts does not exist'],
        'no compare file' => [array_diff_key(perfFiles(), ['tests/e2e/bin/perf-compare.ts' => 1]), 'the nightly compare file tests/e2e/bin/perf-compare.ts does not exist'],
        'no workflow at all' => [array_diff_key(perfFiles(), ['.github/workflows/ci.yml' => 1]), 'no workflow in .github/workflows'],
        'no schedule' => [perfFiles(perfWorkflow(trigger: "on:\n  push:\n")), 'no workflow has a "schedule" trigger'],
        'a schedule that runs something else' => [perfFiles("on:\n  schedule:\n    - cron: '0 1 * * *'\njobs:\n  a:\n    steps:\n      - run: echo hi\n"), 'no scheduled workflow (.github/workflows/ci.yml) runs perf-metrics'],
        'no comparison' => [perfFiles("on:\n  schedule:\n    - cron: '0 1 * * *'\njobs:\n  a:\n    steps:\n      - run: npx playwright test perf-metrics\n"), 'never runs perf-compare'],
        'a tolerance above 10 %' => [perfFiles(perfWorkflow('node bin/perf-compare.ts a.json --tolerance=0.25')), 'a tolerance of 25 %, above the 10 % PERF-07 allows'],
    ]);

    it('measures in one workflow and schedules in another: not a pass', function (): void {
        $files = perfFiles(perfWorkflow(trigger: "on:\n  push:\n"));
        $files['.github/workflows/nightly.yml'] = "on:\n  schedule:\n    - cron: '0 1 * * *'\njobs:\n  a:\n    steps:\n      - run: echo hi\n";

        expect(NightlyMeasures::check(perfRepository($files), new PerformanceSettings)['problems'])->not->toBe([]);
    });

    it('accepts a tolerance below 10 % and says when none is given', function (): void {
        $strict = NightlyMeasures::check(perfRepository(perfFiles(perfWorkflow('node bin/perf-compare.ts a.json --tolerance 0.05'))), new PerformanceSettings);
        $none = NightlyMeasures::check(perfRepository(perfFiles(perfWorkflow('node bin/perf-compare.ts a.json'))), new PerformanceSettings);

        expect($strict['problems'])->toBe([])->and($strict['facts'][1])->toBe('the comparison alerts above 5 %')
            ->and($none['problems'])->toBe([])->and($none['facts'][1])->toContain('without --tolerance')->toContain('not verified');
    });

    it('reads only the workflow it is told to read, and says when it is absent', function (): void {
        $files = perfFiles();
        $files['.github/workflows/other.yml'] = perfWorkflow(trigger: "on:\n  push:\n");
        $root = perfRepository($files);

        expect(NightlyMeasures::check($root, new PerformanceSettings(workflow: '.github/workflows/other.yml'))['problems'])->toContain('no workflow has a "schedule" trigger (on: schedule: - cron), so nothing measures nightly')
            ->and(NightlyMeasures::check($root, new PerformanceSettings(workflow: '.github/workflows/gone.yml'))['problems'])->toContain('the workflow .github/workflows/gone.yml does not exist');
    });

    it('reads .yaml workflows too', function (): void {
        $files = perfFiles();
        $files['.github/workflows/nightly.yaml'] = $files['.github/workflows/ci.yml'];
        unset($files['.github/workflows/ci.yml']);

        expect(NightlyMeasures::check(perfRepository($files), new PerformanceSettings)['problems'])->toBe([]);
    });
});

describe('the step', function (): void {
    it('applies to a budgets file at the default path, a named one, or declared nightly measures', function (): void {
        $none = new PerformanceCheck(perfRepository([]), new PerformanceSettings);
        $budgets = new PerformanceCheck(perfRepository(['tests/e2e/budgets.json' => PERF_BUDGETS]), new PerformanceSettings);
        $named = new PerformanceCheck(perfRepository([]), new PerformanceSettings(budgets: 'x.json'));
        $nightly = new PerformanceCheck(perfRepository([]), new PerformanceSettings(nightlyDeclared: true));
        $measure = new PerformanceCheck(perfRepository(['tests/e2e/specs/perf-metrics.spec.ts' => '']), new PerformanceSettings);

        expect($none->skipReason())->toContain('no budgets file (tests/e2e/budgets.json) and no nightly measures')
            ->and([$budgets->skipReason(), $named->skipReason(), $nightly->skipReason(), $measure->skipReason()])->toBe([null, null, null, null]);
    });

    it('passes on a valid budgets file and says what it did not compare', function (): void {
        $result = (new PerformanceCheck(perfRepository(perfFiles()), new PerformanceSettings))->run();

        expect($result->status)->toBe(StepResult::PASSED)
            ->and($result->detail)->toContain('PERF-01: 3 budgets set across 2 page types in tests/e2e/budgets.json')
            ->and($result->detail)->toContain('PERF-01: 1 without a budget (null, measured only): rest.laravel')
            ->and($result->detail)->toContain('PERF-01: not compared with HEAD^1: git cannot say')
            ->and($result->detail)->toContain('PERF-07: .github/workflows/ci.yml is scheduled and runs perf-metrics');
    });

    it('says which half it did not evaluate', function (): void {
        $budgetsOnly = (new PerformanceCheck(perfRepository(['tests/e2e/budgets.json' => PERF_BUDGETS]), new PerformanceSettings))->run();
        $nightlyOnly = (new PerformanceCheck(perfRepository(array_diff_key(perfFiles(), ['tests/e2e/budgets.json' => 1])), new PerformanceSettings))->run();

        expect($budgetsOnly->detail)->toContain('PERF-07 not declared')
            ->and($nightlyOnly->detail)->toContain('PERF-01 not declared: no tests/e2e/budgets.json');
    });

    it('fails on a declared budgets file that is missing or invalid', function (): void {
        $missing = (new PerformanceCheck(perfRepository([]), new PerformanceSettings(budgets: 'gone.json')))->run();
        $invalid = (new PerformanceCheck(perfRepository(['tests/e2e/budgets.json' => '{"pages":{"a":{"x":-2}}}']), new PerformanceSettings))->run();

        expect($missing->status)->toBe(StepResult::FAILED)->and($missing->detail)->toContain('gone.json is declared but does not exist')
            ->and($invalid->status)->toBe(StepResult::FAILED)->and($invalid->detail)->toContain('tests/e2e/budgets.json: a.x is -2');
    });

    it('fails a budget that went up since the base commit', function (): void {
        $root = perfTwoCommits('{"pages":{"home":{"wordpress":24}}}', '{"pages":{"home":{"wordpress":25}}}');
        $result = (new PerformanceCheck($root, new PerformanceSettings))->run();

        expect($result->status)->toBe(StepResult::FAILED)
            ->and($result->detail)->toContain('1 budget loosened since HEAD^1')
            ->and($result->detail)->toContain('home.wordpress: raised from 24 to 25');
    });

    it('passes a budget that went down and says it compared', function (): void {
        $root = perfTwoCommits('{"pages":{"home":{"wordpress":24}}}', '{"pages":{"home":{"wordpress":20}}}');
        $result = (new PerformanceCheck($root, new PerformanceSettings))->run();

        expect($result->status)->toBe(StepResult::PASSED)->and($result->detail)->toContain('compared with HEAD^1: no budget raised or dropped');
    });

    it('compares with the base it is given', function (): void {
        $root = perfTwoCommits('{"pages":{"home":{"wordpress":24}}}', '{"pages":{"home":{"wordpress":25}}}');
        $same = (new PerformanceCheck($root, new PerformanceSettings, 'HEAD'))->run();

        expect($same->status)->toBe(StepResult::PASSED)->and($same->detail)->toContain('compared with HEAD:');
    });

    it('never reports a comparison it could not make', function (string $case): void {
        $root = match ($case) {
            'a base that is not in the checkout' => harnessGitRepository(['tests/e2e/budgets.json' => PERF_BUDGETS]),
            'a file new since the base' => (function (): string {
                $root = harnessGitRepository(['README.md' => 'x']);
                mkdir($root.'/tests/e2e', 0o755, true);
                file_put_contents($root.'/tests/e2e/budgets.json', PERF_BUDGETS);
                perfGit($root, 'add', '-A');
                perfGit($root, 'commit', '-q', '-m', 'add the budgets');

                return $root;
            })(),
            'a base file that is unreadable' => perfTwoCommits('not json', PERF_BUDGETS),
            default => perfRepository(['tests/e2e/budgets.json' => PERF_BUDGETS]),
        };

        $result = (new PerformanceCheck($root, new PerformanceSettings))->run();

        expect($result->status)->toBe(StepResult::PASSED)->and($result->detail)->toContain('not compared with HEAD^1')->not->toContain('compared with HEAD^1: no budget');
    })->with(['a base that is not in the checkout', 'a file new since the base', 'a base file that is unreadable', 'a directory without git']);

    it('refuses a base that git could take for an option', function (): void {
        $root = perfTwoCommits('{"pages":{"home":{"wordpress":24}}}', '{"pages":{"home":{"wordpress":25}}}');

        expect((new PerformanceCheck($root, new PerformanceSettings, '--output=x'))->run()->detail)->toContain('not compared with --output=x')
            ->and((new PerformanceCheck($root, new PerformanceSettings, ''))->run()->detail)->toContain('not compared');
    });
});

describe('the plan', function (): void {
    it('is skipped with its reason, never passed, in a repository with no budgets', function (): void {
        $step = perfPlanStep(perfRepository([]));

        expect($step->skipReason)->toContain('nothing to check')->and($step->rules)->toBe(['PERF-01', 'PERF-07']);
    });

    it('runs where budgets exist and takes the base from the option', function (): void {
        $root = perfTwoCommits('{"pages":{"home":{"wordpress":24}}}', '{"pages":{"home":{"wordpress":25}}}');
        $step = perfPlanStep($root, 'HEAD');

        expect($step->skipReason)->toBeNull()->and($step->action)->toBeInstanceOf(Closure::class)
            ->and(($step->action)()->detail)->toContain('compared with HEAD:');
    });

    it('is observed by default', function (): void {
        expect(Plan::OBSERVED_BY_DEFAULT)->toContain(Plan::PERFORMANCE);
    });

    it('is in the full plan only, once', function (): void {
        $plan = new Plan(HarnessConfig::fromArray(['profile' => 'core']), perfRepository([]));
        $names = array_map(static fn ($step): string => $step->name, $plan->full());

        expect(array_count_values($names)[Plan::PERFORMANCE])->toBe(1)
            ->and(array_map(static fn ($step): string => $step->name, $plan->fast([])))->not->toContain(Plan::PERFORMANCE);
    });
});

if (! function_exists('perfPlanStep')) {
    function perfPlanStep(string $cwd, ?string $base = null): object
    {
        $plan = new Plan(HarnessConfig::fromArray(['profile' => 'core']), $cwd);

        return array_values(array_filter($plan->full(budgetsBase: $base), static fn ($step): bool => $step->name === Plan::PERFORMANCE))[0];
    }

}
