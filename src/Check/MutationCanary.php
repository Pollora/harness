<?php

declare(strict_types=1);

namespace Pollora\Harness\Check;

use Pollora\Harness\Support\InterpreterFlags;

/**
 * Checks that the mutated source is the code PHP runs, before a mutation score is believed.
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
 */
final class MutationCanary
{
    private const string LOADED = 'loaded';

    private const string IGNORED = 'ignored';

    private const string ABSENT = 'absent';

    /**
     * @param  list<string>  $paths  The source files the run mutated, relative to $cwd; empty for the whole repository
     * @return string|null Why no score can be believed, null when mutated sources are executed
     */
    public static function refusal(string $cwd, array $paths): ?string
    {
        $root = realpath($cwd);

        if ($root === false) {
            return self::message('the repository cannot be read');
        }

        if ($paths === []) {
            return self::probeBootstrap($root);
        }

        $loaded = false;
        $output = '';

        foreach ($paths as $path) {
            $original = realpath($root.'/'.$path);

            if ($original === false) {
                continue;
            }

            [$state, $output] = self::probe($root, $original);

            if ($state === self::IGNORED) {
                return self::message(sprintf('%s was included by the tests, but not its mutated version', $path), $output);
            }

            $loaded = $loaded || $state === self::LOADED;
        }

        if ($loaded) {
            return null;
        }

        // A suite that only holds in parallel may stop, in a single process, before it reaches the file
        foreach ($paths as $path) {
            $original = realpath($root.'/'.$path);

            if ($original !== false && self::probe($root, $original, ['--parallel'])[0] === self::LOADED) {
                return null;
            }
        }

        return self::message(sprintf('no mutated version of %s was executed by the tests', implode(', ', $paths)), $output);
    }

    /**
     * Probes a source file the harness writes, required by a test it writes.
     */
    private static function probeBootstrap(string $root): ?string
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

            [$state, $output] = self::probe($root, $original, [$test]);

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
    private static function probe(string $root, string $original, array $arguments = []): array
    {
        $directory = sys_get_temp_dir().'/pollora-canary-'.bin2hex(random_bytes(8));

        if (! @mkdir($directory, 0o700)) {
            return [self::ABSENT, 'cannot create '.$directory];
        }

        [$mutant, $prepend, $loaded, $included, $log] = array_map(
            static fn (string $name): string => $directory.'/'.$name,
            ['mutant.php', 'prepend.php', 'loaded', 'included', 'output'],
        );

        try {
            file_put_contents($mutant, sprintf("<?php\n\\file_put_contents(%s, '1');\nexit(0);\n", var_export($loaded, true)));
            file_put_contents($prepend, sprintf(
                "<?php\nregister_shutdown_function(static function (): void {\n    if (in_array(%s, get_included_files(), true)) {\n        file_put_contents(%s, '1');\n    }\n});\n",
                var_export($original, true),
                var_export($included, true),
            ));

            $process = proc_open(
                [PHP_BINARY, ...InterpreterFlags::inherited(), '-d', 'auto_prepend_file='.$prepend, 'vendor/bin/pest', ...$arguments],
                [1 => ['file', $log, 'w'], 2 => ['file', '/dev/null', 'w']],
                $pipes,
                $root,
                [...getenv(), 'PEST_MUTATION_TESTING' => $original, 'PEST_MUTATION_FILE' => $mutant],
            );

            if (is_resource($process)) {
                proc_close($process);
            }

            clearstatcache();

            $state = match (true) {
                is_file($loaded) => self::LOADED,
                is_file($included) => self::IGNORED,
                default => self::ABSENT,
            };

            return [$state, is_file($log) ? (string) file_get_contents($log) : ''];
        } finally {
            foreach ([$mutant, $prepend, $loaded, $included, $log] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }

            rmdir($directory);
        }
    }

    private static function message(string $what, string $output = ''): string
    {
        $tail = trim(substr($output, -600));

        return sprintf(
            'No mutation score can be believed: %s, so Pest\'s score is not about its mutants (they survive unseen, or their processes die and count as killed). Something else serves the source files, usually Patchwork replacing the stream wrapper: the test bootstrap must give it back to Pest when PEST_MUTATION_TESTING is set and load PEST_MUTATION_FILE.%s',
            $what,
            $tail === '' ? '' : "\n".$tail,
        );
    }
}
