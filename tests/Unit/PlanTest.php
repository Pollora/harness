<?php

declare(strict_types=1);

use Pollora\Harness\Check\Plan;
use Pollora\Harness\Check\Step;
use Pollora\Harness\Config\HarnessConfig;
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

it('starts the distribution archive gate observed, so that raising the harness turns no repository red', function (): void {
    expect(Plan::OBSERVED_BY_DEFAULT)->toContain(Plan::ARCHIVE);
});
