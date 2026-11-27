<?php

declare(strict_types=1);

use Pollora\Harness\Check\MutationCanary;
use Pollora\Harness\Check\MutationMeasure;
use Pollora\Harness\Check\MutationRun;
use Pollora\Harness\Check\Plan;
use Pollora\Harness\Check\Step;
use Pollora\Harness\Check\StepResult;
use Pollora\Harness\Config\HarnessConfig;
use Pollora\Harness\Console\Commands\CheckCommand;
use Pollora\Harness\Console\Io;
use Pollora\Harness\Support\ChangedSources;

require_once __DIR__.'/../Support/RelativeMutationFixture.php';

/*
 * The three ways a false mutation score was still returned by harness 0.1.36: a Pest cache naming mutants that were
 * never written, a write warning hidden by `display_errors=0`, and mutants that never load behind a bootstrap that only
 * names PEST_MUTATION_TESTING.
 */

const TRUST_REPORT = "  Mutations: 12 tested\n  Score:     100.00%\n";
const TRUST_HONEST = "  Mutations: 4 untested, 8 tested\n  Score:     66.67%\n";

/**
 * A project with one source file and a fake Pest whose mutation run is the given PHP code.
 *
 * @param  string  $mutate  What `pest --mutate` does
 * @param  string  $mutant  What the process of one mutant does; loads the mutant by default, as Pest does
 */
function trustProject(string $mutate, string $mutant = "require getenv('PEST_MUTATION_FILE');"): string
{
    $root = harnessTempDir();
    mkdir($root.'/src');
    mkdir($root.'/vendor/bin', 0o777, true);
    mkdir($root.'/vendor/pestphp/pest-plugin-mutate', 0o777, true);
    file_put_contents($root.'/src/A.php', "<?php\n\nreturn 'original';\n");
    file_put_contents($root.'/vendor/bin/pest', "<?php\nif (getenv('PEST_MUTATION_FILE') !== false) {\n    {$mutant}\n    exit(0);\n}\n{$mutate}\n");

    return $root;
}

function trustPrints(string $report): string
{
    return 'echo '.var_export($report, true).';';
}

describe('the cache of Pest', function (): void {
    it('is not read: a run after a poisoned one scores what the tests really catch', function (): void {
        // As pest-plugin-mutate: without --no-cache, what an earlier run generated is trusted, written or not
        $root = trustProject(sprintf(
            "echo ! in_array('--no-cache', \$argv, true) && is_file('cache') ? %s : %s;",
            var_export(TRUST_REPORT, true),
            var_export(TRUST_HONEST, true),
        ));
        file_put_contents($root.'/cache', 'mutants that were never written');

        expect(MutationRun::run($root)['score'])->toBe(66.67);
    });
});

describe('the temporary files of Pest', function (): void {
    it('are reported even when the ini files hide warnings', function (): void {
        // A real PHP warning, of the shape Pest raises, printed only if the interpreter displays errors
        $root = trustProject("fopen('/nonexistent/vendor/pestphp/pest-plugin-mutate/.temp/mutations/abc', 'r');\n".trustPrints(TRUST_REPORT));
        $ini = harnessTempDir();
        file_put_contents($ini.'/hide.ini', "display_errors=0\n");
        $before = getenv('PHP_INI_SCAN_DIR');
        putenv('PHP_INI_SCAN_DIR='.($before === false ? '' : $before).PATH_SEPARATOR.$ini);

        try {
            $run = MutationRun::run($root);
        } finally {
            putenv($before === false ? 'PHP_INI_SCAN_DIR' : 'PHP_INI_SCAN_DIR='.$before);
        }

        expect($run['unwritable'])->toBeTrue()
            ->and($run['score'])->toBeNull();
    });

    it('are probed before Pest runs, which is not started when they cannot be written', function (): void {
        $root = trustProject("touch('ran');\n".trustPrints(TRUST_REPORT));
        mkdir($root.'/vendor/pestphp/pest-plugin-mutate/.temp');
        // A file where the directory should be: unwritable for root too
        file_put_contents($root.'/vendor/pestphp/pest-plugin-mutate/.temp/mutations', '');

        $run = MutationRun::run($root);

        expect($run['unwritable'])->toBeTrue()
            ->and($run['score'])->toBeNull()
            ->and($root.'/ran')->not->toBeFile();
    });

    it('give no score when a run leaves a mutant empty, as a full disk does', function (): void {
        $root = trustProject("touch('vendor/pestphp/pest-plugin-mutate/.temp/mutations/abc');\n".trustPrints(TRUST_REPORT));

        $run = MutationRun::run($root);

        expect($run['unwritable'])->toBeTrue()
            ->and($run['score'])->toBeNull();
    });

    it('give no score when the directory is lost during the run', function (): void {
        $root = trustProject("rmdir('vendor/pestphp/pest-plugin-mutate/.temp/mutations'); touch('vendor/pestphp/pest-plugin-mutate/.temp/mutations');\n".trustPrints(TRUST_REPORT));

        expect(MutationRun::run($root)['score'])->toBeNull();
    });

    it('are recognised in the warning of a full disk', function (): void {
        expect(MutationRun::cannotWriteTemporaryFiles("Notice: file_put_contents(): Write of 10 bytes failed with errno=28 No space left on device in /app/vendor/pestphp/pest-plugin-mutate/src/Mutation.php on line 61\n"))->toBeTrue();
    });

    it('are not writable without the plugin', function (): void {
        expect(MutationRun::temporaryDirectoryWritable(harnessTempDir()))->toBeFalse();
    });
});

