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

function fastStep(?array $changed, string $name): Step
{
    $steps = (new Plan(HarnessConfig::fromArray(['profile' => 'core']), harnessTempDir()))->fast($changed);

    return array_values(array_filter($steps, static fn (Step $step): bool => $step->name === $name))[0];
}

it('checks the style of the changed files only where git can say which they are', function (): void {
    expect(fastStep([], 'Code style')->action)->toContain('--dirty')
        ->and(fastStep([], 'Code style')->note)->toBeNull()
        ->and(fastStep([], 'Static analysis')->skipReason)->toBe('no PHP file changed');
});

it('falls back to the whole tree, and says so, where git cannot say which files changed', function (): void {
    $style = fastStep(null, 'Code style');
    $analysis = fastStep(null, 'Static analysis');

    expect($style->action)->not->toContain('--dirty')
        ->and($style->note)->toBe(Plan::GIT_UNUSABLE)
        ->and($analysis->skipReason)->toBeNull()
        ->and($analysis->action)->toBe(['vendor/bin/phpstan', 'analyse', '--no-progress', '--memory-limit=1G'])
        ->and($analysis->note)->toBe(Plan::GIT_UNUSABLE);
});

it('knows when git cannot be used', function (): void {
    $broken = harnessTempDir();
    file_put_contents($broken.'/.git', 'gitdir: /nonexistent/.git/worktrees/x');
    file_put_contents($broken.'/Foo.php', '<?php');

    $healthy = harnessTempDir();
    exec('git init -q '.escapeshellarg($healthy).' 2>&1', $ignored, $status);
    file_put_contents($healthy.'/Foo.php', '<?php');

    expect(Paths::gitUsable($broken))->toBeFalse()
        ->and(Paths::changedPhpFiles($broken))->toBeNull()
        ->and($status)->toBe(0)
        ->and(Paths::gitUsable($healthy))->toBeTrue()
        ->and(Paths::changedPhpFiles($healthy))->toBe(['Foo.php']);
});

/**
 * @return Step The 'Tests and architecture' step of a repository whose phpunit.xml holds $suites
 */
function testsStepWith(string $suites, bool $arch = true, ?string $coverage = null): Step
{
    $root = harnessTempDir();
    mkdir($root.'/tests/Unit', 0o755, true);
    mkdir($root.'/tests/Architecture', 0o755, true);

    if ($arch) {
        file_put_contents($root.'/tests/Arch.php', '<?php');
    }

    file_put_contents($root.'/phpunit.xml', "<phpunit><testsuites>{$suites}</testsuites></phpunit>");

    $steps = (new Plan(HarnessConfig::fromArray(['profile' => 'core']), $root))->full($coverage, null);

    return array_values(array_filter($steps, static fn (Step $step): bool => $step->name === 'Tests and architecture'))[0];
}

it('runs tests/Arch.php explicitly, and says so, when no suite of phpunit.xml holds it', function (): void {
    $step = testsStepWith('<testsuite name="Unit"><directory>tests/Unit</directory></testsuite>');

    expect($step->action)->toBe(['sh', '-c', "status=0; 'vendor/bin/pest' || status=1; 'vendor/bin/pest' 'tests/Arch.php' || status=1; exit \$status"])
        ->and($step->note)->toBe(Plan::ARCH_OUTSIDE_SUITES);
});

it('fails the gate when only the explicit architecture run fails', function (): void {
    $root = harnessTempDir();
    mkdir($root.'/vendor/bin', 0o755, true);
    mkdir($root.'/tests');
    file_put_contents($root.'/tests/Arch.php', '<?php');
    file_put_contents($root.'/phpunit.xml', '<phpunit><testsuites><testsuite name="Unit"><directory>tests/Unit</directory></testsuite></testsuites></phpunit>');
    file_put_contents($root.'/vendor/bin/pest', "#!/bin/sh\n[ \"\$1\" = tests/Arch.php ] && exit 1\nexit 0\n");
    chmod($root.'/vendor/bin/pest', 0o755);

    $steps = (new Plan(HarnessConfig::fromArray(['profile' => 'core']), $root))->full(null, null);
    $command = array_values(array_filter($steps, static fn (Step $step): bool => $step->name === 'Tests and architecture'))[0]->action;
    exec('cd '.escapeshellarg($root).' && '.implode(' ', array_map('escapeshellarg', $command)), result_code: $exitCode);

    expect($exitCode)->toBe(1);
});

it('keeps the coverage run for the suites and adds the architecture run without it', function (): void {
    $step = testsStepWith('<testsuite name="Unit"><directory>tests/Unit</directory></testsuite>', coverage: 'clover.xml');

    expect($step->action[2])->toContain("'--coverage-clover' 'clover.xml' || status=1; 'vendor/bin/pest' 'tests/Arch.php'");
});

it('runs tests/Arch.php once when a suite already holds it', function (string $suites): void {
    $step = testsStepWith($suites);

    expect($step->action)->toBe(['vendor/bin/pest'])
        ->and($step->note)->toBeNull();
})->with([
    'listed as a file' => ['<testsuite name="Architecture"><file>tests/Arch.php</file></testsuite>'],
    'a directory with the suffix' => ['<testsuite name="Architecture"><directory suffix="Arch.php">tests</directory></testsuite>'],
    'a file next to the unit suite' => ['<testsuite name="Unit"><directory>tests/Unit</directory></testsuite><testsuite name="Arch"><file>./tests/Arch.php</file></testsuite>'],
]);

it('still runs tests/Arch.php when a directory suite does not match its name or excludes it', function (string $suites): void {
    expect(testsStepWith($suites)->action[0])->toBe('sh');
})->with([
    'the default suffix is Test.php' => ['<testsuite name="All"><directory>tests</directory></testsuite>'],
    'excluded' => ['<testsuite name="All"><directory suffix=".php">tests</directory><exclude>tests/Arch.php</exclude></testsuite>'],
]);

it('has nothing to add where there is no tests/Arch.php', function (): void {
    expect(testsStepWith('<testsuite name="Unit"><directory>tests/Unit</directory></testsuite>', arch: false)->action)->toBe(['vendor/bin/pest']);
});

it('runs tests/Arch.php explicitly where there is no phpunit.xml at all', function (): void {
    $root = harnessTempDir();
    mkdir($root.'/tests');
    file_put_contents($root.'/tests/Arch.php', '<?php');

    $steps = (new Plan(HarnessConfig::fromArray(['profile' => 'core']), $root))->full(null, null);

    expect(array_values(array_filter($steps, static fn (Step $step): bool => $step->name === 'Tests and architecture'))[0]->action[0])->toBe('sh');
});

it('finds tests/Arch.php in the suites of this repository', function (): void {
    $steps = (new Plan(HarnessConfig::fromArray(['profile' => 'core']), dirname(__DIR__, 2)))->full(null, null);

    expect(array_values(array_filter($steps, static fn (Step $step): bool => $step->name === 'Tests and architecture'))[0]->action)->toBe(['vendor/bin/pest']);
});
