<?php

declare(strict_types=1);

namespace Pollora\Harness\Check;

use Pollora\Harness\Console\Io;

/**
 * Runs the steps in order and keeps every result.
 *
 * A failing step does not stop the run: the report shows every gate at once,
 * so one push tells the author everything that needs fixing.
 */
final readonly class Runner
{
    /** Lines of a failing tool's output kept in the report. */
    private const int TAIL = 30;

    public function __construct(private Io $io, private string $cwd) {}

    /**
     * @param  list<Step>  $steps
     * @return list<array{step: Step, result: StepResult}>
     */
    public function run(array $steps): array
    {
        $results = [];

        foreach ($steps as $step) {
            $this->io->title("▸ {$step->name}  ".$this->io->muted(implode(' ', $step->rules)));

            $started = microtime(true);
            $result = $this->execute($step)->withSeconds(microtime(true) - $started);

            $this->io->line(match ($result->status) {
                StepResult::PASSED => $this->io->success('  passed'),
                StepResult::FAILED => $this->io->failure('  failed'),
                default => $this->io->warning('  skipped: '.$result->detail),
            }.$this->io->muted(sprintf(' (%.1fs)', $result->seconds)));

            if ($result->isFailed()) {
                $this->annotate($step, $result);
            }

            $results[] = ['step' => $step, 'result' => $result];
        }

        return $results;
    }

    private function execute(Step $step): StepResult
    {
        if ($step->skipReason !== null) {
            return StepResult::skipped($step->skipReason);
        }

        foreach ($step->requires as $required) {
            if (! file_exists($this->cwd.'/'.$required)) {
                return StepResult::skipped("{$required} not found");
            }
        }

        if (! is_array($step->action)) {
            $result = ($step->action)();

            if ($result->detail !== '') {
                $this->io->line($result->detail);
            }

            return $result;
        }

        [$exitCode, $output] = $this->process($step->action);

        return $exitCode === 0
            ? StepResult::passed()
            : StepResult::failed(implode("\n", array_slice(explode("\n", rtrim($output)), -self::TAIL)));
    }

    /**
     * Runs a command, streaming its output to the console and keeping it.
     *
     * @param  list<string>  $command
     * @return array{int, string}
     */
    private function process(array $command): array
    {
        $this->io->line($this->io->muted('  $ '.implode(' ', array_map($this->quote(...), $command))));

        // @phpstan-ignore argument.type (PHP accepts ['redirect', 1] since 7.4; the stub does not)
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $this->cwd);

        if (! is_resource($process)) {
            return [127, 'Could not start '.$command[0]];
        }

        $output = '';

        while (($chunk = fgets($pipes[1])) !== false) {
            $output .= $chunk;
            $this->io->line('  '.rtrim($chunk, "\n"));
        }

        fclose($pipes[1]);

        return [proc_close($process), $output];
    }

    /**
     * In GitHub Actions, a failed step becomes an annotation on the run, so
     * the reason shows on the pull request without opening the log.
     */
    private function annotate(Step $step, StepResult $result): void
    {
        if (getenv('GITHUB_ACTIONS') !== 'true') {
            return;
        }

        $plain = (string) preg_replace('/\e\[[0-9;]*m/', '', $result->detail);
        $detail = strtr($plain, ['%' => '%25', "\r" => '%0D', "\n" => '%0A']);
        $this->io->line(sprintf('::error title=Pollora harness · %s (%s)::%s', $step->name, implode(' ', $step->rules), $detail));
    }

    private function quote(string $argument): string
    {
        return preg_match('/^[\w.\/:=@%+-]+$/', $argument) === 1 ? $argument : escapeshellarg($argument);
    }
}
