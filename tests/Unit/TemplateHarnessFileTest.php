<?php

declare(strict_types=1);

use Pollora\Harness\Check\Plan;
use Pollora\Harness\Config\HarnessConfig;
use Pollora\Harness\Config\InvalidConfig;
use Pollora\Harness\Console\Commands\CheckCommand;
use Pollora\Harness\Console\Commands\InitCommand;
use Pollora\Harness\Console\Commands\RatchetCommand;

/**
 * A template whose type coverage is 50 % (2 of 4 declarations), with the given harness file.
 *
 * @param  array<string, mixed>|null  $file  The content of the file; null for a template without one
 * @param  string  $path  Where the file sits
 */
if (! function_exists('templateWith')) {
    function templateWith(?array $file, string $path = '.harness.json'): string
    {
        $root = harnessTempDir();
        mkdir($root.'/app', 0o755, true);
        file_put_contents($root.'/composer.json', '{"name":"acme/theme","autoload":{"psr-4":{"Theme\\\\%theme_namespace%\\\\":"app/"}}}');
        file_put_contents($root.'/app/Sample.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Theme\\%theme_namespace%;\n\nfinal class Sample\n{\n    public function typed(int \$x): int\n    {\n        return \$x;\n    }\n\n    public function loose(\$x)\n    {\n        return \$x;\n    }\n}\n");

        if ($file !== null) {
            if (! is_dir(dirname($root.'/'.$path))) {
                mkdir(dirname($root.'/'.$path), 0o755, true);
            }
            file_put_contents($root.'/'.$path, json_encode($file, JSON_THROW_ON_ERROR));
        }

        return $root;
    }
}

it('holds the type coverage floor of a template: a floor that is respected passes', function (): void {
    $root = templateWith(['profile' => 'template', 'ratchet' => ['type_coverage' => 50.0]]);

    [$code, $out] = harnessRun(new CheckCommand, $root, '--template', '--enforce');

    expect($code)->toBe(0)
        ->and($out)->toContain('template (.harness.json) · enforce mode')->toContain('Type coverage (%)')->toContain('unchanged');
});

it('fails a drop below the floor in enforce mode, and only reports it in observe mode', function (): void {
    $root = templateWith(['ratchet' => ['type_coverage' => 80.0]]);

    [$enforced, $out] = harnessRun(new CheckCommand, $root, '--template', '--enforce');
    [$observed] = harnessRun(new CheckCommand, $root, '--template', '--observe');
    [$default] = harnessRun(new CheckCommand, $root, '--template');

    expect($enforced)->toBe(1)->and($out)->toContain('regressed')
        ->and($observed)->toBe(0)
        ->and($default)->toBe(0);
});

it('takes the mode from the workflow, not from the mode key of the file', function (): void {
    $root = templateWith(['mode' => 'observe', 'ratchet' => ['type_coverage' => 80.0]]);

    expect(harnessRun(new CheckCommand, $root, '--template', '--enforce')[0])->toBe(1);
});

it('blocks nothing when no floor is recorded', function (): void {
    $root = templateWith(['profile' => 'template']);

    [$code, $out] = harnessRun(new CheckCommand, $root, '--template', '--enforce');

    expect($code)->toBe(0)->and($out)->toContain('new');
});

it('refuses an invalid ratchet value, an unknown profile and a profile that is not a template one', function (array $file, string $message): void {
    $root = templateWith($file);

    expect(fn () => harnessRun(new CheckCommand, $root, '--template', '--enforce'))->toThrow(InvalidConfig::class, $message);
})->with([
    'a percentage above 100' => [['ratchet' => ['type_coverage' => 120]], 'type_coverage'],
    'a percentage that is not a number' => [['ratchet' => ['type_coverage' => 'high']], 'type_coverage'],
    'a profile that does not exist' => [['profile' => 'theme'], 'declares the profile "theme"'],
    'a package profile' => [['profile' => 'core'], 'use "template"'],
]);

it('accepts the project profile and the keys a template can use', function (): void {
    $root = templateWith(['profile' => 'project', 'npm_audit' => ['allow' => []], 'slow_step_share' => 0.5, 'observed' => ['Ratchet']]);

    expect(harnessRun(new CheckCommand, $root, '--template', '--enforce')[0])->toBe(0);
});

it('does nothing, and writes nothing, for a template without the file', function (): void {
    $root = templateWith(null);

    [$code, $out] = harnessRun(new CheckCommand, $root, '--template', '--enforce');

    expect($code)->toBe(0)
        ->and($out)->toContain('nothing to check')->not->toContain('Ratchet')
        ->and(is_dir($root.'/.harness'))->toBeFalse();
});

it('reads .github/harness.json when there is no .harness.json, and prefers the root file', function (): void {
    $github = templateWith(['ratchet' => ['type_coverage' => 80.0]], '.github/harness.json');
    $both = templateWith(['ratchet' => ['type_coverage' => 10.0]]);
    mkdir($both.'/.github');
    file_put_contents($both.'/.github/harness.json', '{"ratchet":{"type_coverage":99}}');

    [$fromGithub, $out] = harnessRun(new CheckCommand, $github, '--template', '--enforce');

    expect($fromGithub)->toBe(1)->and($out)->toContain('(.github/harness.json)')
        ->and(HarnessConfig::locateForTemplate($both))->toBe('.harness.json')
        ->and(harnessRun(new CheckCommand, $both, '--template', '--enforce')[0])->toBe(0)
        ->and(HarnessConfig::locateForTemplate(templateWith(null)))->toBeNull();
});

it('names another file with --config', function (): void {
    $root = templateWith(['ratchet' => ['type_coverage' => 80.0]], 'quality.json');

    expect(harnessRun(new CheckCommand, $root, '--template', '--enforce', '--config=quality.json')[0])->toBe(1);
});

it('plans nothing but the ratchet for a template', function (): void {
    $steps = (new Plan(HarnessConfig::fromArray(['profile' => 'template']), harnessTempDir()))->template();

    expect(array_map(static fn ($step): string => $step->name, $steps))->toBe(['Ratchet']);
});

it('does not touch the checks of a package: a profile is still required there', function (): void {
    $root = templateWith(['ratchet' => ['type_coverage' => 50.0]]);

    expect(fn () => harnessRun(new CheckCommand, $root))->toThrow(InvalidConfig::class, 'declares the profile');
});

it('has init write the minimal file of a template: the profile and the floor rounded down to a tenth', function (): void {
    $root = templateWith(null);
    file_put_contents($root.'/app/Third.php', "<?php\n\nfinal class Third\n{\n    public function a(int \$x): int\n    {\n        return \$x;\n    }\n\n    public function b(\$x)\n    {\n        return \$x;\n    }\n\n    public function c(\$x): int\n    {\n        return 1;\n    }\n\n    public function d(int \$x): int\n    {\n        return \$x;\n    }\n}\n");

    [$code, $out] = harnessRun(new InitCommand, $root, '--profile=template');
    $files = array_values(array_diff(scandir($root) ?: [], ['.', '..']));
    sort($files);

    // Sample and Third: 7 of 12 declarations typed, 58.33 %, recorded as 58.3
    expect($code)->toBe(0)
        ->and(json_decode((string) file_get_contents($root.'/.harness.json'), true))->toBe(['profile' => 'template', 'ratchet' => ['type_coverage' => 58.3]])
        ->and($files)->toBe(['.harness.json', 'app', 'composer.json'])
        ->and($out)->toContain('Nothing else was created');
});

it('keeps what an existing template file has when init runs again, and adds only a missing floor', function (): void {
    $root = templateWith(['profile' => 'template', 'npm_audit' => ['allow' => []], 'ratchet' => ['type_coverage' => 12.0]]);

    harnessRun(new InitCommand, $root, '--profile=template');

    expect(json_decode((string) file_get_contents($root.'/.harness.json'), true)['ratchet']['type_coverage'])->toEqual(12);

    $without = templateWith(['profile' => 'template', 'observed' => ['Ratchet']]);
    harnessRun(new InitCommand, $without, '--profile=template');
    $saved = json_decode((string) file_get_contents($without.'/.harness.json'), true);

    expect($saved['observed'])->toBe(['Ratchet'])->and($saved['ratchet']['type_coverage'])->toEqual(50);
});

it('writes no floor when a template has no PHP to measure, and writes where --config says', function (): void {
    $root = harnessTempDir();

    [$code, $out] = harnessRun(new InitCommand, $root, '--profile=template', '--config=.github/harness.json');

    expect($code)->toBe(0)
        ->and($out)->toContain('no PHP to measure')
        ->and(json_decode((string) file_get_contents($root.'/.github/harness.json'), true))->toBe(['profile' => 'template']);
});

it('has ratchet --update tighten the file of a template, without adding a mode, and keep the keys it does not know', function (): void {
    $root = templateWith(['ratchet' => ['type_coverage' => 10.0], 'npm_audit' => ['allow' => []], 'future_key' => ['a' => 1]], '.github/harness.json');

    [$code, $out] = harnessRun(new RatchetCommand, $root, '--update');
    $saved = json_decode((string) file_get_contents($root.'/.github/harness.json'), true);

    expect($code)->toBe(0)
        ->and($out)->toContain('.github/harness.json tightened.')
        ->and($saved['ratchet']['type_coverage'])->toEqual(50)
        ->and($saved)->not->toHaveKey('mode')
        ->and($saved['future_key'])->toBe(['a' => 1])
        ->and($saved['profile'])->toBe('template');
});

it('has ratchet read a root file that leaves the profile out, and still tells a package which profile is missing', function (): void {
    $template = templateWith(['ratchet' => ['type_coverage' => 80.0]]);

    expect(harnessRun(new RatchetCommand, $template)[0])->toBe(1)
        ->and(fn () => harnessRun(new RatchetCommand, $template, '--config=missing.json'))->toThrow(InvalidConfig::class, 'not found');

    $package = harnessTempDir();
    file_put_contents($package.'/.harness.json', '{"profile":"nope"}');

    expect(fn () => harnessRun(new RatchetCommand, $package))->toThrow(InvalidConfig::class, 'declares the profile');
});

it('keeps the job names, needs and if of template.yml, with the Result job alone depending on the others', function (): void {
    $text = (string) file_get_contents(dirname(__DIR__, 2).'/.github/workflows/template.yml');
    $section = substr($text, (int) strpos($text, "\njobs:\n"));
    $jobs = [];

    foreach (preg_split('/^  (?=[a-z-]+:\n)/m', $section) ?: [] as $block) {
        if (preg_match('/^([a-z-]+):\n/', $block, $id) !== 1) {
            continue;
        }

        $jobs[$id[1]] = [
            'name' => preg_match('/^    name: (.+)$/m', $block, $m) === 1 ? $m[1] : '',
            'needs' => preg_match('/^    needs: (.+)$/m', $block, $m) === 1 ? $m[1] : '',
            'if' => preg_match('/^    if: (.+)$/m', $block, $m) === 1 ? $m[1] : '',
            'continue' => preg_match('/^    continue-on-error: (.+)$/m', $block, $m) === 1 ? $m[1] : '',
        ];
    }

    $result = $jobs['result'];
    unset($jobs['result']);

    expect($result['name'])->toBe('Result')
        ->and($result['if'])->toBe('always()')
        ->and(array_keys($jobs))->toBe(['secrets', 'style', 'security', 'audit', 'npm-audit', 'ratchet'])
        ->and(array_map(static fn (array $job): string => $job['name'], $jobs))->toBe([
            'secrets' => 'Secrets (SEC-08)',
            'style' => 'Code style',
            'security' => 'Security patterns (SEC-01 to SEC-07, CONV-10)',
            'audit' => 'Dependency audit (SEC-09)',
            'npm-audit' => 'Dependency audit (npm)',
            'ratchet' => 'Ratchet (QA-01)',
        ])
        ->and(array_values(array_unique(array_map(static fn (array $job): string => $job['needs'].'|'.$job['if'], $jobs))))->toBe(['|'])
        ->and($jobs['ratchet']['continue'])->toBe("\${{ inputs.mode != 'enforce' || contains(inputs.observed, 'ratchet') }}")
        ->and($jobs['npm-audit']['continue'])->toBe('true');
});

it('runs every step of the ratchet job after the file test only when the file is there', function (): void {
    $text = (string) file_get_contents(dirname(__DIR__, 2).'/.github/workflows/template.yml');
    $job = substr($text, (int) strpos($text, "\n  ratchet:\n"));
    // the ratchet job alone, without the Result job that follows it
    $job = substr($job, 0, (int) strpos($job, "\n  result:\n"));
    $afterFile = substr($job, (int) strpos($job, 'id: file'));
    $steps = preg_split('/^      - /m', $afterFile) ?: [];
    array_shift($steps);

    expect($steps)->not->toBeEmpty();

    foreach ($steps as $step) {
        expect($step)->toContain("if: steps.file.outputs.present == 'true'");
    }

    expect($job)->toContain('pollora-qa check --template')->not->toMatch('/^\s+(?:run: |\s*)composer install/m');
});

it('documents the optional file of a template', function (): void {
    expect((string) file_get_contents(dirname(__DIR__, 2).'/README.md'))->toContain('A .harness.json for a template');
});
