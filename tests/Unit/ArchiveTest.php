<?php

declare(strict_types=1);

use Pollora\Harness\Check\Plan;
use Pollora\Harness\Check\StepResult;
use Pollora\Harness\Config\HarnessConfig;
use Pollora\Harness\Distribution\Archive;
use Pollora\Harness\Scaffold\Scaffolder;

/**
 * A git repository with one commit holding these files.
 *
 * @param  array<string, string>  $files  Path => content
 */
function archiveRepository(array $files, string $profile = 'core'): string
{
    $root = harnessTempDir();
    HarnessConfig::fromArray(['profile' => $profile, 'mode' => 'enforce'])->save($root);

    foreach ($files as $path => $content) {
        if (! is_dir(dirname($root.'/'.$path))) {
            mkdir(dirname($root.'/'.$path), 0o755, true);
        }

        file_put_contents($root.'/'.$path, $content);
    }

    foreach ([['init', '-q'], ['add', '-A'], ['-c', 'user.name=t', '-c', 'user.email=t@example.com', 'commit', '-q', '-m', 'init']] as $arguments) {
        $process = proc_open(['git', ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        proc_close($process);
    }

    return $root;
}

/**
 * @return array{int, string}
 */
function archiveQa(string $cwd, string ...$arguments): array
{
    $command = array_merge([PHP_BINARY, dirname(__DIR__, 2).'/bin/pollora-qa'], $arguments, ['--no-ansi']);
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
    $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), (string) $output];
}

