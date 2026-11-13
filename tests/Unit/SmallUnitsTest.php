<?php

declare(strict_types=1);

use Pollora\Harness\Config\HarnessConfig;
use Pollora\Harness\Config\InvalidConfig;
use Pollora\Harness\Metrics\Collector;
use Pollora\Harness\Profile;
use Pollora\Harness\Rules\Catalogue;
use Pollora\Harness\Support\Paths;
use Pollora\Harness\Support\Release;
use Pollora\Harness\Surface\SurfaceDiff;
use Pollora\Harness\Violation;

it('counts the entries of a PHP baseline, each for its count', function (): void {
    $cwd = harnessTempDir();
    file_put_contents($cwd.'/phpstan-baseline.php', "<?php\n\nreturn ['parameters' => ['ignoreErrors' => [['message' => 'a', 'count' => 3], ['message' => 'b'], 'not an entry']]];\n");

    expect((new Collector($cwd))->phpstanBaselineEntries())->toBe(5);
});

it('counts nothing for a PHP baseline that returns something else', function (): void {
    $cwd = harnessTempDir();
    file_put_contents($cwd.'/phpstan-baseline.php', "<?php\n\nreturn 'nothing';\n");

    expect((new Collector($cwd))->phpstanBaselineEntries())->toBe(0);
});

it('does not read a coverage it cannot parse, or that measured no statement', function (string $xml): void {
    $cwd = harnessTempDir();
    file_put_contents($cwd.'/clover.xml', $xml);
    // DOM complains about malformed XML with a warning; the report is read as "not measured"
    set_error_handler(static fn (): bool => true, E_WARNING);

    try {
        expect(Collector::cloverCoverage($cwd.'/clover.xml'))->toBeNull();
    } finally {
        restore_error_handler();
    }
})->with([
    'not XML' => ['this is not xml'],
    'no project' => ['<coverage/>'],
    'no metrics' => ['<coverage><project/></coverage>'],
    'no statement' => ['<coverage><project><metrics statements="0" coveredstatements="0"/></project></coverage>'],
]);

it('does not read a mutation score it cannot find', function (string $json): void {
    $cwd = harnessTempDir();
    file_put_contents($cwd.'/infection.json', $json);

    expect(Collector::infectionMsi($cwd.'/infection.json'))->toBeNull();
})->with([
    'not JSON' => ['nope'],
    'no stats' => ['{"other": 1}'],
    'a string score' => ['{"stats": {"msi": "high"}}'],
]);

it('reads the last metrics of a Clover report as the project total, and an integer score', function (): void {
    $cwd = harnessTempDir();
    file_put_contents($cwd.'/clover.xml', '<coverage><project><file><metrics statements="4" coveredstatements="4"/></file><metrics statements="8" coveredstatements="2"/></project></coverage>');
    file_put_contents($cwd.'/infection.json', '{"stats": {"msi": 80}}');

    expect(Collector::cloverCoverage($cwd.'/clover.xml'))->toBe(25.0)
        ->and(Collector::infectionMsi($cwd.'/infection.json'))->toBe(80.0);
});

it('has a PHPStan target level for each profile', function (Profile $profile, int|string $level): void {
    expect($profile->targetPhpstanLevel())->toBe($level);
})->with([
    [Profile::Core, 'max'],
    [Profile::WpPlugin, 'max'],
    [Profile::Framework, 8],
    [Profile::Integration, 8],
    [Profile::Template, 6],
    [Profile::Project, 6],
]);

it('knows which profiles may depend on the framework and which must stay free of Laravel', function (): void {
    expect(Profile::Core->mustStayLaravelFree())->toBeTrue()
        ->and(Profile::Framework->mustStayLaravelFree())->toBeFalse()
        ->and(Profile::Integration->mayDependOnFramework())->toBeTrue()
        ->and(Profile::Core->mayDependOnFramework())->toBeFalse()
        ->and(Profile::values())->toBe(['core', 'framework', 'integration', 'wp-plugin', 'template', 'project']);
});

it('reads a family of rules and the title of one rule, falling back to the identifier', function (): void {
    $family = Catalogue::family('ARCH');

    expect($family)->not->toBeEmpty()
        ->and(array_keys($family))->each->toStartWith('ARCH-')
        ->and(Catalogue::title('ARCH-02'))->toBe('Domain code calls no global WordPress function')
        ->and(Catalogue::title('NOPE-99'))->toBe('NOPE-99');
});

it('names the tag of a release and nothing for a branch', function (?string $version, ?string $tag): void {
    expect(Release::tag($version))->toBe($tag);
})->with([
    ['v0.1.23', 'v0.1.23'],
    ['0.1.23', 'v0.1.23'],
    ['dev-main', null],
    ['1.0.x-dev', null],
    [null, null],
]);

it('gives a workflow ref that is a tag or main', function (): void {
    expect(Release::workflowRef())->toMatch('/^(main|v\d+\.\d+\.\d+)$/');
});

