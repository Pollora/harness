<?php

declare(strict_types=1);

use Pollora\Harness\Config\InvalidConfig;
use Pollora\Harness\Console\Commands\PromoteReportCommand;
use Pollora\Harness\Rules\PromotionReport;

require_once __DIR__.'/../Support/CatalogueStatus.php';

/**
 * A repository (namespace Acme\Promo) whose files are given by relative path.
 *
 * @param  array<string, string>  $files  Path => PHP source (the opening tag is added)
 * @param  array<string, mixed>|null  $harness  The content of .harness.json, none when null
 */
function promoteProject(array $files, ?array $harness = null): string
{
    $root = harnessTempDir();

    foreach ($files as $path => $source) {
        is_dir(dirname("{$root}/{$path}")) || mkdir(dirname("{$root}/{$path}"), 0o755, true);
        file_put_contents("{$root}/{$path}", "<?php\n\ndeclare(strict_types=1);\n\n{$source}\n");
    }

    file_put_contents($root.'/composer.json', json_encode([
        'autoload' => ['psr-4' => ['Acme\\Promo\\' => 'src/']],
        'autoload-dev' => ['psr-4' => ['Acme\\Promo\\Tests\\' => 'tests/']],
    ], JSON_UNESCAPED_SLASHES));

    if ($harness !== null) {
        file_put_contents($root.'/.harness.json', json_encode($harness));
    }

    return $root;
}

/**
 * @return array<string, array{rule: string, title: string, verdict: string, count: int, first: list<string>, note: string, exempted: string|null}>
 */
function promoteRows(string $root): array
{
    $rows = [];

    foreach (PromotionReport::build($root) as $row) {
        $rows[$row['rule']] = $row;
    }

    return $rows;
}

it('says what would block for each observed rule, with the first problems', function (): void {
    $root = promoteProject([
        'src/Open.php' => "namespace Acme\\Promo;\n\nclass Open {}",
        'src/Closed.php' => "namespace Acme\\Promo;\n\nfinal class Closed {}",
        'tests/OldTest.php' => "namespace Acme\\Promo\\Tests;\n\nuse PHPUnit\\Framework\\TestCase;\n\nclass OldTest extends TestCase\n{\n    public function testIt(): void {}\n}",
    ]);
    $rows = promoteRows($root);

    expect(array_keys($rows))->toBe(['CONV-02', 'CONV-08', 'CONV-11', 'CONV-12'])
        ->and($rows['CONV-08']['verdict'])->toBe(PromotionReport::WOULD_BLOCK)
        ->and($rows['CONV-08']['count'])->toBe(1)
        ->and($rows['CONV-08']['first'][0])->toContain('Acme\\Promo\\Open is not final')
        ->and($rows['CONV-02']['verdict'])->toBe(PromotionReport::CLEAN)
        ->and($rows['CONV-12']['verdict'])->toBe(PromotionReport::WOULD_BLOCK)
        ->and($rows['CONV-12']['first'][0])->toContain('OldTest is a PHPUnit test class');
});

it('says CONV-02 would block a public method that takes a raw registration array', function (): void {
    $root = promoteProject([
        'src/PostTypes/Registrar.php' => "<?php\n\nnamespace Acme\\Promo\\PostTypes;\n\nfinal class Registrar\n{\n    public function register(string \$slug, array \$args): void\n    {\n        register_post_type(\$slug, \$args);\n    }\n}\n",
    ]);
    $row = promoteRows($root)['CONV-02'];

    expect($row['verdict'])->toBe(PromotionReport::WOULD_BLOCK)
        ->and($row['count'])->toBe(1)
        ->and($row['first'][0])->toContain('src/PostTypes/Registrar.php');
});

/**
 * The rows of a repository while PERF-01, PERF-07 and CONV-09 are listed as observed again: their probes stay, for the next rule that is born observed.
 *
 * @param  array<string, string>  $files
 * @return array<string, array{rule: string, title: string, verdict: string, count: int, first: list<string>, note: string, exempted: string|null}>
 */
function promoteRowsObserved(array $files): array
{
    return withCatalogueStatus('PERF-01', 'observed', fn (): array => withCatalogueStatus('PERF-07', 'observed', fn (): array => withCatalogueStatus('CONV-09', 'observed', fn (): array => promoteRows(promoteProject($files)))));
}

it('leaves out CONV-09, PERF-01 and PERF-07 now that they are enforced', function (): void {
    $rows = promoteRows(promoteProject(['src/Open.php' => "namespace Acme\\Promo;\n\nfinal class Open {}"]));

    expect($rows)->not->toHaveKey('CONV-09')->not->toHaveKey('PERF-01')->not->toHaveKey('PERF-07');
});

