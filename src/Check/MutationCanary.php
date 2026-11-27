<?php

declare(strict_types=1);

namespace Pollora\Harness\Check;

use Pollora\Harness\Support\ChangedSources;
use Pollora\Harness\Support\InterpreterFlags;

/**
 * Checks, before a mutation score is believed, that the process of a mutant says something about the mutant: the mutated
 * source is the code PHP runs, and the tests pass when that source is not changed at all.
 *
 * Pest hands a mutant to its process through `PEST_MUTATION_TESTING` (the source file) and `PEST_MUTATION_FILE` (the
 * mutated copy), and serves the copy through a stream wrapper on `file`. Another wrapper (Patchwork, which Brain
 * Monkey loads) or a test bootstrap can leave the original in place: every mutant then survives, or its process dies
 * and counts as killed, and Pest reports nothing of it. A bootstrap that merely names the variable proves nothing, so
 * this runs Pest as one of those processes, with a "mutant" that only records that it was executed, and stops it.
 *
 * - With the files a run mutates (`$paths`), each is probed with the real suite. A file the suite never includes has
 *   no covered line, hence no mutant, and says nothing; a file that is included while the canary is not executed is
 *   served by something else, and refuses the score; so does a set of files none of which the canary was executed for.
 * - Without paths (the whole repository) there is no single file to probe: the harness writes one source file and one
 *   test that loads Patchwork (when installed) and then requires it, and runs that test alone. It proves that the
 *   repository's bootstrap leaves mutant loading working, not that every one of its files is loaded that way.
 *
 * A legitimate score of 100 % passes: it differs from a false one by the canary being executed, not by its value.
 *
 * A mutant that loads is not enough. Pest reads a mutant's process that fails as a caught mutant, and one that does not
 * end as a timeout, which counts as caught too. A test that fails or loops in that process whatever the file contains
 * (Patchwork stacked on Pest's stream wrapper breaks directory reading; that wrapper itself takes every `flock()` as
 * shared and reports an unreadable file as absent) therefore scores mutants nothing tested. So tests are also run as
 * Pest runs a mutant, with `PEST_MUTATION_FILE` an exact copy of the source:
 *
 * - First the suite runs alone: the control, which times it and, with a coverage driver, says which tests cover which
 *   file. A single process is tried first, then `--parallel` (for a suite that only holds that way, or one flaky run),
 *   both with coverage before either without; when none passes there is nothing to compare with.
 * - Then, with the environment of a mutant, the tests Pest would select: those that cover the file (`--filter`, the
 *   union of what it selects for each line, in several runs when too long for one). Never their whole class: a test
 *   that covers nothing is run for no mutant. Without coverage data, the whole suite, which can refuse a score for a
 *   test that no mutant of the file would run.
 * - Pest never gives a mutant `--parallel`: in a parallel mutation run it sets the variables of a ParaTest worker, and
 *   so does this. When the tests only fail with those, mutants are not run in parallel.
 * - A run that fails is tried once more: one flaky failure must not refuse the score.
 * - Without `--bail`, so that every failing test is listed; the verdict is the same.
 * - The limit of these runs is `TIME_FACTOR` times the control, and never below `TIME_FLOOR` seconds.
 */
final class MutationCanary
{
    /** A run as a mutant is stopped after this many times the duration of the suite alone, */
    public const float TIME_FACTOR = 5.0;

    /** and never before this many seconds, so that a short suite on a busy machine is not refused. */
    public const float TIME_FLOOR = 120.0;

    private const string LOADED = 'loaded';

    private const string IGNORED = 'ignored';

    private const string ABSENT = 'absent';

    /** Past this length a `--filter` is split over several runs: PCRE refuses a longer one, which then selects nothing. */
    private const int FILTER_LIMIT = 20_000;

    /** What Pest and ParaTest set in the processes of a run: none of it may leak into the control. */
    private const array PEST_VARIABLES = ['PEST_MUTATION_TESTING', 'PEST_MUTATION_FILE', 'PARATEST', 'TEST_TOKEN', 'UNIQUE_TEST_TOKEN', 'LARAVEL_PARALLEL_TESTING'];

