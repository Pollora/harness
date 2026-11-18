<?php

declare(strict_types=1);

namespace Pollora\Harness\Npm;

use DateTimeImmutable;
use Pollora\Harness\Config\NpmAuditException;
use Pollora\Harness\Config\NpmAuditSettings;

/**
 * `npm audit` over the lock files of a repository, with the tolerated advisories set aside (SEC-09).
 *
 * The one implementation of the gate: `pollora-qa check` runs it as a step and the reusable workflow
 * of the templates runs it through `pollora-qa npm-audit`. A high or critical advisory is tolerated
 * only when `npm_audit.allow` names it and the exception has not run out; an exception that has run
 * out fails the gate and says so, and an advisory nothing names blocks as `npm audit` does.
 *
 * Advisories are read from `npm audit --json`: each `via` entry that is an object is an advisory,
 * and a package that is only affected through another one lists that package's name as a string,
 * so judging the advisories judges every affected package.
 */
final readonly class NpmAudit
{
    /** The severities that block, as with `--audit-level=high`. */
    private const array BLOCKING = ['high', 'critical'];

    public function __construct(
        private string $cwd,
        private string $npm,
        private NpmAuditSettings $settings,
        private DateTimeImmutable $today,
    ) {}

    /**
     * @return list<string> Directories (relative to the repository, "." for the root) that hold a package-lock.json
     */
    public static function lockDirectories(string $cwd): array
    {
        $directories = [];

        foreach (['', '*/', '*/*/'] as $depth) {
            $locks = glob($cwd.'/'.$depth.'package-lock.json');

            if ($locks === false) {
                continue;
            }

            foreach ($locks as $lock) {
                $directory = ltrim(substr(dirname($lock), strlen($cwd)), '/');

                if (preg_match('#(^|/)(node_modules|vendor|\.worktrees|\.git)(/|$)#', $directory) !== 1) {
                    $directories[] = $directory === '' ? '.' : $directory;
                }
            }
        }

        return $directories;
    }

    /**
     * @param  list<string>  $directories
     */
    public function audit(array $directories): NpmAuditResult
    {
        $blocking = [];
        $ignored = [];
        $expired = [];
        $failures = [];
        $matched = [];

        foreach ($directories as $directory) {
            $report = $this->report($directory);

            if (is_string($report)) {
                $failures[] = $report;

                continue;
            }

            foreach ($this->advisories($report) as $advisory) {
                $record = ['id' => $advisory['id'], 'package' => $advisory['package'], 'severity' => $advisory['severity'], 'title' => $advisory['title'], 'directory' => $directory];
                $exception = $this->settings->find($advisory['id']);

                if (! $exception instanceof NpmAuditException) {
                    $blocking[] = $record;

                    continue;
                }

                $matched[strtoupper($exception->advisory)] = true;
                $record += ['reason' => $exception->reason, 'expires' => $exception->expires->format('Y-m-d')];

                if ($exception->isExpiredOn($this->today)) {
                    $expired[] = $record;
                } else {
                    $ignored[] = $record;
                }
            }
        }

        $unused = $failures === [] ? array_values(array_filter(
            array_map(static fn (NpmAuditException $exception): string => $exception->advisory, $this->settings->allow),
            static fn (string $id): bool => ! isset($matched[strtoupper($id)]),
        )) : [];

        return new NpmAuditResult($directories, $blocking, $ignored, $expired, $failures, $unused);
    }

    /**
     * The decoded `npm audit --json` report of a directory, or why there is none: npm exits non-zero both
     * when it finds advisories and when it cannot reach the registry, so only the JSON tells them apart.
     *
     * @return array<mixed>|string
     */
    private function report(string $directory): array|string
    {
        $process = proc_open([$this->npm, 'audit', '--json', '--prefix', $directory], [1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $this->cwd);

        if (! is_resource($process)) {
            return "could not start npm for {$directory}";
        }

        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        proc_close($process);

        $report = json_decode($output, true);

        if (! is_array($report)) {
            return "npm audit printed no JSON report for {$directory}";
        }

        $vulnerabilities = $report['vulnerabilities'] ?? null;

        if (isset($report['error']) || ! is_array($vulnerabilities)) {
            $error = is_array($report['error'] ?? null) ? ($report['error']['summary'] ?? $report['error']['code'] ?? null) : null;
            $why = is_string($error) && $error !== '' ? $error : ($report['message'] ?? null);

            return "npm audit could not audit {$directory}".(is_string($why) ? ": {$why}" : '');
        }

        return $report;
    }

    /**
     * The high and critical advisories of a report, each once.
     *
     * @param  array<mixed>  $report
     * @return list<array{id: string, package: string, severity: string, title: string}>
     */
    private function advisories(array $report): array
    {
        $found = [];

        $vulnerabilities = $report['vulnerabilities'] ?? [];

        foreach (is_array($vulnerabilities) ? $vulnerabilities : [] as $vulnerability) {
            $vias = is_array($vulnerability) ? ($vulnerability['via'] ?? []) : [];

            foreach (is_array($vias) ? $vias : [] as $via) {
                $severity = is_array($via) ? ($via['severity'] ?? null) : null;

                if (! is_array($via) || ! is_string($severity) || ! in_array($severity, self::BLOCKING, true)) {
                    continue;
                }

                $url = is_string($via['url'] ?? null) ? $via['url'] : '';
                $source = is_scalar($via['source'] ?? null) ? (string) $via['source'] : 'unknown';
                $package = is_string($via['name'] ?? null) ? $via['name'] : 'unknown';
                $id = preg_match('#/advisories/(GHSA(?:-[a-z0-9]{4}){3})#i', $url, $matches) === 1 ? $matches[1] : 'npm-advisory-'.$source;
                $found[$id.'/'.$package] = [
                    'id' => $id,
                    'package' => $package,
                    'severity' => $severity,
                    'title' => is_string($via['title'] ?? null) ? $via['title'] : '',
                ];
            }
        }

        return array_values($found);
    }
}
