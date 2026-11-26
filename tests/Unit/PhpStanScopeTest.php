<?php

declare(strict_types=1);

use Pollora\Harness\Check\Plan;
use Pollora\Harness\Check\Step;
use Pollora\Harness\Config\HarnessConfig;
use Pollora\Harness\Console\Command;
use Pollora\Harness\Console\Commands\HookCommand;
use Pollora\Harness\Support\PhpStanScope;

/** A file PHPStan reports an error in at any level. */
const SCOPE_BROKEN = "<?php\n\ndeclare(strict_types=1);\n\nundefined_function_of_the_scope_test();\n";

/**
 * A project analysed by the real PHPStan of the harness, whose `paths` and `excludePaths` come from an included file.
 *
 * @param  list<string>  $files  Written with an error in each
 */
function scopeProject(array $files, ?string $parameters = null): string
{
    $root = harnessTempDir();
    $phpstan = dirname(__DIR__, 2).'/vendor/bin/phpstan';
    $parameters ??= "    level: 0\n    paths:\n        - ../src\n        - ../bootstrap.php\n    excludePaths:\n        - ../src/Excluded\n        - ../src/*Generated.php\n        - ../src/NotThere (?)\n";

    mkdir($root.'/vendor/bin', 0o755, true);
    file_put_contents($root.'/vendor/bin/phpstan', "#!/bin/sh\nexec ".escapeshellarg(PHP_BINARY).' '.escapeshellarg($phpstan)." \"\$@\"\n");
    chmod($root.'/vendor/bin/phpstan', 0o755);
    mkdir($root.'/config');
    file_put_contents($root.'/config/analysis.neon', "parameters:\n".$parameters);
    file_put_contents($root.'/phpstan.neon', "includes:\n    - config/analysis.neon\n");
    mkdir($root.'/src');

    foreach ($files as $file) {
        if (! is_dir(dirname($root.'/'.$file))) {
            mkdir(dirname($root.'/'.$file), 0o755, true);
        }

        file_put_contents($root.'/'.$file, SCOPE_BROKEN);
    }

    return $root;
}

/**
 * @param  list<string>  $changed
 */
function scopeStep(string $root, array $changed): Step
{
    $steps = (new Plan(HarnessConfig::fromArray(['profile' => 'core']), $root))->fast($changed);

    return array_values(array_filter($steps, static fn (Step $step): bool => $step->name === 'Static analysis'))[0];
}

function scopeHook(string $root, string $file): int
{
    $stdin = fopen('php://memory', 'w+');
    fwrite($stdin, (string) json_encode(['tool_input' => ['file_path' => $file]]));
    rewind($stdin);

    return harnessRun(new HookCommand($stdin), $root, 'post-edit')[0];
}

$files = ['src/Kept.php', 'src/Excluded/Inside.php', 'src/ThingGenerated.php', 'tests/ThingTest.php', 'bootstrap.php', 'srcother/Near.php'];

it('keeps the changed files PHPStan is configured to analyse, includes taken into account', function () use ($files): void {
    $root = scopeProject($files);

    expect(PhpStanScope::of($root)?->analysed($files))->toBe(['src/Kept.php', 'bootstrap.php']);
});

it('hands only those files to PHPStan in the fast gate', function () use ($files): void {
    $step = scopeStep(scopeProject($files), $files);

    expect($step->action)->toBe(['vendor/bin/phpstan', 'analyse', '--no-progress', '--memory-limit=1G', 'src/Kept.php', 'bootstrap.php']);
});

it('skips the static analysis, and says why, when no changed file is analysed', function (): void {
    $changed = ['tests/ThingTest.php', 'src/Excluded/Inside.php'];
    $step = scopeStep(scopeProject($changed), $changed);

    expect($step->skipReason)->toContain('no changed PHP file is among those PHPStan is configured to analyse');
});

it('leaves a file outside the analysis alone after an edit, and still reports one inside', function () use ($files): void {
    $root = scopeProject($files);

    expect(scopeHook($root, 'tests/ThingTest.php'))->toBe(Command::SUCCESS)
        ->and(scopeHook($root, 'src/Excluded/Inside.php'))->toBe(Command::SUCCESS)
        ->and(scopeHook($root, 'src/Kept.php'))->toBe(Command::BLOCK);
});

it('keeps every changed file when the configuration does not say', function (?string $parameters) use ($files): void {
    $root = scopeProject($files, $parameters);

    expect(PhpStanScope::of($root))->toBeNull()
        ->and(scopeStep($root, $files)->action)->toContain(...$files);
})->with([
    'no paths' => ["    level: 0\n"],
    'a configuration PHPStan refuses' => ["    level: 0\n    notAParameter: true\n"],
]);

it('keeps every changed file when PHPStan answers something else than its parameters', function (string $script): void {
    $root = harnessTempDir();
    mkdir($root.'/vendor/bin', 0o755, true);
    file_put_contents($root.'/vendor/bin/phpstan', "#!/bin/sh\n".$script."\n");
    chmod($root.'/vendor/bin/phpstan', 0o755);

    expect(PhpStanScope::of($root))->toBeNull();
})->with([
    'an unknown command' => ['echo "Command not defined" >&2; exit 1'],
    'not JSON' => ['echo "level: 5"'],
]);