it('says PERF-01 and PERF-07 are not applicable when the repository declares neither, and reads them when it does', function (): void {
    $bare = promoteRowsObserved(['src/Open.php' => "namespace Acme\\Promo;\n\nfinal class Open {}"]);

    expect($bare['PERF-01']['verdict'])->toBe(PromotionReport::NOT_APPLICABLE)
        ->and($bare['PERF-07']['verdict'])->toBe(PromotionReport::NOT_APPLICABLE);

    $declared = promoteRowsObserved([
        'src/Open.php' => "namespace Acme\\Promo;\n\nfinal class Open {}",
        'tests/e2e/budgets.json' => '{"pages": {"home": {"wp": "many"}}}',
    ]);

    expect($declared['PERF-01']['verdict'])->toBe(PromotionReport::WOULD_BLOCK)
        ->and($declared['PERF-01']['first'][0])->toContain('PERF-01:')
        ->and($declared['PERF-07']['verdict'])->toBe(PromotionReport::NOT_APPLICABLE);
});

it('quotes five problems at most and counts the others', function (): void {
    $files = [];

    foreach (range(1, 8) as $number) {
        $files["src/C{$number}.php"] = "namespace Acme\\Promo;\n\nclass C{$number} {}";
    }

    $row = promoteRows(promoteProject($files))['CONV-08'];

    expect($row['count'])->toBe(8)->and($row['first'])->toHaveCount(PromotionReport::FIRST);

    [, $out] = harnessRun(new PromoteReportCommand, promoteProject($files), '--no-ansi');

    expect($out)->toContain('CONV-08  would block (8 problems)')->toContain('… and 3 more');
});

it('says "not applicable" when there is nothing to read, which is not a pass', function (): void {
    $rows = promoteRowsObserved([]);

    foreach (['CONV-02', 'CONV-08', 'CONV-09', 'CONV-12', 'CONV-11'] as $rule) {
        expect($rows[$rule]['verdict'])->toBe(PromotionReport::NOT_APPLICABLE)->and($rows[$rule]['note'])->not->toBe('');
    }

    [, $out] = harnessRun(new PromoteReportCommand, promoteProject([]), '--no-ansi');

    expect($out)->toContain('not applicable (nothing to read)');
});

it('reads the type coverage against the recorded floor, and blocks only below it', function (): void {
    $files = ['src/Untyped.php' => "namespace Acme\\Promo;\n\nfinal class Untyped\n{\n    public function a(\$x) {}\n\n    public function b(int \$y): void {}\n}"];

    $none = promoteRows(promoteProject($files))['CONV-11'];
    $below = promoteRows(promoteProject($files, ['profile' => 'core', 'ratchet' => ['type_coverage' => 90]]))['CONV-11'];
    $holds = promoteRows(promoteProject($files, ['profile' => 'core', 'ratchet' => ['type_coverage' => 10]]))['CONV-11'];

    expect($none['verdict'])->toBe(PromotionReport::CLEAN)->and($none['note'])->toContain('no floor recorded')
        ->and($below['verdict'])->toBe(PromotionReport::WOULD_BLOCK)->and($below['first'][0])->toContain('below the recorded floor of 90 %')
        ->and($holds['verdict'])->toBe(PromotionReport::CLEAN)->and($holds['note'])->toContain('floor 10 %');
});

it('mentions an exemption declared in allow_rules, which applies once the rule is enforced', function (): void {
    $root = promoteProject(['src/Open.php' => "namespace Acme\\Promo;\n\nclass Open {}"], ['profile' => 'core', 'allow_rules' => [['rule' => 'CONV-08', 'reason' => 'open for now']]]);
    [, $out] = harnessRun(new PromoteReportCommand, $root, '--no-ansi');

    expect(promoteRows($root)['CONV-08']['exempted'])->toBe('open for now')
        ->and($out)->toContain('allow_rules exempts it ("open for now")');
});

it('lists an observed rule it has no probe for, instead of leaving it out', function (): void {
    $rows = withCatalogueStatus('CONV-01', 'observed', fn (): array => promoteRows(promoteProject([])));

    expect($rows['CONV-01']['verdict'])->toBe(PromotionReport::NO_PROBE)
        ->and($rows['CONV-01']['note'])->toContain('no probe');
});

it('leaves out the rules that are enforced or planned', function (): void {
    $rows = promoteRows(promoteProject([]));

    expect($rows)->not->toHaveKey('ARCH-01')->not->toHaveKey('CONV-01');
});

