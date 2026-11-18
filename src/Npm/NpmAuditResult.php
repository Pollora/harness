<?php

declare(strict_types=1);

namespace Pollora\Harness\Npm;

/**
 * What `npm audit` found over the lock files of a repository, once the tolerated advisories are set aside.
 */
final readonly class NpmAuditResult
{
    /**
     * @param  list<string>  $directories  The directories audited
     * @param  list<array{id: string, package: string, severity: string, title: string, directory: string}>  $blocking  High or critical advisories nothing tolerates
     * @param  list<array{id: string, package: string, severity: string, title: string, directory: string, reason: string, expires: string}>  $ignored  Advisories tolerated by a live exception
     * @param  list<array{id: string, package: string, severity: string, title: string, directory: string, reason: string, expires: string}>  $expired  Advisories whose exception ran out: they block
     * @param  list<string>  $failures  Directories npm could not audit, each with why
     * @param  list<string>  $unused  Exceptions that matched no advisory: they can be removed
     */
    public function __construct(
        public array $directories,
        public array $blocking = [],
        public array $ignored = [],
        public array $expired = [],
        public array $failures = [],
        public array $unused = [],
    ) {}

    public function passed(): bool
    {
        return $this->blocking === [] && $this->expired === [] && $this->failures === [];
    }

    /**
     * The report printed by the gate and the command.
     */
    public function render(): string
    {
        $lines = [];

        foreach ($this->failures as $failure) {
            $lines[] = '  ✘ '.$failure;
        }

        foreach ($this->blocking as $advisory) {
            $lines[] = sprintf('  ✘ %s (%s): %s %s [%s]', $advisory['package'], $advisory['severity'], $advisory['id'], $advisory['title'], $advisory['directory']);
        }

        foreach ($this->expired as $advisory) {
            $lines[] = sprintf('  ✘ %s (%s): %s %s [%s]: exception expired on %s (%s)', $advisory['package'], $advisory['severity'], $advisory['id'], $advisory['title'], $advisory['directory'], $advisory['expires'], $advisory['reason']);
        }

        foreach ($this->ignored as $advisory) {
            $lines[] = sprintf('  ~ ignored %s on %s (%s) [%s]: %s, until %s', $advisory['id'], $advisory['package'], $advisory['severity'], $advisory['directory'], $advisory['reason'], $advisory['expires']);
        }

        foreach ($this->unused as $advisory) {
            $lines[] = '  ! '.$advisory.' is listed in npm_audit.allow but no audited lock file has it: remove the exception';
        }

        if ($this->passed()) {
            $lines[] = '  npm audit: no high or critical advisory without an exception ('.implode(', ', $this->directories).')';
        }

        return implode("\n", $lines);
    }

    /**
     * The Markdown for the job summary of GitHub Actions: the tolerated advisories, with their reason and date.
     */
    public function markdown(): string
    {
        $out = '### Dependency audit (npm): '.($this->passed() ? 'passed' : 'failed')."\n\n";

        foreach ([...$this->failures, ...array_map(static fn (array $a): string => sprintf('%s on %s (%s) [%s]: no exception', $a['id'], $a['package'], $a['severity'], $a['directory']), $this->blocking), ...array_map(static fn (array $a): string => sprintf('%s on %s (%s) [%s]: exception expired on %s', $a['id'], $a['package'], $a['severity'], $a['directory'], $a['expires']), $this->expired)] as $problem) {
            $out .= '- ✘ '.$problem."\n";
        }

        if ($this->ignored !== []) {
            $out .= "\nTolerated advisories (`npm_audit.allow`):\n\n| Advisory | Package | Reason | Expires |\n| --- | --- | --- | --- |\n";

            foreach ($this->ignored as $advisory) {
                $out .= sprintf("| %s | %s | %s | %s |\n", $advisory['id'], $advisory['package'], str_replace('|', '\\|', $advisory['reason']), $advisory['expires']);
            }
        }

        foreach ($this->unused as $advisory) {
            $out .= "\n- {$advisory} is listed but no audited lock file has it: remove the exception.\n";
        }

        return $out;
    }
}
