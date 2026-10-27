<?php

declare(strict_types=1);

use Pollora\Harness\Check\Plan;
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