describe('mutants that never load', function (): void {
    /** The original is served in place of the mutant, which is what Patchwork's stream wrapper does. */
    $hijacked = "require getenv('PEST_MUTATION_TESTING');";

    it('give no score, though the bootstrap names PEST_MUTATION_TESTING and Pest reads 100 %', function (array $paths) use ($hijacked): void {
        $root = trustProject(trustPrints(TRUST_REPORT), $hijacked);

        $run = MutationRun::run($root, $paths);
        $measure = MutationMeasure::fromRun($run);

        expect($run['score'])->toBeNull()
            ->and($measure->state)->toBe(MutationMeasure::UNMEASURED)
            ->and($measure->why)->toContain('No mutation score can be believed');
    })->with(['a file' => [['src/A.php']], 'the whole repository' => [[]]]);

    it('do not refuse a real 100 %: the canary was executed', function (array $paths): void {
        $root = trustProject(trustPrints(TRUST_REPORT));

        $run = MutationRun::run($root, $paths);

        expect($run['score'])->toBe(100.0)
            ->and($run)->not->toHaveKey('unloaded')
            ->and(glob($root.'/{src,.}/PolloraCanary*', GLOB_BRACE))->toBe([]);
    })->with(['a file' => [['src/A.php']], 'the whole repository' => [[]]]);

    it('fail the whole-repository gate and the changed-files gate, which give the ratchet nothing', function (string $scope, string $step) use ($hijacked): void {
        $root = trustProject(trustPrints(TRUST_REPORT), $hijacked);
        relGit($root, ['init', '-q', '-b', 'main']);
        relGit($root, ['add', 'vendor']);
        relGit($root, ['commit', '-q', '-m', 'base']);
        relGit($root, ['add', '-A']);
        relGit($root, ['commit', '-q', '-m', 'change']);
        file_put_contents($root.'/composer.json', '{"autoload":{"psr-4":{"App\\\\":"src/"}}}');
        $config = ['profile' => 'core', ...($scope === 'all' ? [] : ['mutation' => ['scope' => $scope, 'min' => 80]])];
        $steps = (new Plan(HarnessConfig::fromArray($config), $root))->full(null, null, true);

        $result = (array_values(array_filter($steps, static fn (Step $found): bool => $found->name === $step))[0]->action)();

        expect($result->status)->toBe(StepResult::FAILED)
            ->and($result->detail)->toContain('No mutation score can be believed');
    })->with([['all', Plan::MUTATION], ['changed', Plan::MUTATION_CHANGED]]);

    /** A mutant's process that serves A.php its mutant, the original of B.php, and never includes Unused.php. */
    $perFile = <<<'PHP'
        $original = (string) getenv('PEST_MUTATION_TESTING');
        if (str_ends_with($original, 'A.php')) {
            require getenv('PEST_MUTATION_FILE');
        }
        if (str_ends_with($original, 'B.php')) {
            require $original;
        }
        PHP;

    it('refuse a set of files when one is included without its mutant', function () use ($perFile): void {
        $root = trustProject('', $perFile);
        file_put_contents($root.'/src/B.php', "<?php\n");

        expect(MutationCanary::refusal($root, ['src/A.php']))->toBeNull()
            ->and(MutationCanary::refusal($root, ['src/A.php', 'src/B.php']))->toContain('src/B.php was included by the tests, but not its mutated version');
    });

    it('accept a set where a file the tests never include, which has no mutant, sits beside a loaded one', function () use ($perFile): void {
        $root = trustProject('', $perFile);
        file_put_contents($root.'/src/Unused.php', "<?php\n");

        expect(MutationCanary::refusal($root, ['src/A.php', 'src/Unused.php']))->toBeNull()
            ->and(MutationCanary::refusal($root, ['src/Unused.php']))->toContain('no mutated version of src/Unused.php was executed');
    });

    it('tries the suite in parallel when a single process never reaches the file', function (): void {
        $root = trustProject('', '');
        file_put_contents($root.'/vendor/bin/pest', "<?php\nif (in_array('--parallel', \$argv, true)) {\n    require getenv('PEST_MUTATION_FILE');\n}\n");

        expect(MutationCanary::refusal($root, ['src/A.php']))->toBeNull();
    });

    it('cannot be vouched for in a repository that cannot be read', function (): void {
        expect(MutationCanary::refusal(harnessTempDir().'/missing', []))->toContain('cannot be read');
    });
});