    /**
     * Both checks at once, for a use without a mutation run (`check --mutation-canary`).
     *
     * @param  list<string>  $paths  Source files relative to $cwd; empty for the whole repository
     * @param  float  $floor  The shortest limit of a run, in seconds
     * @return string|null Why no score could be believed, null when it could
     */
    public static function refusal(string $cwd, array $paths, float $floor = self::TIME_FLOOR): ?string
    {
        $unchanged = self::unchanged($cwd, $paths, $floor);

        return $unchanged['refusal'] ?? self::loaded($cwd, $paths, $unchanged['limit']);
    }

    /**
     * Whether the tests pass as the process of a mutant that changes nothing. To be asked before Pest runs its
     * mutants: they may leave files behind that make the suite fail afterwards.
     *
     * For the whole repository, the first source file stands for the others (Pest puts its wrapper on `file` whatever
     * the file is), with the tests that cover any of them. Each file of a limited run is tried with its own tests.
     *
     * @param  list<string>  $paths  Source files relative to $cwd; empty for the whole repository
     * @param  float  $floor  The shortest limit of a run, in seconds
     * @return array{refusal: string|null, parallel: bool, controlled: bool, limit: float} Why no score can be believed;
     *                                                                                     whether mutants can run in parallel; whether the suite passes on its own; the limit of a run, in seconds
     */
    public static function unchanged(string $cwd, array $paths, float $floor = self::TIME_FLOOR): array
    {
        $result = ['refusal' => null, 'parallel' => true, 'controlled' => true, 'limit' => $floor];
        $root = realpath($cwd);

        if ($root === false) {
            return $result;
        }

        $sources = $paths === [] ? ChangedSources::all($root) : $paths;
        $originals = self::originals($root, $paths === [] ? array_slice($sources, 0, 1) : $paths);

        // Without a source file to copy there is no mutant either
        if ($originals === []) {
            return $result;
        }

        $control = self::control($root);

        if (is_string($control)) {
            return [...$result, 'refusal' => $control, 'controlled' => false];
        }

        [$seconds, $coverage] = $control;
        $result['limit'] = max($floor, self::TIME_FACTOR * $seconds);

        foreach ($originals as $path => $original) {
            $filters = null;

            if ($coverage !== null) {
                $tests = self::testsCovering($coverage, $paths === [] ? array_values(self::originals($root, $sources)) : [$original]);

                // No test covers the file: Pest runs no mutant of it
                if ($tests === [] && $paths !== []) {
                    continue;
                }

                $filters = $tests === [] ? null : self::filters($tests);
            }

            foreach ($filters ?? [null] as $filter) {
                $variables = $result['parallel'] ? self::workerVariables() : [];
                $refusal = self::unchangedRefusal($root, $path, $original, $variables, $filter, $result['limit'], $paths === []);

                if ($refusal !== null && $variables !== []) {
                    $result['parallel'] = false;
                    $refusal = self::unchangedRefusal($root, $path, $original, [], $filter, $result['limit'], $paths === []);
                }

                if ($refusal !== null) {
                    return [...$result, 'refusal' => $refusal];
                }
            }
        }

        return $result;
    }

    /**
     * Whether the mutated version of a source file is the code the tests run.
     *
     * @param  list<string>  $paths  The source files the run mutated, relative to $cwd; empty for the whole repository
     * @param  float  $limit  The longest a run may take, in seconds
     * @return string|null Why no score can be believed, null when mutated sources are executed
     */
    public static function loaded(string $cwd, array $paths, float $limit = self::TIME_FLOOR): ?string
    {
        $root = realpath($cwd);

        if ($root === false) {
            return self::message('the repository cannot be read');
        }

        if ($paths === []) {
            return self::probeBootstrap($root, $limit);
        }

        $originals = self::originals($root, $paths);
        $loaded = false;
        $output = '';

        foreach ($originals as $path => $original) {
            [$state, $output] = self::probe($root, $original, [], $limit);

            if ($state === self::IGNORED) {
                return self::message(sprintf('%s was included by the tests, but not its mutated version', $path), $output);
            }

            $loaded = $loaded || $state === self::LOADED;
        }

        if ($loaded) {
            return null;
        }

        // A suite that only holds in parallel may stop, in a single process, before it reaches the file
        foreach ($originals as $original) {
            if (self::probe($root, $original, ['--parallel'], $limit)[0] === self::LOADED) {
                return null;
            }
        }

        return self::message(sprintf('no mutated version of %s was executed by the tests', implode(', ', $paths)), $output);
    }

