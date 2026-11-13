<?php

declare(strict_types=1);

use Pollora\Harness\Check\Plan;
use Pollora\Harness\Check\Step;
use Pollora\Harness\Check\StepResult;
use Pollora\Harness\Config\HarnessConfig;

beforeEach(function (): void {
    $this->originalPath = getenv('PATH');
});

afterEach(function (): void {
    putenv('PATH='.$this->originalPath);
});

/**
 * @param  list<string>  $tools  Executables to place on a PATH that holds nothing else
 */
function covPathWith(array $tools): string
{
    $bin = harnessTempDir();

    foreach ($tools as $tool) {
        file_put_contents($bin.'/'.$tool, "#!/bin/sh\nexit 0\n");
        chmod($bin.'/'.$tool, 0o755);
    }

    putenv('PATH='.$bin);

    return $bin;
}

function covStep(string $name, string $cwd, array $config = ['profile' => 'core'], bool $fast = false, ?string $coverage = null): Step
{
    $plan = new Plan(HarnessConfig::fromArray($config), $cwd);
    $steps = $fast ? $plan->fast([]) : $plan->full($coverage);

    return array_values(array_filter($steps, static fn (Step $step): bool => $step->name === $name))[0];
}

function covExecute(Step $step): StepResult
{
    return ($step->action)();
}

it('scans the source directories with semgrep and the common rules', function (): void {
    $bin = covPathWith(['semgrep']);
    $cwd = harnessTempDir();
    mkdir($cwd.'/src');
    mkdir($cwd.'/routes');

    $step = covStep('Security patterns', $cwd);

    expect($step->action)->toBeArray()
        ->and($step->action[0])->toBe($bin.'/semgrep')
        ->and($step->action)->toContain('src', 'routes')->not->toContain('app')
        ->and(implode(' ', $step->action))->toContain('presets/semgrep/common.yml')->not->toContain('framework.yml')
        ->and($step->rules)->not->toContain('ARCH-05');
});

it('adds the framework rules to the semgrep scan of the framework profile', function (): void {
    covPathWith(['semgrep']);
    $cwd = harnessTempDir();
    mkdir($cwd.'/src');

    $step = covStep('Security patterns', $cwd, ['profile' => 'framework']);

    expect(implode(' ', $step->action))->toContain('semgrep/framework.yml')
        ->and($step->rules)->toContain('ARCH-05');
});

it('skips the semgrep scan where there is no source directory, and flags a missing semgrep', function (): void {
    covPathWith(['semgrep']);
    $skipped = covStep('Security patterns', harnessTempDir());

    expect($skipped->skipReason)->toBe('no source directory to scan')->and($skipped->install)->toBeNull();

    covPathWith([]);
    $missing = covStep('Security patterns', harnessTempDir());

    expect($missing->install)->toBe('pipx install semgrep');
});

it('audits Composer with the binary found on the PATH, or says it is missing', function (): void {
    $bin = covPathWith(['composer']);

    expect(covStep('Dependency audit', harnessTempDir())->action)->toBe([$bin.'/composer', 'audit', '--no-interaction']);

    covPathWith([]);

    expect(covStep('Dependency audit', harnessTempDir())->install)->toBe('https://getcomposer.org/download/');
});

it('audits npm for every lock file, or flags npm missing when there is one to audit', function (): void {
    $bin = covPathWith(['npm']);
    $cwd = harnessTempDir();
    mkdir($cwd.'/e2e');
    file_put_contents($cwd.'/package-lock.json', '{}');
    file_put_contents($cwd.'/e2e/package-lock.json', '{}');

    $step = covStep(Plan::NPM_AUDIT, $cwd);

    expect($step->action[0])->toBe('sh')
        ->and($step->action[2])->toContain("'{$bin}/npm' audit --audit-level=high --prefix '.'")->toContain("--prefix 'e2e'");

    covPathWith([]);

    expect(covStep(Plan::NPM_AUDIT, $cwd)->install)->toBe('install Node.js (https://nodejs.org)');
});