/**
 * A real project run by the installed Pest and its mutation plugin: no coverage driver is needed, the canary is a plain
 * test run with the environment Pest gives a mutant.
 *
 * It lives under /tmp for the reason `contractProject()` does.
 *
 * @param  string  $bootstrap  PHP run by the test bootstrap, after the autoloader
 * @param  list<string>  $paths
 */
function realPestRefusal(string $bootstrap, array $paths, ?string $test = null): ?string
{
    $test ??= "expect(require __DIR__.'/../src/Answer.php')->toBeInt();";
    $harness = dirname(__DIR__, 2);
    $root = '/tmp/pollora-harness-canary-'.bin2hex(random_bytes(5));

    foreach (['src', 'tests', 'vendor/bin', 'vendor/pestphp/pest/bin'] as $directory) {
        mkdir("{$root}/{$directory}", 0o755, true);
    }

    copy($harness.'/vendor/pestphp/pest/bin/pest', $root.'/vendor/pestphp/pest/bin/pest');
    copy($harness.'/vendor/pest-plugins.json', $root.'/vendor/pest-plugins.json');
    file_put_contents($root.'/vendor/bin/pest', "<?php\nrequire __DIR__.'/../pestphp/pest/bin/pest';\n");
    file_put_contents($root.'/vendor/autoload.php', "<?php\nreturn require '{$harness}/vendor/autoload.php';\n");
    file_put_contents($root.'/src/Answer.php', "<?php\n\nreturn 42;\n");
    file_put_contents($root.'/tests/bootstrap.php', "<?php\nrequire __DIR__.'/../vendor/autoload.php';\n\$mutated = getenv('PEST_MUTATION_TESTING');\n{$bootstrap}\n");
    file_put_contents($root.'/tests/AnswerTest.php', "<?php\n\ntest('answers', function (): void {\n    {$test}\n});\n");
    file_put_contents($root.'/phpunit.xml', '<?xml version="1.0"?><phpunit bootstrap="tests/bootstrap.php"><testsuites><testsuite name="Unit"><directory>tests</directory></testsuite></testsuites></phpunit>');

    try {
        return MutationCanary::refusal($root, $paths);
    } finally {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);

        foreach ($files as $file) {
            $file->isDir() && ! $file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }

        rmdir($root);
    }
}

describe('the canary, with a real Pest', function (): void {
    it('sees the mutant loaded by the wrapper of Pest', function (array $paths): void {
        expect(realPestRefusal('', $paths))->toBeNull();
    })->with(['a file' => [['src/Answer.php']], 'the whole repository' => [[]]]);

    it('sees that a bootstrap which takes the wrapper away, naming the variable, loads no mutant', function (array $paths): void {
        // What Patchwork does to Pest, without the part of a bootstrap that loads the mutant itself
        expect(realPestRefusal('\Pest\Mutate\Support\StreamWrapper::disable();', $paths))->toContain('No mutation score can be believed');
    })->with(['a file' => [['src/Answer.php']], 'the whole repository' => [[]]]);

    it('accepts a bootstrap that takes the wrapper away and loads the mutant itself, as the framework does', function (array $paths): void {
        $bootstrap = "if (is_string(\$mutated)) {\n    \Pest\Mutate\Support\StreamWrapper::disable();\n    require (string) getenv('PEST_MUTATION_FILE');\n}";

        expect(realPestRefusal($bootstrap, $paths))->toBeNull();
    })->with(['a file' => [['src/Answer.php']], 'the whole repository' => [[]]]);
});