function archiveCommit(string $root): void
{
    foreach ([['add', '-A'], ['-c', 'user.name=t', '-c', 'user.email=t@example.com', 'commit', '-q', '-m', 'more']] as $arguments) {
        $process = proc_open(['git', ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        proc_close($process);
    }
}

function archiveStep(string $cwd, string $profile): StepResult|string
{
    foreach ((new Plan(HarnessConfig::fromArray(['profile' => $profile]), $cwd))->full(null, null) as $step) {
        if ($step->name === 'Distribution archive') {
            return $step->skipReason ?? ($step->action)();
        }
    }

    return 'no such step';
}

const ARCHIVE_PACKAGE = [
    'composer.json' => '{"name":"acme/pkg"}',
    'README.md' => '# pkg',
    'src/Thing.php' => '<?php',
    'tests/ThingTest.php' => '<?php',
    '.github/workflows/ci.yml' => 'on: push',
    'AGENTS.md' => '# agents',
    'phpstan.neon' => 'parameters:',
];

it('lists what git archive would hold, and flags the development files', function (): void {
    $entries = (new Archive(archiveRepository(ARCHIVE_PACKAGE)))->entries();

    expect($entries)->toContain('src/Thing.php', 'README.md', 'tests/ThingTest.php')
        ->and(Archive::offenders($entries ?? []))->toBe(['.github/workflows/ci.yml', '.harness.json', 'AGENTS.md', 'phpstan.neon', 'tests/ThingTest.php'])
        ->and(Archive::linesFor(Archive::offenders($entries ?? [])))->toBe(['/.gitattributes export-ignore', '/.github export-ignore', '/.harness.json export-ignore', '/AGENTS.md export-ignore', '/phpstan.neon export-ignore', '/tests export-ignore']);
});

it('sees an export-ignore on a directory, which a file-by-file check cannot', function (): void {
    $root = archiveRepository([...ARCHIVE_PACKAGE, '.gitattributes' => "/tests export-ignore\n/.github export-ignore\n/AGENTS.md export-ignore\n/phpstan.neon export-ignore\n/.harness.json export-ignore\n/.gitattributes export-ignore\n"]);
    $entries = (new Archive($root))->entries() ?? [];

    expect($entries)->toBe(['README.md', 'composer.json', 'src/Thing.php'])
        ->and(Archive::offenders($entries))->toBe([]);
});

it('adds the missing lines once, under one header, and keeps what is there', function (): void {
    $root = archiveRepository(['.gitattributes' => "* text=auto\n/tests export-ignore\n"]);
    $archive = new Archive($root);

    $added = $archive->ensure(['/tests export-ignore', '/AGENTS.md export-ignore', '/rector.php export-ignore']);
    $content = (string) file_get_contents($root.'/.gitattributes');

    expect($added)->toBe(['/AGENTS.md export-ignore', '/rector.php export-ignore'])
        ->and($content)->toStartWith("* text=auto\n/tests export-ignore\n# Pollora harness (QA-06)")
        ->and(substr_count($content, '# Pollora harness (QA-06)'))->toBe(1)
        ->and($archive->ensure(['/AGENTS.md export-ignore', '/rector.php export-ignore']))->toBe([])
        ->and((string) file_get_contents($root.'/.gitattributes'))->toBe($content);
});

it('fails the gate with the fix to apply, and passes once the lines are in', function (): void {
    $root = archiveRepository(ARCHIVE_PACKAGE);
    $failed = archiveStep($root, 'core');

    expect($failed)->toBeInstanceOf(StepResult::class)
        ->and($failed->isFailed())->toBeTrue()
        ->and($failed->detail)->toContain('5 development files', '/tests export-ignore', 'pollora-qa archive --fix');

    (new Archive($root))->ensure(Archive::linesFor(Archive::offenders((new Archive($root))->entries() ?? [])));

    $passed = archiveStep($root, 'core');

    expect($passed)->toBeInstanceOf(StepResult::class)
        ->and($passed->status)->toBe(StepResult::PASSED);
});

it('does not check a template or a project: the archive is the product', function (string $profile): void {
    expect(archiveStep(archiveRepository(ARCHIVE_PACKAGE, $profile), $profile))->toContain('its own product');
})->with(['template', 'project']);

it('skips instead of failing where no archive can be built', function (): void {
    $result = archiveStep(harnessTempDir(), 'core');

    expect($result)->toBeInstanceOf(StepResult::class)
        ->and($result->status)->toBe(StepResult::SKIPPED);
});

it('fixes .gitattributes from the command, then passes', function (): void {
    $root = archiveRepository(ARCHIVE_PACKAGE);

    [$code, $output] = archiveQa($root, 'archive');
    expect($code)->toBe(1)->and($output)->toContain('/tests export-ignore', '--fix');

    [$code, $output] = archiveQa($root, 'archive', '--fix');
    expect($code)->toBe(0)->and($output)->toContain('+ /tests export-ignore', 'holds no development file');

    [$code] = archiveQa($root, 'archive');
    expect($code)->toBe(0);

    // Committed, .gitattributes is a tracked file: it must not travel in the archive either.
    archiveCommit($root);
    [$code, $output] = archiveQa($root, 'archive');
    expect($code)->toBe(0)->and($output)->toContain('none for development');
});

it('has init keep the files it creates out of the archive of a package, and out of nothing for a template', function (): void {
    $package = harnessTempDir();
    file_put_contents($package.'/composer.json', '{"name":"acme/pkg","require":{"php":"^8.3"}}');
    mkdir($package.'/src');
    mkdir($package.'/tests');
    (new Scaffolder($package, HarnessConfig::fromArray(['profile' => 'core'])))->apply(5);
    $attributes = (string) file_get_contents($package.'/.gitattributes');

    expect($attributes)->toContain('/.harness.json export-ignore', '/AGENTS.md export-ignore', '/rector.php export-ignore', '/.claude export-ignore', '/tests export-ignore', '/.github export-ignore', '/api-surface.json export-ignore');

    (new Scaffolder($package, HarnessConfig::fromArray(['profile' => 'core'])))->apply(5);
    expect((string) file_get_contents($package.'/.gitattributes'))->toBe($attributes);

    $template = harnessTempDir();
    file_put_contents($template.'/composer.json', '{"name":"acme/theme","require":{"php":"^8.3"}}');
    (new Scaffolder($template, HarnessConfig::fromArray(['profile' => 'template'])))->apply(5);

    expect(file_exists($template.'/.gitattributes'))->toBeFalse();
});
