<?php

declare(strict_types=1);

/**
 * The reusable workflow's jobs, read from the text: name, `needs` and `if` of each one.
 *
 * @return array<string, array{name: string, needs: string, if: string}>
 */
if (! function_exists('workflowJobs')) {
    function workflowJobs(): array
    {
        $text = (string) file_get_contents(dirname(__DIR__, 2).'/.github/workflows/php-package.yml');
        $section = substr($text, (int) strpos($text, "\njobs:\n"));
        $jobs = [];

        foreach (preg_split('/^  (?=[a-z]+:\n)/m', $section) ?: [] as $block) {
            if (preg_match('/^([a-z]+):\n/', $block, $id) !== 1) {
                continue;
            }

            $jobs[$id[1]] = [
                'name' => preg_match('/^    name: (.+)$/m', $block, $m) === 1 ? $m[1] : '',
                'needs' => preg_match('/^    needs: (.+)$/m', $block, $m) === 1 ? $m[1] : '',
                'if' => preg_match('/^    if: (.+)$/m', $block, $m) === 1 ? $m[1] : '',
            ];
        }

        return $jobs;
    }
}

it('keeps the name, needs and if of every job that existed before the WordPress job', function (): void {
    $jobs = workflowJobs();

    expect($jobs['setup'])->toBe(['name' => 'Read .harness.json', 'needs' => '', 'if' => ''])
        ->and($jobs['quality']['name'])->toBe('Quality gates (${{ needs.setup.outputs.profile }}, ${{ needs.setup.outputs.mode }})')
        ->and($jobs['tests']['name'])->toBe('Tests PHP ${{ matrix.php }} (${{ matrix.stability }})')
        ->and($jobs['contract']['name'])->toBe('PHP API compatibility (QA-05)')
        ->and($jobs['secrets']['name'])->toBe('Secrets (SEC-08)')
        ->and($jobs['downstream'])->toBe(['name' => 'Downstream — framework with this change (QA-03)', 'needs' => 'setup', 'if' => "contains(needs.setup.outputs.downstream, 'framework')"])
        ->and(array_keys($jobs))->toBe(['setup', 'quality', 'tests', 'wordpress', 'contract', 'secrets', 'downstream']);

    foreach (['quality', 'tests', 'contract', 'secrets'] as $job) {
        expect($jobs[$job]['needs'])->toBe('setup')
            ->and($jobs[$job]['if'])->toBe('');
    }
});

it('skips the WordPress job while wordpress-integration is empty, and nothing waits for it', function (): void {
    $jobs = workflowJobs();
    $text = (string) file_get_contents(dirname(__DIR__, 2).'/.github/workflows/php-package.yml');

    expect($jobs['wordpress']['name'])->toBe('WordPress integration PHP ${{ matrix.php }}')
        ->and($jobs['wordpress']['if'])->toBe("inputs.wordpress-integration != ''")
        ->and($text)->toMatch("/wordpress-integration:\n(?:.*\n)*?        type: string\n        default: ''/")
        ->and($text)->not->toMatch('/needs:.*\bwordpress\b/');
});

it('ships the bootstrap templates the workflow and the README name', function (): void {
    $root = dirname(__DIR__, 2);

    foreach (['bootstrap.php.stub', 'phpunit.integration.xml.stub', 'WordPressLoadedTest.php.stub', 'unit-bootstrap.php.stub'] as $file) {
        expect($root.'/stubs/wordpress/'.$file)->toBeFile();
    }

    expect((string) file_get_contents($root.'/stubs/wordpress/WordPressLoadedTest.php.stub'))->toContain("function_exists('register_post_meta')", 'get_bloginfo')
        ->and((string) file_get_contents($root.'/README.md'))->toContain('## Integration tests against a real WordPress');
});

/**
 * Runs the "Install WP-CLI" script of the workflow with a `curl` that writes a small program in place of the archive.
 *
 * @return array{int, string, string} exit code, output, content of GITHUB_PATH
 */
function runWpCliInstall(string $archive, string $checksum): array
{
    preg_match('/- name: Install WP-CLI\n.*?        run: \|\n(.*?)\n\n/s', (string) file_get_contents(dirname(__DIR__, 2).'/.github/workflows/php-package.yml'), $match);
    $dir = sys_get_temp_dir().'/harness-wpcli-'.bin2hex(random_bytes(4));
    mkdir($dir.'/fake', 0o777, true);
    file_put_contents($dir.'/archive', $archive);
    file_put_contents($dir.'/fake/curl', "#!/bin/sh\nwhile [ \"\$1\" != --output ]; do shift; done\ncp '{$dir}/archive' \"\$2\"\n");
    chmod($dir.'/fake/curl', 0o755);
    file_put_contents($dir.'/step.sh', preg_replace('/^ {10}/m', '', $match[1]));
    touch($dir.'/path');

    $environment = sprintf('PATH=%s:"$PATH" RUNNER_TEMP=%s GITHUB_PATH=%s WP_CLI_VERSION=2.12.0 WP_CLI_SHA512=%s', escapeshellarg($dir.'/fake'), escapeshellarg($dir), escapeshellarg($dir.'/path'), escapeshellarg($checksum));
    exec($environment.' bash '.escapeshellarg($dir.'/step.sh').' 2>&1', $output, $code);
    $path = (string) file_get_contents($dir.'/path');
    exec('rm -rf '.escapeshellarg($dir));

    return [$code, implode("\n", $output), $path];
}

it('installs WP-CLI itself, pinned, and not through setup-php, whose failure goes unnoticed', function (): void {
    $text = (string) file_get_contents(dirname(__DIR__, 2).'/.github/workflows/php-package.yml');

    expect($text)->not->toContain('tools: wp-cli')
        ->toMatch("/WP_CLI_VERSION: '\\d+\\.\\d+\\.\\d+'\n          WP_CLI_SHA512: [0-9a-f]{128}\n/")
        ->toContain('--retry-all-errors');
});

it('puts WP-CLI on the path when the archive has the expected checksum, and stops otherwise', function (): void {
    $archive = "#!/bin/sh\necho 'WP-CLI 2.12.0'\n";
    [$code, $output, $path] = runWpCliInstall($archive, hash('sha512', $archive));

    expect($code)->toBe(0)->and($output)->toContain('WP-CLI 2.12.0')->and($path)->toContain('/wp-cli');

    [$code, $output, $path] = runWpCliInstall("#!/bin/sh\necho 'something else'\n", hash('sha512', $archive));

    expect($code)->toBe(1)->and($output)->toContain('expected checksum')->not->toContain('something else')->and($path)->toBe('');
});