    /**
     * @param  list<string>  $paths
     * @return array<string, string> The real path of each file that exists
     */
    private static function originals(string $root, array $paths): array
    {
        $originals = [];

        foreach ($paths as $path) {
            $original = realpath($root.'/'.$path);

            if ($original !== false) {
                $originals[$path] = $original;
            }
        }

        return $originals;
    }

    /**
     * Runs the suite alone, with nothing of a mutant.
     *
     * @return array{float, array<string, list<string>>|null}|string Its duration and the tests covering each file (null
     *                                                               without coverage data), or why nothing can be compared
     */
    private static function control(string $root): array|string
    {
        $report = sys_get_temp_dir().'/pollora-coverage-'.bin2hex(random_bytes(8)).'.php';

        // In parallel too: without coverage data, every test would be run as a mutant, those of no mutant included
        foreach ([[], ['--parallel']] as $arguments) {
            try {
                [$code, , $seconds] = self::execute($root, [...$arguments, '--coverage-php='.$report], [...self::environment(), 'XDEBUG_MODE' => 'coverage'], flags: InterpreterFlags::coverageScope($root));

                if ($code === 0) {
                    return [$seconds, self::coverage($root, $report)];
                }
            } finally {
                if (is_file($report)) {
                    unlink($report);
                }
            }
        }

        $output = '';

        // Coverage can be what fails (no driver, in a repository that fails on warnings)
        foreach ([[], ['--parallel']] as $arguments) {
            [$code, $output, $seconds] = self::execute($root, $arguments, self::environment());

            if ($code === 0) {
                return [$seconds, null];
            }
        }

        return self::message('the tests do not pass on their own, outside any mutation', $output, 'Run `vendor/bin/pest`: it must pass first.');
    }

