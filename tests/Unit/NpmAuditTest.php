<?php

declare(strict_types=1);

use Pollora\Harness\Check\Plan;
use Pollora\Harness\Check\StepResult;
use Pollora\Harness\Config\HarnessConfig;
use Pollora\Harness\Config\InvalidConfig;
use Pollora\Harness\Config\NpmAuditSettings;
use Pollora\Harness\Console\Commands\NpmAuditCommand;
use Pollora\Harness\Npm\NpmAudit;
use Pollora\Harness\Npm\NpmAuditResult;
use Pollora\Harness\Support\Paths;

require_once __DIR__.'/../Support/NpmAuditFake.php';

const PICOMATCH = 'GHSA-fc5q-39xc-vjpf';

beforeEach(function (): void {
    $this->path = (string) getenv('PATH');
});

afterEach(function (): void {
    putenv('PATH='.$this->path);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array{advisory: string, reason: string, expires: string}
 */
function npmException(array $overrides = []): array
{
    return [...['advisory' => PICOMATCH, 'reason' => 'No patched picomatch exists yet', 'expires' => '2026-12-18'], ...$overrides];
}

function npmToday(): DateTimeImmutable
{
    return new DateTimeImmutable('2026-11-18', new DateTimeZone('UTC'));
}

function npmSettings(array ...$exceptions): NpmAuditSettings
{
    return NpmAuditSettings::fromArray(['allow' => $exceptions], '.harness.json', npmToday());
}

function npmAuditOf(string $root, NpmAuditSettings $settings, ?DateTimeImmutable $today = null): NpmAuditResult
{
    // The wait between two attempts is skipped: a test never sleeps.
    return (new NpmAudit($root, (string) Paths::which('npm'), $settings, $today ?? npmToday(), sleep: static function (int $seconds): void {}))->audit(NpmAudit::lockDirectories($root));
}

/**
 * A repository whose fake `npm` answers each call with the next fixture of the list (the last repeats) and counts its calls.
 *
 * @param  list<string>  $fixtures  Names of tests/Fixtures/npm-audit/<name>.json
 * @return array{string, string} The repository root and the directory of the fake, which holds the call counter
 */
function npmAuditSequence(array $fixtures): array
{
    $root = harnessTempDir();
    $bin = harnessTempDir();
    file_put_contents($root.'/package-lock.json', '{}');

    foreach ($fixtures as $index => $fixture) {
        copy(__DIR__.'/../Fixtures/npm-audit/'.$fixture.'.json', $bin.'/out-'.($index + 1));
    }

    file_put_contents($bin.'/last', (string) count($fixtures));
    file_put_contents($bin.'/npm', "#!/bin/sh\nd=\$(dirname \"\$0\")\nn=\$(cat \"\$d/count\" 2>/dev/null || echo 0)\nn=\$((n + 1))\necho \$n > \"\$d/count\"\n[ -f \"\$d/out-\$n\" ] || n=\$(cat \"\$d/last\")\ncat \"\$d/out-\$n\"\nexit 1\n");
    chmod($bin.'/npm', 0o755);
    putenv('PATH='.$bin.PATH_SEPARATOR.(string) getenv('PATH'));

    return [$root, $bin];
}

it('blocks a high advisory that no exception names', function (): void {
    $result = npmAuditOf(npmAuditRepository(['.' => 'picomatch']), new NpmAuditSettings);

    expect($result->passed())->toBeFalse()
        ->and($result->blocking)->toHaveCount(1)
        ->and($result->blocking[0]['id'])->toBe(PICOMATCH)
        ->and($result->render())->toContain('picomatch (high)', PICOMATCH);
});

it('blocks an advisory when the exception names another one', function (): void {
    $result = npmAuditOf(npmAuditRepository(['.' => 'picomatch']), npmSettings(npmException(['advisory' => 'GHSA-2222-3333-4444'])));

    expect($result->passed())->toBeFalse()->and($result->blocking)->toHaveCount(1)->and($result->unused)->toBe(['GHSA-2222-3333-4444']);
});

it('tolerates a listed advisory until its expiry and reports it with the reason and the date', function (): void {
    $result = npmAuditOf(npmAuditRepository(['.' => 'picomatch']), npmSettings(npmException()));

    expect($result->passed())->toBeTrue()
        ->and($result->ignored)->toHaveCount(1)
        ->and($result->render())->toContain('ignored '.PICOMATCH, 'No patched picomatch exists yet', 'until 2026-12-18')
        ->and($result->markdown())->toContain('| '.PICOMATCH.' | picomatch | No patched picomatch exists yet | 2026-12-18 |');
});

it('still tolerates the advisory on the day the exception expires', function (): void {
    $result = npmAuditOf(npmAuditRepository(['.' => 'picomatch']), npmSettings(npmException()), new DateTimeImmutable('2026-12-18 23:59:59', new DateTimeZone('UTC')));

    expect($result->passed())->toBeTrue();
});

it('fails an expired exception and says when it expired', function (): void {
    $result = npmAuditOf(npmAuditRepository(['.' => 'picomatch']), npmSettings(npmException()), new DateTimeImmutable('2026-12-19', new DateTimeZone('UTC')));

    expect($result->passed())->toBeFalse()
        ->and($result->ignored)->toBe([])
        ->and($result->expired)->toHaveCount(1)
        ->and($result->render())->toContain('exception expired on 2026-12-18')
        ->and($result->markdown())->toContain('exception expired on 2026-12-18');
});

it('judges the advisories, so the packages that only depend on the vulnerable one are covered too', function (): void {
    $root = npmAuditRepository(['.' => 'vite-transitive']);

    expect(npmAuditOf($root, new NpmAuditSettings)->blocking)->toHaveCount(1)
        ->and(npmAuditOf($root, npmSettings(npmException()))->passed())->toBeTrue();
});

it('lets the moderate and low advisories pass, as --audit-level=high does', function (): void {
    $result = npmAuditOf(npmAuditRepository(['.' => 'vite-transitive']), npmSettings(npmException()));

    expect($result->blocking)->toBe([])->and($result->ignored)->toHaveCount(1);
});

it('passes a report without any vulnerability', function (): void {
    $result = npmAuditOf(npmAuditRepository(['.' => 'clean']), new NpmAuditSettings);

    expect($result->passed())->toBeTrue()->and($result->render())->toContain('no high or critical advisory');
});

it('audits every lock file and reports each directory', function (): void {
    $root = npmAuditRepository(['.' => 'clean', 'tests/e2e' => 'picomatch']);

    $blocked = npmAuditOf($root, new NpmAuditSettings);
    $tolerated = npmAuditOf($root, npmSettings(npmException()));

    expect($blocked->blocking)->toHaveCount(1)
        ->and($blocked->blocking[0]['directory'])->toBe('tests/e2e')
        ->and($tolerated->passed())->toBeTrue()
        ->and($tolerated->ignored[0]['directory'])->toBe('tests/e2e');
});

it('fails where npm cannot audit, instead of reading a missing report as clean', function (string $raw): void {
    $result = npmAuditOf(npmAuditRepository(['.' => $raw]), npmSettings(npmException()));

    expect($result->passed())->toBeFalse()->and($result->failures)->toHaveCount(1);
})->with([
    'no output' => [''],
    'not JSON' => ['npm ERR! something'],
    'the registry cannot be reached' => [(string) file_get_contents(__DIR__.'/../Fixtures/npm-audit/offline.json')],
]);

it('says why npm could not audit', function (): void {
    $result = npmAuditOf(npmAuditRepository(['.' => 'offline']), new NpmAuditSettings);

    expect($result->render())->toContain('could not audit', 'EAI_AGAIN');
});

it('names an exception that matches nothing, so it can be removed', function (): void {
    $result = npmAuditOf(npmAuditRepository(['.' => 'clean']), npmSettings(npmException()));

    expect($result->passed())->toBeTrue()->and($result->unused)->toBe([PICOMATCH])->and($result->render())->toContain('remove the exception')->and($result->markdown())->toContain('remove the exception');
});

it('refuses an exception without a valid advisory, reason or date', function (array $override, string $message): void {
    expect(fn (): NpmAuditSettings => npmSettings(npmException($override)))->toThrow(InvalidConfig::class, $message);
})->with([
    'identifier that is not GHSA' => [['advisory' => 'CVE-2026-0001'], 'not a GitHub advisory identifier'],
    'identifier with a forbidden letter' => [['advisory' => 'GHSA-aaaa-bbbb-cccc'], 'not a GitHub advisory identifier'],
    'no identifier' => [['advisory' => null], 'not a GitHub advisory identifier'],
    'empty reason' => [['reason' => '  '], 'without a reason'],
    'no reason' => [['reason' => null], 'without a reason'],
    'no date' => [['expires' => null], 'calendar date'],
    'date in another format' => [['expires' => '18/12/2026'], 'calendar date'],
    'date that does not exist' => [['expires' => '2026-02-30'], 'calendar date'],
    'date more than 90 days away' => [['expires' => '2027-02-17'], 'more than 90 days'],
]);

it('accepts the furthest date, 90 days from today, and a date already past', function (): void {
    expect(npmSettings(npmException(['expires' => '2027-02-16']))->allow)->toHaveCount(1)
        ->and(npmSettings(npmException(['expires' => '2020-01-01']))->allow)->toHaveCount(1);
});

it('refuses a malformed npm_audit key and a repeated advisory', function (): void {
    expect(fn (): NpmAuditSettings => NpmAuditSettings::fromArray('x', '.harness.json'))->toThrow(InvalidConfig::class, 'npm_audit must be an object')
        ->and(fn (): NpmAuditSettings => NpmAuditSettings::fromArray(['allow' => ['a' => 1]], '.harness.json'))->toThrow(InvalidConfig::class, 'npm_audit must be an object')
        ->and(fn (): NpmAuditSettings => npmSettings(npmException(), npmException()))->toThrow(InvalidConfig::class, 'twice')
        ->and(NpmAuditSettings::fromArray(null, '.harness.json')->allow)->toBe([])
        ->and(NpmAuditSettings::fromArray([], '.harness.json')->allow)->toBe([])
        ->and(fn (): NpmAuditSettings => NpmAuditSettings::fromArray(['allow' => ['junk']], '.harness.json'))->toThrow(InvalidConfig::class);
});

it('reads the key from .harness.json, validates it and writes it back', function (): void {
    $future = (new DateTimeImmutable('+30 days'))->format('Y-m-d');
    $config = HarnessConfig::fromArray(['profile' => 'core', 'npm_audit' => ['allow' => [npmException(['expires' => $future])]]]);

    expect($config->npmAudit->find(strtolower(PICOMATCH))?->reason)->toBe('No patched picomatch exists yet')
        ->and($config->toArray()['npm_audit'])->toBe(['allow' => [npmException(['expires' => $future])]])
        ->and($config->withRatchet($config->ratchet)->npmAudit)->toBe($config->npmAudit)
        ->and(HarnessConfig::fromArray(['profile' => 'core'])->toArray())->not->toHaveKey('npm_audit')
        ->and(fn (): HarnessConfig => HarnessConfig::fromArray(['profile' => 'core', 'npm_audit' => ['allow' => [['advisory' => PICOMATCH]]]]))->toThrow(InvalidConfig::class);
});

it('reads the exceptions of a file that is not a whole .harness.json', function (): void {
    $dir = harnessTempDir();
    file_put_contents($dir.'/h.json', json_encode(['npm_audit' => ['allow' => [npmException()]]]));
    file_put_contents($dir.'/none.json', '{"profile": "core"}');
    file_put_contents($dir.'/bad.json', '{');
    file_put_contents($dir.'/list.json', '"x"');

    expect(NpmAuditSettings::fromFile($dir.'/h.json', npmToday())->allow)->toHaveCount(1)
        ->and(NpmAuditSettings::fromFile($dir.'/none.json')->allow)->toBe([])
        ->and(NpmAuditSettings::fromFile($dir.'/missing.json')->allow)->toBe([])
        ->and(fn (): NpmAuditSettings => NpmAuditSettings::fromFile($dir.'/bad.json'))->toThrow(InvalidConfig::class, 'not valid JSON')
        ->and(fn (): NpmAuditSettings => NpmAuditSettings::fromFile($dir.'/list.json'))->toThrow(InvalidConfig::class, 'must be an object');
});

it('runs the gate as a step of check, blocking an advisory nothing names', function (): void {
    $root = npmAuditRepository(['.' => 'picomatch']);
    $step = array_values(array_filter((new Plan(HarnessConfig::fromArray(['profile' => 'core']), $root))->full(), static fn ($step): bool => $step->name === Plan::NPM_AUDIT))[0];
    $result = ($step->action)();

    expect($result->status)->toBe(StepResult::FAILED)->and($result->detail)->toContain(PICOMATCH);
});

it('runs the gate as a step of check, tolerating a listed advisory and writing the job summary', function (): void {
    $root = npmAuditRepository(['.' => 'picomatch']);
    $summary = $root.'/summary.md';
    putenv('GITHUB_STEP_SUMMARY='.$summary);
    $future = (new DateTimeImmutable('+30 days'))->format('Y-m-d');
    $config = HarnessConfig::fromArray(['profile' => 'core', 'npm_audit' => ['allow' => [npmException(['expires' => $future])]]]);
    $step = array_values(array_filter((new Plan($config, $root))->full(), static fn ($step): bool => $step->name === Plan::NPM_AUDIT))[0];
    $result = ($step->action)();
    putenv('GITHUB_STEP_SUMMARY');

    expect($result->status)->toBe(StepResult::PASSED)
        ->and($result->detail)->toContain('ignored '.PICOMATCH)
        ->and((string) file_get_contents($summary))->toContain('Tolerated advisories', $future);
});

it('runs the command: fails on an advisory, passes with a template file, and uses the given file', function (): void {
    $root = npmAuditRepository(['.' => 'picomatch']);
    $future = (new DateTimeImmutable('+30 days'))->format('Y-m-d');

    [$blocked] = harnessRun(new NpmAuditCommand, $root);

    mkdir($root.'/.github');
    file_put_contents($root.'/.github/harness.json', json_encode(['npm_audit' => ['allow' => [npmException(['expires' => $future])]]]));
    [$code, $out] = harnessRun(new NpmAuditCommand, $root);

    file_put_contents($root.'/other.json', '{}');
    [$other] = harnessRun(new NpmAuditCommand, $root, '--config=other.json');
    [$absolute] = harnessRun(new NpmAuditCommand, $root, '--config='.$root.'/.github/harness.json');

    expect($blocked)->toBe(1)->and($code)->toBe(0)->and($out)->toContain('ignored '.PICOMATCH)
        ->and($other)->toBe(1)->and($absolute)->toBe(0);
});

it('prefers .harness.json over the template file', function (): void {
    $root = npmAuditRepository(['.' => 'picomatch']);
    file_put_contents($root.'/.harness.json', json_encode(['profile' => 'core', 'npm_audit' => ['allow' => [npmException(['expires' => (new DateTimeImmutable('+30 days'))->format('Y-m-d')])]]]));

    expect(harnessRun(new NpmAuditCommand, $root)[0])->toBe(0);
});

it('fails the command on an invalid file, without npm, and passes without a lock file', function (): void {
    $root = npmAuditRepository(['.' => 'picomatch']);
    file_put_contents($root.'/.harness.json', json_encode(['profile' => 'core', 'npm_audit' => ['allow' => [['advisory' => PICOMATCH]]]]));
    [$invalid, , $err] = harnessRun(new NpmAuditCommand, $root);

    $empty = harnessTempDir();
    [$none, $out] = harnessRun(new NpmAuditCommand, $empty);

    putenv('PATH='.harnessTempDir());
    [$missing, , $missingErr] = harnessRun(new NpmAuditCommand, $root);

    expect($invalid)->toBe(1)->and($err)->toContain('without a reason')
        ->and($none)->toBe(0)->and($out)->toContain('No package-lock.json')
        ->and($missing)->toBe(1)->and($missingErr)->toContain('npm is not on the PATH');
});

it('tries npm audit again when the registry cannot be reached, then judges the answer', function (): void {
    [$root, $bin] = npmAuditSequence(['offline', 'clean']);

    $result = npmAuditOf($root, new NpmAuditSettings);

    expect($result->passed())->toBeTrue()->and((int) file_get_contents($bin.'/count'))->toBe(2);
});

it('fails, saying the advisory API is unreachable, when the registry never answers', function (): void {
    [$root, $bin] = npmAuditSequence(['offline']);

    $result = npmAuditOf($root, new NpmAuditSettings);

    expect($result->passed())->toBeFalse()
        ->and($result->render())->toContain('advisory API unreachable after 3 attempts', 'EAI_AGAIN')
        ->and((int) file_get_contents($bin.'/count'))->toBe(3);
});

it('does not try npm audit again when it finds an advisory', function (): void {
    [$root, $bin] = npmAuditSequence(['picomatch', 'clean']);

    $result = npmAuditOf($root, new NpmAuditSettings);

    expect($result->blocking)->toHaveCount(1)->and((int) file_get_contents($bin.'/count'))->toBe(1);
});
