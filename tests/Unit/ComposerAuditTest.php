<?php

declare(strict_types=1);

use Pollora\Harness\Check\ComposerAudit;
use Pollora\Harness\Check\StepResult;
use Pollora\Harness\Console\Commands\ComposerAuditCommand;
use Pollora\Harness\Support\TransportErrors;

require_once __DIR__.'/../Support/ComposerAuditFake.php';

const PACKAGIST_TIMEOUT = "\n  Failed to audit: curl error 28 while downloading https://packagist.org/api/security-advisories/: Connection timed out after 10001 milliseconds\n";
const PACKAGIST_CLEAN = 'No security vulnerability advisories found.';
const PACKAGIST_VULNERABLE = "Found 1 security vulnerability advisory affecting 1 package:\nPackage: vendor/package\nCVE: CVE-2026-0001\nTitle: A flaw";

/**
 * Runs the audit against a fake composer, without waiting.
 *
 * @param  list<array{int, string}>  $outcomes
 * @return array{StepResult, int, list<int>} The result, the number of calls and the waits asked for
 */
function composerAuditOf(array $outcomes, int $attempts = 3): array
{
    [$composer, $directory] = fakeComposer($outcomes);
    $waits = [];
    $result = (new ComposerAudit(harnessTempDir(), $composer, ['--no-interaction'], $attempts, function (int $seconds) use (&$waits): void {
        $waits[] = $seconds;
    }))->run();

    return [$result, fakeComposerCalls($directory), $waits];
}

it('passes at once when there is nothing to report', function (): void {
    [$result, $calls, $waits] = composerAuditOf([[0, PACKAGIST_CLEAN]]);

    expect($result->status)->toBe(StepResult::PASSED)
        ->and($result->detail)->toBe(PACKAGIST_CLEAN)
        ->and($calls)->toBe(1)
        ->and($waits)->toBe([]);
});

it('tries again after a timeout and passes when the second attempt answers', function (): void {
    [$result, $calls, $waits] = composerAuditOf([[1, PACKAGIST_TIMEOUT], [0, PACKAGIST_CLEAN]]);

    expect($result->status)->toBe(StepResult::PASSED)
        ->and($calls)->toBe(2)
        ->and($waits)->toBe([5]);
});

it('fails, saying the advisory API is unreachable, when every attempt times out', function (): void {
    [$result, $calls, $waits] = composerAuditOf([[1, PACKAGIST_TIMEOUT]]);

    expect($result->isFailed())->toBeTrue()
        ->and($result->detail)->toContain('advisory API unreachable after 3 attempts', 'not a vulnerability', 'curl error 28')
        ->and($calls)->toBe(3)
        ->and($waits)->toBe([5, 15])
        ->and(array_sum($waits))->toBeLessThanOrEqual(20);
});

it('fails on the first attempt when a vulnerability is found, without trying again', function (): void {
    [$result, $calls, $waits] = composerAuditOf([[1, PACKAGIST_VULNERABLE], [0, PACKAGIST_CLEAN]]);

    expect($result->isFailed())->toBeTrue()
        ->and($result->detail)->toContain('CVE-2026-0001')->not->toContain('unreachable')
        ->and($calls)->toBe(1)
        ->and($waits)->toBe([]);
});

it('does not let a vulnerability found after a timeout pass', function (): void {
    [$result, $calls] = composerAuditOf([[1, PACKAGIST_TIMEOUT], [1, PACKAGIST_VULNERABLE]]);

    expect($result->isFailed())->toBeTrue()->and($result->detail)->toContain('CVE-2026-0001')->and($calls)->toBe(2);
});

it('does not try again on an error that is not the network', function (): void {
    [$result, $calls] = composerAuditOf([[1, 'composer.lock was not found'], [0, PACKAGIST_CLEAN]]);

    expect($result->isFailed())->toBeTrue()->and($result->detail)->toBe('composer.lock was not found')->and($calls)->toBe(1);
});

it('reads the number of attempts it is given', function (): void {
    [$result, $calls, $waits] = composerAuditOf([[1, PACKAGIST_TIMEOUT]], 2);

    expect($result->detail)->toContain('unreachable after 2 attempts')->and($calls)->toBe(2)->and($waits)->toBe([5]);
});

it('recognises the transport failures of Composer, curl and npm, and nothing else', function (string $output, bool $transport): void {
    expect(TransportErrors::matches($output))->toBe($transport);
})->with([
    'timeout' => ['curl error 28 while downloading https://packagist.org/x', true],
    'dns' => ['curl error 6 while downloading https://packagist.org/x: Could not resolve host: packagist.org', true],
    'refused' => ['curl error 7 while downloading x: Connection refused', true],
    'reset' => ['curl error 56 while downloading x: Connection reset by peer', true],
    'http 502' => ['The "https://packagist.org/api/security-advisories/" file could not be downloaded (HTTP/2 502 ):', true],
    'http 429' => ['HTTP/1.1 429 Too Many Requests', true],
    'npm dns' => ['request failed, reason: getaddrinfo EAI_AGAIN registry.npmjs.org', true],
    'http 404' => ['The file could not be downloaded (HTTP/2 404 )', false],
    'certificate' => ['curl error 60 while downloading x: SSL certificate problem', false],
    'a lock file mistake' => ['composer.lock was not found', false],
]);

it('waits the last delay again when asked past the list', function (): void {
    $waits = [];
    TransportErrors::wait(1, function (int $seconds) use (&$waits): void {
        $waits[] = $seconds;
    });
    TransportErrors::wait(9, function (int $seconds) use (&$waits): void {
        $waits[] = $seconds;
    });

    expect($waits)->toBe([5, 15]);
});

it('runs from the command with the lock file only, and exits with the verdict', function (): void {
    [$composer, $directory] = fakeComposer([[0, PACKAGIST_CLEAN]]);
    $path = (string) getenv('PATH');
    putenv('PATH='.$directory.PATH_SEPARATOR.$path);

    try {
        [$code, $out] = harnessRun(new ComposerAuditCommand, harnessTempDir(), '--locked');
    } finally {
        putenv('PATH='.$path);
    }

    expect($code)->toBe(0)->and($out)->toContain(PACKAGIST_CLEAN)->and(fakeComposerCalls($directory))->toBe(1)
        ->and($composer)->toBeFile();
});

it('fails from the command when a vulnerability is found', function (): void {
    [, $directory] = fakeComposer([[1, PACKAGIST_VULNERABLE]]);
    $path = (string) getenv('PATH');
    putenv('PATH='.$directory.PATH_SEPARATOR.$path);

    try {
        [$code, $out] = harnessRun(new ComposerAuditCommand, harnessTempDir());
    } finally {
        putenv('PATH='.$path);
    }

    expect($code)->toBe(1)->and($out)->toContain('CVE-2026-0001');
});