    /**
     * The tests that cover each file, read from a `--coverage-php` report with the classes of the repository.
     *
     * @return array<string, list<string>>|null null when the report cannot be read or names no test
     */
    private static function coverage(string $root, string $report): ?array
    {
        if (! is_file($report)) {
            return null;
        }

        $code = <<<'PHP'
            if (is_file($argv[1])) { require $argv[1]; }
            $coverage = require $argv[2];
            $files = [];
            foreach ($coverage->getData()->lineCoverage() as $file => $lines) {
                $tests = [];
                foreach ($lines as $names) { foreach ((array) $names as $name) { $tests[$name] = true; } }
                if ($tests !== []) { $files[realpath($file) ?: $file] = array_keys($tests); }
            }
            echo "\n", json_encode($files);
            PHP;
        // A report weighs tens of megabytes before it is unserialized
        $process = proc_open([PHP_BINARY, '-d', 'memory_limit=-1', '-r', $code, '--', $root.'/vendor/autoload.php', $report], [1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $root);

        if (! is_resource($process)) {
            return null;
        }

        $lines = explode("\n", (string) stream_get_contents($pipes[1]));
        fclose($pipes[1]);
        proc_close($process);
        $files = json_decode((string) end($lines), true);

        if (! is_array($files) || $files === []) {
            return null;
        }

        $coverage = [];

        foreach ($files as $file => $tests) {
            $coverage[(string) $file] = is_array($tests) ? array_values(array_filter($tests, is_string(...))) : [];
        }

        return $coverage;
    }

    /**
     * @param  array<string, list<string>>  $coverage
     * @param  list<string>  $files
     * @return list<string>
     */
    private static function testsCovering(array $coverage, array $files): array
    {
        $tests = [];

        foreach ($files as $file) {
            array_push($tests, ...($coverage[$file] ?? []));
        }

        return array_values(array_unique($tests));
    }

    /**
     * The `--filter` Pest gives the process of a mutant (`MutationTest::start()`), for these tests, in as many parts as
     * their length needs.
     *
     * @param  list<string>  $tests  As the coverage names them
     * @return list<string>|null null when a test has a name Pest's own pattern does not read
     */
    private static function filters(array $tests): ?array
    {
        $names = [];

        foreach ($tests as $test) {
            if (preg_match('/\\\\([a-zA-Z0-9]*)::(__pest_evaluable_)?([^#]*)"?/', $test, $matches) !== 1) {
                return null;
            }

            $names[$matches[1].'::(.*)'.($matches[2] === '__pest_evaluable_' ? str_replace(['__', '_'], ['.{1,2}', '.'], $matches[3]) : $matches[3])] = true;
        }

        $filters = [];
        $part = '';

        foreach (array_keys($names) as $name) {
            if ($part !== '' && strlen($part) + strlen($name) >= self::FILTER_LIMIT) {
                $filters[] = $part;
                $part = '';
            }

            $part .= ($part === '' ? '' : '|').$name;
        }

        return $part === '' ? $filters : [...$filters, $part];
    }

    /**
     * What Pest sets in the process of a mutant of a parallel run.
     *
     * @return array<string, string>
     */
    private static function workerVariables(): array
    {
        return ['PARATEST' => '1', 'TEST_TOKEN' => '1', 'UNIQUE_TEST_TOKEN' => '1_'.bin2hex(random_bytes(6)), 'LARAVEL_PARALLEL_TESTING' => '1'];
    }

    /**
     * Runs the tests as the process of a mutant that changes nothing.
     *
     * @param  array<string, string>  $variables  Those of a parallel run, or none
     * @param  string|null  $filter  The tests to run; null runs them all
     */
    private static function unchangedRefusal(string $root, string $path, string $original, array $variables, ?string $filter, float $limit, bool $wholeRepository): ?string
    {
        // Where Pest writes its mutants, so that a bootstrap which reads the mutant finds it where it would
        $directory = $root.'/vendor/pestphp/pest-plugin-mutate/.temp/mutations';
        $copy = (MutationRun::temporaryDirectoryWritable($root) ? $directory : sys_get_temp_dir()).'/pollora-unchanged-'.bin2hex(random_bytes(8));

        if (! @copy($original, $copy)) {
            return self::message(sprintf('the harness could not copy %s', $path));
        }

        $environment = [...self::environment(), 'PEST_MUTATION_TESTING' => $original, 'PEST_MUTATION_FILE' => $copy, ...$variables];

        try {
            // Never --parallel, which Pest gives no mutant
            $arguments = $filter === null ? [] : ['--filter='.$filter];
            [$code, $output] = self::execute($root, $arguments, $environment, $limit);

            // A filter that selects nothing proves nothing: the whole suite then
            if ($filter !== null && preg_match('/No tests (?:found|executed)/i', $output) === 1) {
                $filter = null;
                $arguments = [];
                [$code, $output] = self::execute($root, $arguments, $environment, $limit);
            }

            // One flaky failure is no verdict; a run past the limit is not tried again
            if ($code !== 0 && $code !== null) {
                [$code, $output] = self::execute($root, $arguments, $environment, $limit);
            }
        } finally {
            if (is_file($copy)) {
                unlink($copy);
            }
        }

        if ($code === 0) {
            return null;
        }

        return self::message(
            sprintf(
                'the suite passes on its own but %s %s when Pest runs them as a mutant of %s that changes nothing%s%s',
                $filter === null ? 'its tests (all of them: no coverage data to select by)' : 'the tests covering the code',
                $code === null ? sprintf('are still running after %d s', (int) ceil($limit)) : sprintf('fail (exit code %d)', $code),
                $path,
                $variables === [] ? '' : ', in a parallel run',
                $wholeRepository ? ' (the first source file, standing for every one: the failure does not have to be about it)' : '',
            ),
            $output,
            'Pest counts such a failure as a caught mutant, and a loop as a timeout, which counts as caught too. Known causes: the stream wrapper Pest puts on `file` (it takes every flock() as shared and reports a file nobody may read as absent), Patchwork, which Brain Monkey loads, stacked on it (reading a directory then fails: antecedent/patchwork#235), and tests that only pass after others that Pest does not run with them. When PEST_MUTATION_TESTING is set, the test bootstrap can give `file` back and load PEST_MUTATION_FILE itself (see the framework\'s tests/bootstrap.php).',
        );
    }

    /**
     * Probes a source file the harness writes, required by a test it writes.
     */
    private static function probeBootstrap(string $root, float $limit): ?string
    {
        $name = 'PolloraCanary'.bin2hex(random_bytes(6));
        $original = (is_dir($root.'/src') ? $root.'/src/' : $root.'/').$name.'.php';
        $test = (is_dir($root.'/tests') ? $root.'/tests/' : $root.'/').$name.'Test.php';
        $patchwork = $root.'/vendor/antecedent/patchwork/Patchwork.php';

        $code = "<?php\n\ntest('the pollora canary source is loaded', function (): void {\n"
            // The worst case: a test that loads Patchwork before the file under test is read
            .(is_file($patchwork) ? '    require_once '.var_export($patchwork, true).";\n" : '')
            .'    require '.var_export($original, true).";\n    expect(true)->toBeTrue();\n});\n";

        try {
            if (@file_put_contents($original, "<?php\n") === false || @file_put_contents($test, $code) === false) {
                return self::message('the harness could not write its canary files in the repository');
            }

            [$state, $output] = self::probe($root, $original, [$test], $limit);

            return $state === self::LOADED ? null : self::message('a mutated source file required by a test was not executed', $output);
        } finally {
            foreach ([$original, $test] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }

    /**
     * Runs Pest as the process of one mutant of `$original`.
     *
     * @param  list<string>  $arguments  Arguments of Pest; none runs the whole suite, until the canary stops it
     * @return array{string, string} Whether the canary ran (`loaded`), the original was included without it
     *                               (`ignored`) or was not included (`absent`), and what Pest printed
     */
    private static function probe(string $root, string $original, array $arguments, float $limit): array
    {
        $directory = sys_get_temp_dir().'/pollora-canary-'.bin2hex(random_bytes(8));

        if (! @mkdir($directory, 0o700)) {
            return [self::ABSENT, 'cannot create '.$directory];
        }

        [$mutant, $prepend, $loaded, $included] = array_map(
            static fn (string $name): string => $directory.'/'.$name,
            ['mutant.php', 'prepend.php', 'loaded', 'included'],
        );

        try {
            file_put_contents($mutant, sprintf("<?php\n\\file_put_contents(%s, '1');\nexit(0);\n", var_export($loaded, true)));
            file_put_contents($prepend, sprintf(
                "<?php\nregister_shutdown_function(static function (): void {\n    if (in_array(%s, get_included_files(), true)) {\n        file_put_contents(%s, '1');\n    }\n});\n",
                var_export($original, true),
                var_export($included, true),
            ));

            [, $output] = self::execute(
                $root,
                $arguments,
                [...self::environment(), 'PEST_MUTATION_TESTING' => $original, 'PEST_MUTATION_FILE' => $mutant],
                $limit,
                ['-d', 'auto_prepend_file='.$prepend],
            );

            clearstatcache();

            $state = match (true) {
                is_file($loaded) => self::LOADED,
                is_file($included) => self::IGNORED,
                default => self::ABSENT,
            };

            return [$state, $output];
        } finally {
            foreach ([$mutant, $prepend, $loaded, $included] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }

            rmdir($directory);
        }
    }

    /**
     * Runs Pest, and kills it and its processes once past the limit.
     *
     * @param  list<string>  $arguments
     * @param  array<string, string>  $environment
     * @param  list<string>  $flags  Flags of the interpreter
     * @return array{int|null, string, float} The exit code (null when killed), the output and the seconds it took
     */
    private static function execute(string $root, array $arguments, array $environment, ?float $limit = null, array $flags = []): array
    {
        $log = (string) tempnam(sys_get_temp_dir(), 'pollora-pest-');
        // A run of its own: what a killed or failing run leaves behind must not fail the next one
        $temporary = sys_get_temp_dir().'/pollora-run-'.bin2hex(random_bytes(6));

        if (@mkdir($temporary, 0o700)) {
            $environment = [...$environment, 'TMPDIR' => $temporary, 'TMP' => $temporary, 'TEMP' => $temporary];
        }

        $started = microtime(true);

        try {
            $process = proc_open(
                [PHP_BINARY, ...InterpreterFlags::inherited(), '-d', 'display_errors=1', ...$flags, 'vendor/bin/pest', ...$arguments],
                [1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
                $pipes,
                $root,
                $environment,
            );

            if (! is_resource($process)) {
                return [-1, 'Pest could not be started', 0.0];
            }

            $code = null;

            while (true) {
                $status = proc_get_status($process);

                if (! $status['running']) {
                    $code = $status['exitcode'];

                    break;
                }

                if ($limit !== null && microtime(true) - $started > $limit) {
                    self::kill($process, $status['pid']);

                    break;
                }

                usleep(20_000);
            }

            proc_close($process);

            return [$code, (string) file_get_contents($log), microtime(true) - $started];
        } finally {
            unlink($log);
            self::remove($temporary);
        }
    }

    /**
     * Removes a directory and what is in it, without following links.
     */
    private static function remove(string $directory): void
    {
        if (! is_dir($directory) || is_link($directory)) {
            return;
        }

        // A test may leave a directory nobody can list
        @chmod($directory, 0o700);

        foreach (array_diff((array) @scandir($directory), ['.', '..', false]) as $name) {
            $path = $directory.'/'.$name;

            if (is_dir($path) && ! is_link($path)) {
                self::remove($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($directory);
    }

    /**
     * Kills a process and the processes it started (the workers of ParaTest, a server a test left running).
     *
     * @param  resource  $process
     */
    private static function kill($process, int $pid): void
    {
        $list = proc_open(['ps', '-A', '-o', 'pid=,ppid='], [1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        $table = '';

        if (is_resource($list)) {
            $table = (string) stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            proc_close($list);
        }

        preg_match_all('/^\s*(\d+)\s+(\d+)\s*$/m', $table, $rows, PREG_SET_ORDER);
        $doomed = [$pid];

        for ($index = 0; $index < count($doomed); $index++) {
            foreach ($rows as [, $child, $parent]) {
                if ((int) $parent === $doomed[$index]) {
                    $doomed[] = (int) $child;
                }
            }
        }

        proc_terminate($process, 9);

        // The command rather than posix_kill(), which would make ext-posix a requirement
        if (count($doomed) > 1) {
            $killing = proc_open(['kill', '-9', ...array_map(strval(...), array_slice($doomed, 1))], [2 => ['file', '/dev/null', 'w']], $ignored);

            if (is_resource($killing)) {
                proc_close($killing);
            }
        }
    }

    /**
     * The environment of the harness without what Pest sets for the processes of a run.
     *
     * @return array<string, string>
     */
    private static function environment(): array
    {
        return array_diff_key(getenv(), array_flip(self::PEST_VARIABLES));
    }

    private static function message(string $what, string $output = '', ?string $advice = null): string
    {
        $clean = (string) preg_replace('/\x1b\[[0-9;]*m/', '', $output);
        preg_match_all('/^\s*FAILED\s+\S.*$/m', $clean, $failures);
        $failed = array_slice(array_map(static fn (string $line): string => '  '.trim((string) preg_replace('/\s{2,}/', ' ', $line)), $failures[0]), 0, 15);
        $tail = trim(substr($clean, -600));

        return implode("\n", [
            sprintf(
                'No mutation score can be believed: %s, so Pest\'s score is not about its mutants (they survive unseen, or their processes die and count as killed). %s',
                $what,
                $advice ?? 'Something else serves the source files, usually Patchwork replacing the stream wrapper: the test bootstrap must give it back to Pest when PEST_MUTATION_TESTING is set and load PEST_MUTATION_FILE.',
            ),
            ...$failed,
            ...($tail === '' ? [] : [$tail]),
        ]);
    }
}
