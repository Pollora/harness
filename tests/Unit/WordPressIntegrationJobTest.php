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
