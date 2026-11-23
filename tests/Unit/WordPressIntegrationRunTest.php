<?php

declare(strict_types=1);

/*
 * The WordPress integration job, run without WordPress: the bootstrap template, the wp-config.php constants and the
 * command that starts the suite are taken from the workflow and the stubs as they are, in a small project, against a
 * fake `wp-load.php` that behaves like the real one on the two points that broke the first pilots (a constant
 * defined by wp-config.php, a class declared by WordPress).
 */

if (! function_exists('wpIntegrationWorkflow')) {
    /**
     * The text of the reusable workflow.
     */
    function wpIntegrationWorkflow(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2).'/.github/workflows/php-package.yml');
    }

    /**
     * Removes the indentation the YAML gives to a block (ten spaces).
     */
    function wpIntegrationDedent(string $block): string
    {
        return (string) preg_replace('/^ {10}/m', '', $block);
    }

    /**
     * The PHP the job gives to `wp config create --extra-php`, as it lands in wp-config.php.
     */
    function wpIntegrationConfigPhp(): string
    {
        preg_match("/--extra-php <<'PHP'\n(.*?)\n {10}PHP\n/s", wpIntegrationWorkflow(), $match);

        return wpIntegrationDedent($match[1]);
    }

    /**
     * The shell script of the "Run the integration tests" step.
     */
    function wpIntegrationRunScript(): string
    {
        preg_match('/- name: Run the integration tests\n.*?        run: \|\n(.*?)\n\n/s', wpIntegrationWorkflow(), $match);

        return wpIntegrationDedent($match[1]);
    }

    /**
     * Builds a project, runs the shell script in it and returns the exit code and the output.
     *
     * The project is under /tmp because the namespace Pest generates from a test path cannot have a segment that starts
     * with a digit. Its `tests/Pest.php` declares a double of `WP_Error` the way a unit suite does (`$doubleInPest`),
     * and the fake WordPress declares the real one after defining the constants of wp-config.php.
     *
     * @param  string  $script  The shell script to run in the project
     * @param  bool  $doubleInPest  Whether tests/Pest.php declares the WP_Error double
     * @param  bool  $withScript  Whether composer.json declares a test:integration script (never run: composer is absent)
     * @return array{int, string} Exit code and output
     */
    function wpIntegrationProject(string $script, bool $doubleInPest = true, bool $withScript = false): array
    {
        $harness = dirname(__DIR__, 2);
        $root = '/tmp/pollora-harness-wpint-'.bin2hex(random_bytes(5));

        foreach (['tests/Integration', 'wordpress', 'vendor/bin', 'vendor/pestphp/pest/bin'] as $directory) {
            mkdir("{$root}/{$directory}", 0o755, true);
        }

        $stubs = $harness.'/stubs/wordpress';
        copy($stubs.'/bootstrap.php.stub', $root.'/tests/Integration/bootstrap.php');
        copy($stubs.'/phpunit.integration.xml.stub', $root.'/phpunit.integration.xml');
        file_put_contents($root.'/tests/Integration/RealWordPressTest.php', "<?php\n\ndeclare(strict_types=1);\n\nit('runs against the WP_Error of WordPress, not the double', function (): void {\n    expect((new WP_Error)->origin)->toBe('wordpress')->and(DISABLE_WP_CRON)->toBeTrue();\n});\n");
        file_put_contents($root.'/composer.json', json_encode(['name' => 'acme/package', 'scripts' => $withScript ? ['test:integration' => 'exit 7'] : new stdClass], JSON_THROW_ON_ERROR));

        if ($doubleInPest) {
            file_put_contents($root.'/tests/Pest.php', "<?php\n\ndeclare(strict_types=1);\n\nclass WP_Error\n{\n    public string \$origin = 'double';\n}\n");
        }

        file_put_contents($root.'/wordpress/wp-load.php', "<?php\n\n".wpIntegrationConfigPhp()."\nclass WP_Error\n{\n    public string \$origin = 'wordpress';\n}\n");

        // Pest takes as its root the directory above the vendor folder that holds its binary.
        $autoload = $harness.'/vendor/autoload.php';
        copy($harness.'/vendor/pestphp/pest/bin/pest', $root.'/vendor/pestphp/pest/bin/pest');
        file_put_contents($root.'/vendor/autoload.php', "<?php\n\nreturn require '{$autoload}';\n");
        file_put_contents($root.'/vendor/bin/pest', "#!/bin/sh\nexec ".escapeshellarg(PHP_BINARY)." -d memory_limit=2G -d display_errors=1 -d error_reporting=-1 \"\$(dirname \"\$0\")/../pestphp/pest/bin/pest\" --colors=never \"\$@\"\n");
        chmod($root.'/vendor/bin/pest', 0o755);
        file_put_contents($root.'/script.sh', $script);

        $process = proc_open(['bash', $root.'/script.sh'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, [...getenv(), 'COLUMNS' => '200', 'SUITE' => 'tests/Integration', 'WP_PATH' => $root.'/wordpress', 'TMPDIR' => $root]);
        $output = (string) stream_get_contents($pipes[1]);
        $output .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $status = proc_close($process);
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);

        foreach ($files as $file) {
            $file->isDir() && ! $file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }

        rmdir($root);

        return [$status, $output];
    }
}

it('defines no constant of wp-config.php twice: every define is guarded', function (): void {
    preg_match_all('/^(?!defined\()define\(/m', wpIntegrationConfigPhp(), $unguarded);

    expect($unguarded[0])->toBe([])
        ->and(wpIntegrationConfigPhp())->toContain("defined('DISABLE_WP_CRON') || define('DISABLE_WP_CRON', true);");
});

it('loads the bootstrap template before a wp-config.php that defines DISABLE_WP_CRON, without a warning', function (): void {
    [$status, $output] = wpIntegrationProject(wpIntegrationRunScript(), doubleInPest: false);

    expect($output)->not->toContain('already defined')->not->toContain('Warning')
        ->and($output)->toContain('1 passed')
        ->and($status)->toBe(0);
});

it('runs the suite without loading tests/Pest.php, so its doubles of WordPress never meet the real one', function (): void {
    [$status, $output] = wpIntegrationProject(wpIntegrationRunScript());

    expect($output)->not->toContain('Cannot redeclare')
        ->and($output)->toContain('1 passed')
        ->and($status)->toBe(0);
});

it('leaves the repository its own command when composer.json has a test:integration script', function (): void {
    $script = str_replace('composer run-script test:integration', 'echo "own command"; exit 7', wpIntegrationRunScript());
    [$status, $output] = wpIntegrationProject($script, withScript: true);

    expect($output)->toContain('own command')->not->toContain('passed')
        ->and($status)->toBe(7);
});

it('keeps running a suite with no configuration file by its directory alone', function (): void {
    expect(wpIntegrationRunScript())->toContain('vendor/bin/pest "$SUITE"');
});

it('ships a well-formed PHPUnit configuration for the integration suite', function (): void {
    $previous = libxml_use_internal_errors(true);
    $xml = simplexml_load_file(dirname(__DIR__, 2).'/stubs/wordpress/phpunit.integration.xml.stub');
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    expect($xml)->toBeInstanceOf(SimpleXMLElement::class)
        ->and((string) $xml['bootstrap'])->toBe('tests/Integration/bootstrap.php');
});