/** A mutant's process that loads its mutant and then fails: what Patchwork on top of Pest's wrapper does to a suite. */
const FAILS_AFTER_LOADING = "require getenv('PEST_MUTATION_FILE'); echo \"  FAILED  Tests\\\\ATest > it lists a directory\\n\"; exit(2);";

describe('the tests on unchanged code run as a mutant', function (): void {
    it('give no score when they fail, though the mutant loads and Pest reads 100 %', function (array $paths): void {
        $root = trustProject(trustPrints(TRUST_REPORT), FAILS_AFTER_LOADING);

        $measure = MutationMeasure::fromRun(MutationRun::run($root, $paths));

        expect($measure->state)->toBe(MutationMeasure::UNMEASURED)
            ->and($measure->why)->toContain('No mutation score can be believed')
            ->and($measure->why)->toContain('fail (exit code 2) when Pest runs them as a mutant of src/A.php that changes nothing')
            ->and($measure->why)->toContain('FAILED Tests\ATest > it lists a directory');
    })->with(['a file' => [['src/A.php']], 'the whole repository' => [[]]]);

    it('do not start Pest, whose mutants would all be read as caught', function (): void {
        $root = trustProject("if (in_array('--mutate', \$argv, true)) {\n    touch('ran');\n}", FAILS_AFTER_LOADING);

        expect(MutationRun::run($root)['score'])->toBeNull()
            ->and($root.'/ran')->not->toBeFile();
    });

    it('are given an exact copy where Pest writes its mutants, neither --bail nor --parallel, and the copy is removed', function (): void {
        $record = <<<'PHP'
            $copy = (string) getenv('PEST_MUTATION_FILE');
            $original = (string) getenv('PEST_MUTATION_TESTING');
            if (str_contains($copy, 'pollora-unchanged-')) {
                file_put_contents('seen', json_encode([$original, $copy, md5_file($copy) === md5_file($original), array_slice($argv, 1), getenv('PARATEST'), getenv('TEST_TOKEN')]));
            }
            require $copy;
            PHP;
        $root = trustProject('', $record);

        expect(MutationCanary::refusal($root, ['src/A.php']))->toBeNull();

        [$original, $copy, $same, $arguments, $paratest, $token] = json_decode((string) file_get_contents($root.'/seen'), true);

        expect($original)->toBe(realpath($root.'/src/A.php'))
            ->and($copy)->toStartWith(realpath($root).'/vendor/pestphp/pest-plugin-mutate/.temp/mutations/')
            ->and($copy)->not->toBeFile()
            ->and($same)->toBeTrue()
            ->and($arguments)->toBe([])
            // As the processes of the parallel pass, which the harness runs first
            ->and([$paratest, $token])->toBe(['1', '1']);
    });

    it('are not run in parallel, rather than refused, when only the variables of a worker fail them', function (): void {
        $mutant = "require getenv('PEST_MUTATION_FILE'); if (getenv('PARATEST') === '1' && getenv('TEST_TOKEN') !== false) { exit(1); }";
        // The parallel pass would read 100 %, a single process reads the real score
        $mutate = "echo in_array('--parallel', \$argv, true) ? ".var_export(TRUST_REPORT, true).' : '.var_export(TRUST_HONEST, true).';';

        expect(MutationRun::run(trustProject($mutate, $mutant))['score'])->toBe(66.67);
    });

    /**
     * A project whose control writes the coverage of src/A.php (by ATest) and whose mutant processes fail unless the
     * `--filter` leaves out BTest, as a test that only fails under Pest's wrapper and does not cover A.php.
     */
    $covered = static function (string $coveredBy): string {
        $root = trustProject('', "require getenv('PEST_MUTATION_FILE'); if (str_contains((string) getenv('PEST_MUTATION_FILE'), 'pollora-unchanged-')) { \$filter = implode(' ', preg_grep('/^--filter=/', \$argv)); file_put_contents('filter', \$filter); exit(\$filter !== '' && ! str_contains(\$filter, 'BTest') ? 0 : 1); }");
        $report = sprintf('<?php return new class { function getData() { return $this; } function lineCoverage() { return [%s => [3 => [%s]]]; } };', var_export(realpath($root.'/src/A.php'), true), var_export($coveredBy, true));
        $pest = (string) file_get_contents($root.'/vendor/bin/pest');
        file_put_contents($root.'/vendor/bin/pest', str_replace("<?php\n", "<?php\nforeach (\$argv as \$a) { if (str_starts_with(\$a, '--coverage-php=')) { file_put_contents(substr(\$a, 15), ".var_export($report, true)."); } }\n", $pest));

        return $root;
    };

    it('run only the tests that cover the file, selected as Pest selects them', function (array $paths) use ($covered): void {
        $root = $covered('P\\Tests\\ATest::__pest_evaluable_it_reads__the_answer');

        expect(MutationCanary::unchanged($root, $paths)['refusal'])->toBeNull()
            ->and(file_get_contents($root.'/filter'))->toBe($paths === [] ? '--filter=ATest::' : '--filter=ATest::(.*)it.reads.{1,2}the.answer');
    })->with(['a file' => [['src/A.php']], 'the whole repository' => [[]]]);

    it('refuse the score when a test that covers the file fails', function () use ($covered): void {
        $root = $covered('P\\Tests\\BTest::__pest_evaluable_it_locks');

        expect(MutationCanary::unchanged($root, ['src/A.php'])['refusal'])->toContain('the tests covering the code fail');
    });

    it('say nothing about a file no test covers, which has no mutant', function () use ($covered): void {
        $root = $covered('P\\Tests\\BTest::__pest_evaluable_it_locks');
        file_put_contents($root.'/src/Unused.php', "<?php\n");

        expect(MutationCanary::unchanged($root, ['src/Unused.php'])['refusal'])->toBeNull();
    });

    it('give each run its own temporary directory, removed with what the run left there', function (): void {
        $root = trustProject('', "require getenv('PEST_MUTATION_FILE');");
        file_put_contents($root.'/vendor/bin/pest', "<?php\n\$temporary = sys_get_temp_dir();\nfile_put_contents('runs', \$temporary.\"\\n\", FILE_APPEND);\nif (is_file(\$temporary.'/leftover')) {\n    exit(1);\n}\ntouch(\$temporary.'/leftover');\nif (getenv('PEST_MUTATION_FILE') !== false) {\n    require getenv('PEST_MUTATION_FILE');\n}\n");

        expect(MutationCanary::refusal($root, ['src/A.php']))->toBeNull();

        $runs = file($root.'/runs', FILE_IGNORE_NEW_LINES);

        expect($runs)->toHaveCount(count(array_unique($runs)))
            ->and(array_filter($runs, is_dir(...)))->toBe([]);
    });

    it('stop a loop at the limit, with the processes it started', function (): void {
        $loop = <<<'PHP'
            require getenv('PEST_MUTATION_FILE');
            if (str_contains((string) getenv('PEST_MUTATION_FILE'), 'pollora-unchanged-')) {
                $child = proc_open([PHP_BINARY, '-r', 'sleep(60);'], [], $pipes);
                file_put_contents('child', (string) proc_get_status($child)['pid']);
                sleep(60);
            }
            PHP;
        $root = trustProject('', $loop);
        $started = microtime(true);

        $refusal = MutationCanary::refusal($root, ['src/A.php'], 1.0);

        expect($refusal)->toContain('are still running after 1 s')
            ->and(microtime(true) - $started)->toBeLessThan(30.0);

        usleep(200_000);
        $state = trim((string) shell_exec('ps -o stat= -p '.(int) file_get_contents($root.'/child')));

        // Gone, or a zombie nobody reaped yet
        expect($state === '' || str_starts_with($state, 'Z'))->toBeTrue();
    });

    it('accept a slow run within a few times the duration of the suite alone', function (): void {
        $slow = "usleep(1_500_000); require getenv('PEST_MUTATION_FILE');";
        // The suite alone takes 0.6 s: the limit is 3 s, well over the floor of this test
        $root = trustProject('usleep(600_000);', $slow);

        expect(MutationCanary::unchanged($root, ['src/A.php'], 0.1))->toMatchArray(['refusal' => null, 'parallel' => true]);
    });

    it('never allow less than two minutes, and five times the suite beyond', function (): void {
        expect(MutationCanary::TIME_FLOOR)->toBe(120.0)
            ->and(MutationCanary::TIME_FACTOR)->toBe(5.0)
            ->and(MutationCanary::unchanged(trustProject(''), ['src/A.php'])['limit'])->toBe(120.0);
    });

    it('say that the tests fail on their own when they do, and leave Pest to report it', function (): void {
        $root = trustProject('exit(1);');

        expect(MutationCanary::refusal($root, ['src/A.php']))->toContain('the tests do not pass on their own');

        $run = MutationRun::run($root);

        expect($run['score'])->toBeNull()
            ->and($run)->not->toHaveKey('unloaded');
    });

    it('are compared in parallel for a suite that only holds that way', function (): void {
        $root = trustProject('', "require getenv('PEST_MUTATION_FILE');");
        file_put_contents($root.'/vendor/bin/pest', "<?php\nif (! in_array('--parallel', \$argv, true)) {\n    exit(1);\n}\nif (getenv('PEST_MUTATION_FILE') !== false) {\n    require getenv('PEST_MUTATION_FILE');\n    exit(str_contains((string) getenv('PEST_MUTATION_FILE'), 'pollora-unchanged-') ? 1 : 0);\n}\n");

        expect(MutationCanary::unchanged($root, ['src/A.php'])['refusal'])->toContain('fail (exit code 1)');
    });

    it('run the first source file for the whole repository, never one Composer loads at startup', function (): void {
        $root = trustProject('');
        mkdir($root.'/src/Deep');
        file_put_contents($root.'/src/0-helpers.php', "<?php\n");
        file_put_contents($root.'/src/Deep/B.php', "<?php\n");
        file_put_contents($root.'/composer.json', '{"autoload":{"psr-4":{"App\\\\":"src/"},"files":["src/0-helpers.php"]}}');

        expect(ChangedSources::all($root))->toBe(['src/A.php', 'src/Deep/B.php']);
    });
});