it('prints the same report as JSON', function (): void {
    $root = promoteProject(['src/Open.php' => "namespace Acme\\Promo;\n\nclass Open {}"]);

    [$code, $out] = harnessRun(new PromoteReportCommand, $root, '--json');
    $data = json_decode($out, true, 512, JSON_THROW_ON_ERROR);

    expect($code)->toBe(0)
        ->and(array_column($data['rules'], 'rule'))->toBe(['CONV-02', 'CONV-08', 'CONV-11', 'CONV-12'])
        ->and($data['rules'][1])->toHaveKeys(['rule', 'title', 'verdict', 'count', 'first', 'note', 'exempted'])
        ->and($data['rules'][1]['verdict'])->toBe('would-block');
});

it('writes nothing in the repository and works without a .harness.json', function (): void {
    $root = promoteProject(['src/Open.php' => "namespace Acme\\Promo;\n\nclass Open {}"]);
    $snapshot = static function (string $root): array {
        $files = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
            $files[$file->getPathname()] = md5_file($file->getPathname());
        }

        ksort($files);

        return $files;
    };
    $before = $snapshot($root);

    [$code] = harnessRun(new PromoteReportCommand, $root);
    [$jsonCode] = harnessRun(new PromoteReportCommand, $root, '--json');

    expect($code)->toBe(0)->and($jsonCode)->toBe(0)->and($snapshot($root))->toBe($before);
});

it('reports a malformed .harness.json instead of guessing', function (): void {
    $root = promoteProject([], ['profile' => 'core', 'ratchet' => ['type_coverage' => 120]]);

    expect(fn () => PromotionReport::build($root))->toThrow(InvalidConfig::class, 'ratchet.type_coverage');
});

/**
 * A directory holding several repositories, one sub-directory each.
 *
 * @param  array<string, string>  $repositories  Name => root of an existing project
 */
function promoteWorkspace(array $repositories): string
{
    $workspace = harnessTempDir();

    foreach ($repositories as $name => $source) {
        exec('cp -R '.escapeshellarg($source).' '.escapeshellarg("{$workspace}/{$name}"));
    }

    return $workspace;
}

it('reports several repositories as one matrix, with the verdict of each rule, from paths', function (): void {
    $open = promoteProject(['src/Open.php' => "namespace Acme\\Promo;\n\nclass Open {}"]);
    $closed = promoteProject(['src/Closed.php' => "namespace Acme\\Promo;\n\nfinal class Closed {}"]);

    [$code, $out] = harnessRun(new PromoteReportCommand, harnessTempDir(), $open, $closed, '--json');
    $data = json_decode($out, true, 512, JSON_THROW_ON_ERROR);
    $cells = [];

    foreach ($data['matrix'] as $cell) {
        $cells[$cell['repository']][$cell['rule']] = [$cell['verdict'], $cell['count']];
    }

    expect($code)->toBe(0)
        ->and(array_keys($data['repositories']))->toBe([basename($open), basename($closed)])
        ->and($cells[basename($open)]['CONV-08'])->toBe(['would-block', 1])
        ->and($cells[basename($closed)]['CONV-08'])->toBe(['clean', 0])
        ->and($cells[basename($closed)]['CONV-12'])->toBe(['not-applicable', 0])
        ->and($data['repositories'][basename($open)]['rules'][1]['first'][0])->toContain('Open is not final');
});

it('walks --root, keeps the --only repositories, and prints a text matrix', function (): void {
    $workspace = promoteWorkspace([
        'beta' => promoteProject(['src/B.php' => "namespace Acme\\Promo;\n\nclass B {}"]),
        'alpha' => promoteProject(['src/A.php' => "namespace Acme\\Promo;\n\nfinal class A {}"]),
        'gamma' => promoteProject(['src/G.php' => "namespace Acme\\Promo;\n\nclass G {}"]),
    ]);
    mkdir("{$workspace}/notes");

    [$all, $text] = harnessRun(new PromoteReportCommand, harnessTempDir(), "--root={$workspace}", '--no-ansi');
    [$some, $json] = harnessRun(new PromoteReportCommand, harnessTempDir(), "--root={$workspace}", '--only=gamma,notes', '--json');
    $repositories = array_keys(json_decode($json, true, 512, JSON_THROW_ON_ERROR)['repositories']);

    expect($all)->toBe(0)
        ->and($text)->toContain('repository')->toContain('CONV-08')
        ->and(strpos($text, 'alpha'))->toBeLessThan(strpos($text, 'beta'))
        ->and($text)->not->toContain('notes')
        ->and($some)->toBe(0)
        ->and($repositories)->toBe(['gamma', 'notes']);
});

it('measures a directory without composer.json as not applicable instead of dropping it', function (): void {
    $workspace = promoteWorkspace([]);
    mkdir("{$workspace}/assets-only");

    [$code, $out] = harnessRun(new PromoteReportCommand, harnessTempDir(), "--root={$workspace}", '--only=assets-only', '--json');
    $data = json_decode($out, true, 512, JSON_THROW_ON_ERROR);

    expect($code)->toBe(0)
        ->and(array_unique(array_column($data['matrix'], 'verdict')))->toBe(['not-applicable'])
        ->and($data['repositories']['assets-only']['type_coverage'])->toBeNull();
});

