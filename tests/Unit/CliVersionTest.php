<?php

declare(strict_types=1);

/**
 * The `run:` script of a step of php-package.yml, found in one of its jobs.
 */
function versionScript(string $job, string $step): string
{
    $text = (string) file_get_contents(dirname(__DIR__, 2).'/.github/workflows/php-package.yml');
    $block = substr($text, (int) strpos($text, "\n  {$job}:\n"));
    $block = substr($block, (int) strpos($block, "name: {$step}\n"));

    if (preg_match('/^        run: (?:\|\n((?:          .*\n|\n)+)|(.+)\n)/m', $block, $match) !== 1) {
        throw new RuntimeException("No script for {$job} / {$step}");
    }

    return isset($match[2]) ? $match[2] : (string) preg_replace('/^ {10}/m', '', $match[1] ?? '');
}

/**
 * Runs a script the way GitHub runs a `run:` step, in a directory, with only these variables.
 *
 * @param  array<string, string>  $environment
 * @return array{int, string}
 */
function versionBash(string $script, string $cwd, array $environment): array
{
    $environment = ['PATH' => (string) getenv('PATH'), 'HOME' => $cwd, 'COMPOSER_HOME' => $cwd.'/.composer-home', ...$environment];
    $process = proc_open(['bash', '--noprofile', '--norc', '-eo', 'pipefail', '-c', $script], [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $cwd, $environment);
    $output = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    return [proc_close($process), $output];
}

const VERSION_REF = 'Pollora/harness/.github/workflows/php-package.yml@refs/tags/v0.1.36';

/**
 * What the setup job decides for a repository holding these files, called with this workflow ref.
 *
 * @param  array<string, array<mixed>>  $files  Name => content, written as JSON
 * @return array{pin: string, log: string}
 */
function versionChoice(array $files, string $workflowRef = VERSION_REF): array
{
    $root = harnessTempDir();

    foreach ($files as $name => $content) {
        file_put_contents($root.'/'.$name, json_encode($content, JSON_THROW_ON_ERROR));
    }

    [$code, $log] = versionBash(versionScript('setup', 'Choose the harness version'), $root, ['WORKFLOW_REF' => $workflowRef, 'GITHUB_OUTPUT' => $root.'/outputs']);
    expect($code)->toBe(0, $log);
    preg_match('/^pin=(.*)$/m', (string) file_get_contents($root.'/outputs'), $pin);

    return ['pin' => $pin[1] ?? 'NOT WRITTEN', 'log' => $log];
}

$required = ['require-dev' => ['pollora/harness' => '^0.1.35']];
$locked = static fn (string $version): array => ['packages' => [], 'packages-dev' => [['name' => 'pollora/harness', 'version' => $version]]];

it("pins a repository without composer.lock to the version of the workflow's tag", function () use ($required): void {
    expect(versionChoice(['composer.json' => $required])['pin'])->toBe('pollora/harness:0.1.36');
});

it('leaves a locked repository as it is, and warns when the lock and the tag differ', function () use ($required, $locked): void {
    $behind = versionChoice(['composer.json' => $required, 'composer.lock' => $locked('v0.1.35')]);
    $aligned = versionChoice(['composer.json' => $required, 'composer.lock' => $locked('v0.1.36')]);

    expect($behind['pin'])->toBe('')
        ->and($behind['log'])->toContain('::warning', 'composer.lock holds pollora/harness v0.1.35 and the workflow is called at v0.1.36')
        ->and($aligned['pin'])->toBe('')
        ->and(str_contains($aligned['log'], '::warning'))->toBeFalse();
});

it('leaves the version to Composer, and says why, when there is no tag to follow', function (string $ref, array $composer, string $said): void {
    $choice = versionChoice(['composer.json' => $composer], $ref);

    expect($choice['pin'])->toBe('')
        ->and($choice['log'])->toContain($said);
})->with([
    'called at a branch' => ['Pollora/harness/.github/workflows/php-package.yml@refs/heads/main', $required, 'not called at a release tag'],
    'called at a commit' => ['Pollora/harness/.github/workflows/php-package.yml@f26b214415a82d0b33d2746cd5965a61286b82b1', $required, 'not called at a release tag'],
    'no workflow ref' => ['', $required, '::warning title=Harness version::job.workflow_ref is empty'],
    'a development version' => [VERSION_REF, ['require-dev' => ['pollora/harness' => 'dev-main']], 'requires pollora/harness dev-main'],
    'the harness not required' => [VERSION_REF, ['require' => ['php' => '^8.3']], 'does not require pollora/harness'],
]);

it('reads the ref of its own file, not the one of the caller', function (): void {
    $text = (string) file_get_contents(dirname(__DIR__, 2).'/.github/workflows/php-package.yml');

    expect($text)->toContain('WORKFLOW_REF: ${{ job.workflow_ref }}')
        ->and(str_contains($text, 'github.workflow_ref }}'))->toBeFalse()
        ->and(substr_count($text, 'HARNESS_PIN: ${{ needs.setup.outputs.harness }}'))->toBe(3)
        ->and($text)->toContain('harness: ${{ steps.harness.outputs.pin }}');
});

/** The three installing steps of the workflow, with what each of them needs besides the pin. */
dataset('installing steps', [
    'quality' => ['quality', 'Install Composer dependencies', []],
    'tests, lowest' => ['tests', 'Install dependencies', ['STABILITY' => 'prefer-lowest']],
    'tests, stable' => ['tests', 'Install dependencies', ['STABILITY' => 'prefer-stable']],
    'wordpress' => ['wordpress', 'Install Composer dependencies', []],
]);

/**
 * Runs the real Composer over an offline repository holding three releases of the harness.
 *
 * @param  array<string, string>  $environment
 * @return array{int, string, string, bool} exit code, output, installed version, composer.json untouched
 */
function versionInstall(string $job, string $step, array $environment, string $constraint = '^0.1.35'): array
{
    $root = harnessTempDir();
    $releases = array_map(static fn (string $version): array => ['name' => 'pollora/harness', 'version' => $version, 'type' => 'metapackage'], ['v0.1.35', 'v0.1.36', 'v0.1.39']);
    $composer = (string) json_encode(['repositories' => [['packagist.org' => false], ['type' => 'package', 'package' => $releases]], 'require-dev' => ['pollora/harness' => $constraint]]);
    file_put_contents($root.'/composer.json', $composer);

    // The step's name in the tests job carries the matrix value: the name is matched up to it.
    $script = versionScript($job, $step === 'Install dependencies' ? 'Install dependencies (${{ matrix.stability }})' : $step);
    [$code, $output] = versionBash($script, $root, $environment);
    $lock = is_file($root.'/composer.lock') ? json_decode((string) file_get_contents($root.'/composer.lock'), true) : [];

    return [$code, $output, $lock['packages-dev'][0]['version'] ?? '', file_get_contents($root.'/composer.json') === $composer];
}

it('installs the pinned version, composer.json untouched', function (string $job, string $step, array $environment): void {
    [$code, $output, $installed, $untouched] = versionInstall($job, $step, [...$environment, 'HARNESS_PIN' => 'pollora/harness:0.1.36']);

    expect($code)->toBe(0, $output)
        ->and($installed)->toBe('v0.1.36')
        ->and($untouched)->toBeTrue();
})->with('installing steps');

it('installs as before without a pin', function (string $job, string $step, array $environment): void {
    [$code, $output, $installed] = versionInstall($job, $step, [...$environment, 'HARNESS_PIN' => '']);

    expect($code)->toBe(0, $output)
        ->and($installed)->toBe(($environment['STABILITY'] ?? '') === 'prefer-lowest' ? 'v0.1.35' : 'v0.1.39');
})->with('installing steps');

it('fails, rather than installing another version, when the constraint starts above the tag', function (): void {
    [$code, $output, $installed] = versionInstall('quality', 'Install Composer dependencies', ['HARNESS_PIN' => 'pollora/harness:0.1.35'], '^0.1.36');

    expect($code)->not->toBe(0)
        ->and($output)->toContain('must be a subset of the constraint')
        ->and($installed)->toBe('');
});