it('passes the public surface gate where the code matches the record, and fails it where it does not', function (): void {
    $cwd = harnessTempDir();
    mkdir($cwd.'/src');
    file_put_contents($cwd.'/composer.json', '{"autoload": {"psr-4": {"Acme\\\\": "src/"}}}');
    file_put_contents($cwd.'/src/Fire.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Acme;\n\nfunction fire(): void\n{\n    do_action('pollora/orders/placed');\n}\n");

    expect(covStep('Public surface', $cwd)->skipReason)->toContain('api-surface.json not recorded yet');

    file_put_contents($cwd.'/api-surface.json', '{"version": 1, "surface": {"hook:pollora/orders/placed": "action"}}');
    $passed = covExecute(covStep('Public surface', $cwd));

    expect($passed->status)->toBe(StepResult::PASSED)->and($passed->detail)->toContain('unchanged');

    file_put_contents($cwd.'/api-surface.json', '{"version": 1, "surface": {"hook:pollora/orders/gone": "action"}}');
    $failed = covExecute(covStep('Public surface', $cwd));

    expect($failed->isFailed())->toBeTrue()->and($failed->detail)->toContain('breaking');
});

it('passes the archive gate for a clean repository and fails it with the fix, capping the list', function (): void {
    $clean = harnessGitRepository(['src/A.php' => "<?php\n", '.gitattributes' => ".gitattributes export-ignore\n"]);
    $passed = covExecute(covStep(Plan::ARCHIVE, $clean));

    expect($passed->status)->toBe(StepResult::PASSED)->and($passed->detail)->toContain('none for development');

    $files = ['src/A.php' => "<?php\n"];

    foreach (range(1, 10) as $number) {
        $files["tests/T{$number}Test.php"] = "<?php\n";
    }

    $dirty = harnessGitRepository($files);
    $failed = covExecute(covStep(Plan::ARCHIVE, $dirty));

    expect($failed->isFailed())->toBeTrue()
        ->and($failed->detail)->toContain('development files would travel')->toContain('… and 2 more')->toContain('archive --fix');
});

it('skips the archive gate where git cannot build an archive', function (): void {
    $result = covExecute(covStep(Plan::ARCHIVE, harnessTempDir()));

    expect($result->status)->toBe(StepResult::SKIPPED)->and($result->detail)->toContain('git archive cannot be built');
});

it('skips the archive gate for a template', function (): void {
    expect(covStep(Plan::ARCHIVE, harnessTempDir(), ['profile' => 'template'])->skipReason)->toContain('a template is its own product');
});

it('compares the ratchet with the report it is given, and fails on a regression', function (): void {
    $cwd = harnessTempDir();
    file_put_contents($cwd.'/clover.xml', '<coverage><project><metrics statements="10" coveredstatements="5"/></project></coverage>');
    $config = ['profile' => 'core', 'ratchet' => ['coverage' => 80.0]];

    $failed = covExecute(covStep('Ratchet', $cwd, $config, coverage: 'clover.xml'));

    expect($failed->isFailed())->toBeTrue()->and($failed->detail)->toContain('regressed')->not->toContain('read from');

    $passed = covExecute(covStep('Ratchet', $cwd, ['profile' => 'core', 'ratchet' => ['coverage' => 40.0]], coverage: 'clover.xml'));

    expect($passed->status)->toBe(StepResult::PASSED);
});

it('says where the coverage was read from when it was not asked for, and when no report exists', function (): void {
    $cwd = harnessTempDir();
    $config = ['profile' => 'core', 'ratchet' => ['coverage' => 40.0]];

    $missing = covExecute(covStep('Ratchet', $cwd, $config));

    expect($missing->detail)->toContain('no Clover report found');

    mkdir($cwd.'/build/logs', 0o755, true);
    file_put_contents($cwd.'/build/logs/clover.xml', '<coverage><project><metrics statements="10" coveredstatements="9"/></project></coverage>');
    $found = covExecute(covStep('Ratchet', $cwd, $config));

    expect($found->status)->toBe(StepResult::PASSED)->and($found->detail)->toContain('read from build/logs/clover.xml');
});

it('checks the dependency policy in process', function (): void {
    $cwd = harnessTempDir();
    file_put_contents($cwd.'/composer.json', '{"require": {"illuminate/support": "^13.0"}}');

    $failed = covExecute(covStep('Dependency policy', $cwd));

    expect($failed->isFailed())->toBeTrue()->and($failed->detail)->toContain('illuminate/support');

    file_put_contents($cwd.'/composer.json', '{"require": {"php": "^8.3"}}');

    expect(covExecute(covStep('Dependency policy', $cwd))->status)->toBe(StepResult::PASSED);
});

it('plans the fast gates for a list of changed files', function (): void {
    $plan = new Plan(HarnessConfig::fromArray(['profile' => 'core']), harnessTempDir());

    $steps = $plan->fast(['src/A.php', 'src/B.php']);
    $analysis = array_values(array_filter($steps, static fn (Step $step): bool => $step->name === 'Static analysis'))[0];

    expect($analysis->action)->toContain('src/A.php', 'src/B.php')
        ->and($steps[0]->action)->toContain('--dirty');
});