it('gives the type coverage floor to record, rounded down to a tenth of a point', function (): void {
    $root = promoteProject(['src/Mixed.php' => "namespace Acme\\Promo;\n\nfinal class Mixed\n{\n    public function a(\$x): void {}\n\n    public function b(int \$y) {}\n}"]);

    [, $out] = harnessRun(new PromoteReportCommand, harnessTempDir(), $root, '--json');
    $entry = json_decode($out, true, 512, JSON_THROW_ON_ERROR)['repositories'][basename($root)];

    expect((float) $entry['type_coverage'])->toBe(50.0)->and((float) $entry['floor'])->toBe(50.0);

    $odd = promoteProject(['src/Odd.php' => "namespace Acme\\Promo;\n\nfinal class Odd\n{\n    public function a(int \$x): void {}\n\n    public function b(\$y) {}\n\n    public function c(\$z) {}\n}"]);
    [, $out] = harnessRun(new PromoteReportCommand, harnessTempDir(), $odd, '--json');
    $entry = json_decode($out, true, 512, JSON_THROW_ON_ERROR)['repositories'][basename($odd)];

    // 2 typed declarations out of 6: 33.33 % measured, 33.3 recorded (never 33.4, which a re-measure would fail).
    expect($entry['type_coverage'])->toBe(33.33)->and($entry['floor'])->toBe(33.3);
});

it('says which repository could not be read and fails, after printing the others', function (): void {
    $good = promoteProject(['src/A.php' => "namespace Acme\\Promo;\n\nfinal class A {}"]);
    $bad = promoteProject([], ['profile' => 'core', 'ratchet' => ['type_coverage' => 120]]);

    [$code, $out, $err] = harnessRun(new PromoteReportCommand, harnessTempDir(), $good, $bad, '/does/not/exist', '--json');
    $data = json_decode($out, true, 512, JSON_THROW_ON_ERROR);

    expect($code)->toBe(1)
        ->and($err)->toContain('ratchet.type_coverage')->toContain('not a directory')
        ->and($data['repositories'][basename($bad)]['error'])->toContain('ratchet.type_coverage')
        ->and($data['repositories'][basename($bad)]['rules'])->toBe([])
        ->and($data['repositories'][basename($good)]['error'])->toBeNull()
        ->and(array_column($data['matrix'], 'repository'))->not->toContain(basename($bad));
});

it('fails on a --root that is not a directory, on --root without value and on an unknown --only name', function (): void {
    $workspace = promoteWorkspace(['a' => promoteProject([])]);

    [$missing, , $missingErr] = harnessRun(new PromoteReportCommand, harnessTempDir(), '--root=/does/not/exist');
    [$bare, , $bareErr] = harnessRun(new PromoteReportCommand, harnessTempDir(), '--root');
    [$unknown, , $unknownErr] = harnessRun(new PromoteReportCommand, harnessTempDir(), "--root={$workspace}", '--only=a,zzz');

    expect($missing)->toBe(1)->and($missingErr)->toContain('is not a directory')
        ->and($bare)->toBe(1)->and($bareErr)->toContain('--root needs a directory')
        ->and($unknown)->toBe(1)->and($unknownErr)->toContain('zzz');
});

it('resolves relative paths against the current directory and changes nothing in the repositories', function (): void {
    $workspace = promoteWorkspace(['a' => promoteProject(['src/A.php' => "namespace Acme\\Promo;\n\nclass A {}"])]);
    $before = md5((string) shell_exec('find '.escapeshellarg($workspace).' -type f -exec md5 -q {} + 2>/dev/null || find '.escapeshellarg($workspace).' -type f -exec md5sum {} +'));

    [$code, $out] = harnessRun(new PromoteReportCommand, $workspace, 'a', '--json');
    $after = md5((string) shell_exec('find '.escapeshellarg($workspace).' -type f -exec md5 -q {} + 2>/dev/null || find '.escapeshellarg($workspace).' -type f -exec md5sum {} +'));

    expect($code)->toBe(0)->and(array_keys(json_decode($out, true, 512, JSON_THROW_ON_ERROR)['repositories']))->toBe(['a'])
        ->and($after)->toBe($before);
});

it('keeps the report of the current repository unchanged when no path is given', function (): void {
    [, $out] = harnessRun(new PromoteReportCommand, promoteProject(['src/A.php' => "namespace Acme\\Promo;\n\nclass A {}"]), '--json');
    $data = json_decode($out, true, 512, JSON_THROW_ON_ERROR);

    expect(array_keys($data))->toBe(['rules'])->and($data['rules'][1]['verdict'])->toBe('would-block');
});