describe('check --mutation-canary', function (): void {
    $check = static function (string $root, array $argv = ['--mutation-canary']): array {
        [$out, $err] = [fopen('php://memory', 'w+'), fopen('php://memory', 'w+')];
        $code = (new CheckCommand)->run(Io::fromArgv($root, $argv, $out, $err));
        rewind($out);
        rewind($err);

        return [$code, stream_get_contents($out).stream_get_contents($err)];
    };

    it('passes without running a mutation pass or a gate', function () use ($check): void {
        $root = trustProject("if (in_array('--mutate', \$argv, true)) {\n    touch('ran');\n}");

        [$code, $output] = $check($root);

        expect($code)->toBe(0)
            ->and($output)->toContain('No score was measured')
            ->and($root.'/ran')->not->toBeFile()
            ->and($root.'/.harness')->not->toBeDirectory();
    });

    it('fails when no score could be believed, even in observe mode', function (string $argument) use ($check): void {
        [$code, $output] = $check(trustProject('', FAILS_AFTER_LOADING), [$argument, '--observe']);

        expect($code)->toBe(1)->and($output)->toContain('that changes nothing');
    })->with(['--mutation-canary', '--mutation-canary=src/A.php']);

    it('fails rather than pass when there was nothing to check', function () use ($check): void {
        expect($check(harnessTempDir())[1])->toContain('nothing was checked')
            ->and($check(harnessTempDir())[0])->toBe(1)
            ->and($check(trustProject(''), ['--mutation-canary=src/Missing.php'])[0])->toBe(1);
    });
});

