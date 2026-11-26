<?php

declare(strict_types=1);

use Pollora\Harness\Check\MutationCanary;
use Pollora\Harness\Check\MutationMeasure;
use Pollora\Harness\Check\MutationRun;
use Pollora\Harness\Check\Plan;
use Pollora\Harness\Check\Step;
use Pollora\Harness\Check\StepResult;
use Pollora\Harness\Config\HarnessConfig;

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
function realPestRefusal(string $bootstrap, array $paths): ?string
{
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
    file_put_contents($root.'/tests/AnswerTest.php', "<?php\n\ntest('answers', function (): void {\n    expect(require __DIR__.'/../src/Answer.php')->toBeInt();\n});\n");
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
