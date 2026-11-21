<?php

declare(strict_types=1);

namespace Pollora\Harness\Check;

use Closure;
use Pollora\Harness\Support\TransportErrors;

/**
 * `composer audit` (SEC-09), attempted again when Packagist cannot be reached.
 *
 * Composer exits with 1 both when it finds a vulnerability and when the advisory API times out, so
 * the output tells them apart. Three outcomes, and only the second one is attempted again:
 *
 *  - exit 0, or advisories found: a real answer, judged at once (a vulnerability fails the first
 *    time, with no second attempt that could let it slip past);
 *  - a transport failure (timeout, DNS, refused or reset connection, HTTP 5xx or 429): up to
 *    `TransportErrors::ATTEMPTS` attempts with a growing wait; still unreachable at the end, the
 *    step FAILS with "advisory API unreachable after N attempts", never a pass;
 *  - anything else (an unreadable lock file, an unknown option): fails at once, as a mistake.
 */
final readonly class ComposerAudit
{
    /**
     * @param  list<string>  $arguments  What follows `composer audit` (`--locked`, `--no-interaction`)
     * @param  Closure(int): void|null  $sleep  Replaces `sleep()` in tests
     */
    public function __construct(
        private string $cwd,
        private string $composer,
        private array $arguments = ['--no-interaction'],
        private int $attempts = TransportErrors::ATTEMPTS,
        private ?Closure $sleep = null,
    ) {}

    public function run(): StepResult
    {
        $attempt = 0;

        do {
            $attempt++;
            [$code, $output] = $this->audit();

            if ($code === 0) {
                return StepResult::passed($output);
            }

            // Advisories found, or any other answer that is not a transport failure: a result, not an incident.
            if ($this->foundVulnerabilities($output) || ! TransportErrors::matches($output)) {
                return StepResult::failed($output);
            }

            if ($attempt < $this->attempts) {
                TransportErrors::wait($attempt, $this->sleep);
            }
        } while ($attempt < $this->attempts);

        return StepResult::failed(sprintf(
            "Dependency audit: advisory API unreachable after %d attempts. This is a network failure of Packagist, not a vulnerability: nothing was verified, so the gate fails. Run it again.\nLast error: %s",
            $this->attempts,
            self::lastLine($output),
        ));
    }

    /**
     * @return array{int, string} Exit code and the output (standard output and error together)
     */
    private function audit(): array
    {
        // @phpstan-ignore argument.type (PHP accepts ['redirect', 1] since 7.4; the stub does not)
        $process = proc_open([$this->composer, 'audit', ...$this->arguments], [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $this->cwd);

        if (! is_resource($process)) {
            return [127, 'Could not start '.$this->composer];
        }

        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        return [proc_close($process), rtrim($output)];
    }

    private function foundVulnerabilities(string $output): bool
    {
        return preg_match('/Found \d+ security vulnerability advisor/i', $output) === 1;
    }

    /**
     * The last non-empty line of a tool's output, which names the error.
     */
    private static function lastLine(string $output): string
    {
        $lines = array_values(array_filter(array_map(trim(...), explode("\n", $output)), static fn (string $line): bool => $line !== ''));

        return $lines === [] ? '(no output)' : $lines[array_key_last($lines)];
    }
}