describe('unchanged code, with a real Pest', function (): void {
    /** An exclusive lock keeps a second one out, except behind the wrapper of Pest, which takes every lock as shared. */
    $locks = <<<'PHP'
        require __DIR__.'/../src/Answer.php';
        $file = sys_get_temp_dir().'/pollora-lock-'.getmypid();
        $first = fopen($file, 'c');
        $second = fopen($file, 'c');
        flock($first, LOCK_EX);
        $taken = flock($second, LOCK_EX | LOCK_NB);
        unlink($file);
        expect($taken)->toBeFalse();
        PHP;

    it('gives no score when a test fails only behind the wrapper of Pest', function (array $paths) use ($locks): void {
        expect(realPestRefusal('', $paths, $locks))
            ->toContain('fail (exit code')
            ->toContain('src/Answer.php that changes nothing');
    })->with(['a file' => [['src/Answer.php']], 'the whole repository' => [[]]]);

    it('accepts the same suite once the bootstrap gives the wrapper back and loads the mutant itself', function (array $paths) use ($locks): void {
        $bootstrap = "if (is_string(\$mutated)) {\n    \Pest\Mutate\Support\StreamWrapper::disable();\n    require (string) getenv('PEST_MUTATION_FILE');\n}";
        // The file is already loaded by the bootstrap: the test reads nothing from it
        $test = str_replace("require __DIR__.'/../src/Answer.php';", '', $locks);

        expect(realPestRefusal($bootstrap, $paths, $paths === [] ? $test : $locks))->toBeNull();
    })->with(['a file' => [['src/Answer.php']], 'the whole repository' => [[]]]);
});
