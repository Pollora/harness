<?php

declare(strict_types=1);

use Pollora\Harness\Check\Plan;
use Pollora\Harness\Check\Step;
use Pollora\Harness\Config\HarnessConfig;
use Pollora\Harness\Support\InterpreterFlags;
use Pollora\Harness\Support\Paths;

/**
 * @return list<string>
 */
function dependencyAnalysisCommand(string $cwd): array
{
    $steps = (new Plan(HarnessConfig::fromArray(['profile' => 'core']), $cwd))->full(null, null);

    foreach ($steps as $step) {
        if ($step->name === 'Dependency analysis' && is_array($step->action)) {
            return $step->action;
        }
    }

    return [];
}

it('analyses the dependencies with the harness preset where the repository has no configuration', function (): void {
    expect(dependencyAnalysisCommand(harnessTempDir()))->toBe([
        'vendor/bin/composer-dependency-analyser',
        '--config='.Paths::preset('composer-dependency-analyser.php'),
    ]);
});

it("keeps the repository's own dependency analyser configuration", function (): void {
    $cwd = harnessTempDir();
    file_put_contents($cwd.'/composer-dependency-analyser.php', '<?php return new ShipMonk\ComposerDependencyAnalyser\Config\Configuration;');

    expect(dependencyAnalysisCommand($cwd))->toBe(['vendor/bin/composer-dependency-analyser']);
});

function npmAuditStep(string $cwd): Step
{
    $steps = (new Plan(HarnessConfig::fromArray(['profile' => 'core']), $cwd))->full(null, null);

    return array_values(array_filter($steps, static fn ($step): bool => $step->name === Plan::NPM_AUDIT))[0];
}

it('skips the npm audit where the repository has no package-lock.json', function (): void {
    expect(npmAuditStep(harnessTempDir())->skipReason)->toBe('no package-lock.json to audit');
});

it('audits every package-lock.json except the vendored ones, and never blocks', function (): void {
    $cwd = harnessTempDir();

    foreach (['', 'tests/e2e/', 'node_modules/pkg/', 'vendor/pkg/'] as $directory) {
        is_dir($cwd.'/'.$directory) || mkdir($cwd.'/'.$directory, 0777, true);
        file_put_contents($cwd.'/'.$directory.'package-lock.json', '{}');
    }

    $step = npmAuditStep($cwd);

    if ($step->skipReason !== null) {
        expect($step->skipReason)->toBe('npm is not on the PATH');

        return;
    }

    expect($step->action[2])->toContain('--prefix \'.\'')
        ->toContain('--prefix \'tests/e2e\'')
        ->not->toContain('node_modules')
        ->not->toContain('vendor');
    expect(Plan::OBSERVED_BY_DEFAULT)->toContain(Plan::NPM_AUDIT);
});

it('blocks on the distribution archive gate like on the others, unless the repository lists it as observed', function (): void {
    expect(Plan::OBSERVED_BY_DEFAULT)->not->toContain(Plan::ARCHIVE)
        ->and(Plan::OBSERVED_BY_DEFAULT)->toContain(Plan::NPM_AUDIT);
});

it('hands the coverage driver of the parent process to the child', function (array $arguments, array $flags): void {
    expect(InterpreterFlags::from(...$arguments))->toBe($flags);
})->with([
    'xdebug passed with -d' => [[true, false, '', 'coverage', ''], ['-d', 'zend_extension=xdebug', '-d', 'xdebug.mode=coverage']],
    'xdebug without a mode' => [[true, false, '', '', ''], ['-d', 'zend_extension=xdebug']],
    'xdebug already in the ini files' => [[true, false, "; conf\nzend_extension=xdebug.so\n", 'coverage', ''], []],
    'xdebug commented out in the ini files' => [[true, false, ";zend_extension=xdebug.so\n", '', ''], ['-d', 'zend_extension=xdebug']],
    'pcov passed with -d' => [[false, true, '', '', '1'], ['-d', 'extension=pcov', '-d', 'pcov.enabled=1']],
    'pcov already in the ini files' => [[false, true, "extension=pcov\n", '', '1'], []],
    'no driver' => [[false, false, '', '', ''], []],
]);

it('runs the tests with the flags of the parent when --coverage is given', function (): void {
    $steps = (new Plan(HarnessConfig::fromArray(['profile' => 'core']), harnessTempDir()))->full('clover.xml', null);
    $tests = array_values(array_filter($steps, static fn (Step $step): bool => $step->name === 'Tests and architecture'))[0];
    $flags = InterpreterFlags::inherited();

    expect($tests->action)->toBe([...($flags === [] ? [] : [PHP_BINARY, ...$flags]), 'vendor/bin/pest', '--coverage-clover', 'clover.xml']);
});

it('runs the tests as they are without --coverage', function (): void {
    $steps = (new Plan(HarnessConfig::fromArray(['profile' => 'core']), harnessTempDir()))->full(null, null);
    $tests = array_values(array_filter($steps, static fn (Step $step): bool => $step->name === 'Tests and architecture'))[0];

    expect($tests->action)->toBe(['vendor/bin/pest']);
});