it('rejects a .harness.json that is not JSON or not an object', function (string $contents, string $message): void {
    $cwd = harnessTempDir();
    file_put_contents($cwd.'/.harness.json', $contents);

    expect(fn () => HarnessConfig::load($cwd))->toThrow(InvalidConfig::class, $message);
})->with([
    'broken JSON' => ['{nope', 'is not valid JSON'],
    'a scalar' => ['42', 'the root must be an object'],
]);

it('ignores the optional settings that have the wrong type', function (): void {
    $config = HarnessConfig::fromArray([
        'profile' => 'core',
        'downstream' => 'framework',
        'allow_laravel' => 3,
        'observed' => ['Ratchet', 7],
        'allow_commands' => 'orders:export',
        'allow_rules' => 'ARCH-08',
        'ratchet' => 'strict',
    ]);

    expect($config->downstream)->toBe([])
        ->and($config->allowLaravel)->toBe([])
        ->and($config->observed)->toBe(['Ratchet'])
        ->and($config->allowCommands)->toBe([])
        ->and($config->allowRules)->toBe([]);
});

it('reports a missing .harness.json with the way to create it', function (): void {
    expect(fn () => HarnessConfig::load(harnessTempDir()))->toThrow(InvalidConfig::class, 'pollora-qa init');
});

it('lists a removed entry and a changed entry of the surface', function (): void {
    $diff = SurfaceDiff::between(
        ['hook:a' => 'action', 'hook:b' => 'action', 'hook:c' => 'action'],
        ['hook:a' => 'action', 'hook:b' => 'filter', 'hook:d' => 'action'],
    );

    expect($diff->lines())->toBe(['+ hook:d  action', '- hook:c  action', '~ hook:b  action  →  filter'])
        ->and($diff->requiredLabel())->toBe('breaking')
        ->and($diff->allowedBy('additions'))->toBeFalse()
        ->and($diff->allowedBy('all'))->toBeTrue();
});

it('needs no label for an unchanged surface and only api-change for an addition', function (): void {
    $same = SurfaceDiff::between(['hook:a' => 'action'], ['hook:a' => 'action']);
    $added = SurfaceDiff::between([], ['hook:a' => 'action']);

    expect($same->requiredLabel())->toBeNull()->and($same->allowedBy('none'))->toBeTrue()
        ->and($added->requiredLabel())->toBe('api-change')
        ->and($added->allowedBy('none'))->toBeFalse()
        ->and($added->allowedBy('additions'))->toBeTrue();
});

it('flags a required parameter added to an existing attribute as a break', function (): void {
    $diff = SurfaceDiff::between(
        ['attribute:A' => 'targets class'],
        ['attribute:A' => 'targets class', 'attribute:A($x)' => 'string, required'],
    );

    expect($diff->breaks())->toBeTrue()->and($diff->lines())->toBe(['! attribute:A($x)  string, required']);
});

it('prints a violation with its rule and the fix', function (): void {
    expect((string) new Violation('ARCH-01', 'The domain uses Illuminate.', 'Move it to infrastructure.'))
        ->toBe("[ARCH-01] The domain uses Illuminate.\n          Fix: Move it to infrastructure.");
});

it('finds an executable on the PATH only when it is executable', function (): void {
    $bin = harnessTempDir();
    file_put_contents($bin.'/runnable', '#!/bin/sh');
    chmod($bin.'/runnable', 0o755);
    file_put_contents($bin.'/inert', 'x');
    chmod($bin.'/inert', 0o644);
    $original = getenv('PATH');
    putenv('PATH='.$bin.PATH_SEPARATOR.'/no/such/dir');

    try {
        expect(Paths::which('runnable'))->toBe($bin.'/runnable')
            ->and(Paths::which('inert'))->toBeNull()
            ->and(Paths::which('absent'))->toBeNull();
    } finally {
        putenv('PATH='.$original);
    }
});

it('uses the Pint configuration of a repository only when it requires strict types', function (): void {
    $cwd = harnessTempDir();
    file_put_contents($cwd.'/pint.json', '{"rules": {"declare_strict_types": true}}');

    expect(Paths::pintConfig($cwd))->toBe($cwd.'/pint.json');

    file_put_contents($cwd.'/pint.json', '{"rules": {"declare_strict_types": false}}');

    expect(Paths::pintConfig($cwd))->toBe(Paths::preset('pint.json'));

    file_put_contents($cwd.'/pint.json', 'not json');

    expect(Paths::pintConfig($cwd))->toBe(Paths::preset('pint.json'));
});

it('lists the PHP files changed in a working tree: modified, staged and untracked, not vendored', function (): void {
    $cwd = harnessGitRepository(['a.php' => "<?php\n", 'b.php' => "<?php\n", 'notes.md' => 'x', 'vendor/v.php' => "<?php\n"]);
    file_put_contents($cwd.'/a.php', "<?php\n// changed\n");
    file_put_contents($cwd.'/new.php', "<?php\n");
    file_put_contents($cwd.'/vendor/v2.php', "<?php\n");
    file_put_contents($cwd.'/notes.md', 'changed');

    expect(Paths::changedPhpFiles($cwd))->toBe(['a.php', 'new.php'])
        ->and(Paths::changedPhpFiles(harnessTempDir()))->toBeNull()
        ->and(Paths::gitUsable($cwd))->toBeTrue();
});
