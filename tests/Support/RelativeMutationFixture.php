<?php

declare(strict_types=1);

/**
 * Support for the tests of the `changed-relative` mutation gate: a git repository whose base and change are committed,
 * and a fake Pest whose report depends on the source file it is asked to mutate.
 *
 * The fake reads a marker in the first line of `--path=<file>`: `// killed=39 survived=10` prints those counts and
 * their score; `// report=<json string>` prints that text as it is (to play a Pest that leaks Patchwork's frames,
 * cannot write its files, or prints no score). A file that does not exist prints nothing.
 */
if (! function_exists('relGit')) {
    /**
     * Runs git in a directory and returns what it printed.
     *
     * @param  list<string>  $arguments
     */
    function relGit(string $root, array $arguments): string
    {
        $process = proc_open(['git', '-c', 'user.name=t', '-c', 'user.email=t@example.test', '-c', 'commit.gpgsign=false', ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return trim($output);
    }
}

if (! function_exists('relWrite')) {
    /**
     * Writes files (path => content) under a root, creating the directories.
     *
     * @param  array<string, string>  $files
     */
    function relWrite(string $root, array $files): void
    {
        foreach ($files as $path => $content) {
            if (! is_dir(dirname($root.'/'.$path))) {
                mkdir(dirname($root.'/'.$path), 0o755, true);
            }

            file_put_contents($root.'/'.$path, $content);
        }
    }
}

if (! function_exists('relMarker')) {
    /**
     * The source of a file the fake Pest scores: `killed` mutants caught, `survived` that were not.
     */
    function relMarker(int $killed, int $survived): string
    {
        return "<?php // killed={$killed} survived={$survived}\n";
    }
}

if (! function_exists('relRepository')) {
    /**
     * A git repository with a base commit and a change commit, and a fake Pest in an untracked `vendor/`.
     *
     * @param  array<string, string>  $base  Files of the base commit (composer.json is added)
     * @param  array<string, string>  $change  Files the change adds or modifies
     * @param  list<string>  $removed  Files the change deletes
     * @param  array<string, string>  $renamed  Files the change renames, new path => old path (the content comes from `$change`)
     */
    function relRepository(array $base, array $change, array $removed = [], array $renamed = []): string
    {
        $root = harnessTempDir();
        relGit($root, ['init', '-q', '-b', 'main']);
        relWrite($root, ['composer.json' => json_encode(['autoload' => ['psr-4' => ['App\\' => 'src/']]], JSON_THROW_ON_ERROR), ...$base]);
        relGit($root, ['add', '-A']);
        relGit($root, ['commit', '-q', '-m', 'base']);

        foreach ($renamed as $new => $old) {
            if (! is_dir(dirname($root.'/'.$new))) {
                mkdir(dirname($root.'/'.$new), 0o755, true);
            }

            relGit($root, ['mv', $old, $new]);
        }

        relWrite($root, $change);

        foreach ($removed as $file) {
            unlink($root.'/'.$file);
        }

        relGit($root, ['add', '-A']);
        relGit($root, ['commit', '-q', '-m', 'change']);

        mkdir($root.'/vendor/bin', 0o777, true);
        mkdir($root.'/vendor/pestphp/pest-plugin-mutate', 0o777, true);
        file_put_contents($root.'/vendor/bin/pest', <<<'PHP'
            <?php
            $path = '';
            foreach ($argv as $argument) {
                if (str_starts_with($argument, '--path=')) {
                    $path = substr($argument, 7);
                }
            }
            fwrite(STDOUT, "RUN {$path} in ".basename(getcwd())."\n");
            $source = is_file($path) ? (string) file_get_contents($path) : '';
            if (preg_match('/report=(".*")/', $source, $report) === 1) {
                fwrite(STDOUT, json_decode($report[1], true));
            } elseif (preg_match('/killed=(\d+) survived=(\d+)/', $source, $counts) === 1) {
                [$killed, $survived] = [(int) $counts[1], (int) $counts[2]];
                $score = number_format(100 * $killed / max(1, $killed + $survived), 2, '.', '');
                for ($line = 1; $line <= min($survived, 3); $line++) {
                    fwrite(STDOUT, "   UNTESTED  {$path}  > Line {$line}: PlusToMinus - ID: 38fe59d773d1381{$line}\n");
                }
                fwrite(STDOUT, "  Mutations: {$survived} untested, {$killed} tested\n  Score:     {$score}%\n");
            }
            PHP);

        return $root;
    }
}

if (! function_exists('relReport')) {
    /**
     * The source of a file whose fake Pest report is the given text.
     */
    function relReport(string $report): string
    {
        return '<?php // report='.json_encode($report, JSON_THROW_ON_ERROR)."\n";
    }
}
