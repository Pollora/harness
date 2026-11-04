<?php

declare(strict_types=1);

namespace Pollora\Harness\Check;

use Pollora\Harness\Config\Mode;
use Pollora\Harness\Console\Io;
use Pollora\Harness\Profile;
use Pollora\Harness\Rules\Catalogue;

/**
 * What a check run found, for the console, for CI (JSON) and for the GitHub
 * job summary (Markdown).
 */
final readonly class Report
{
    /**
     * @param  list<array{step: Step, result: StepResult}>  $results
     * @param  list<string>  $observed  Gates reported but never blocking, whatever the mode
     * @param  bool  $strict  A gate that did not run for want of a tool fails the check, whatever the mode
     */
    public function __construct(
        private Profile $profile,
        private Mode $mode,
        private bool $fast,
        private array $results,
        private array $observed = [],
        private bool $strict = false,
    ) {}

    /**
     * Whether a failure stops the merge: the mode blocks and the failed gate is not one the repository only observes.
     */
    public function hasBlockingFailures(): bool
    {
        if ($this->strict && $this->missingTools() !== []) {
            return true;
        }

        if (! $this->mode->blocks()) {
            return false;
        }

        foreach ($this->results as $entry) {
            if ($entry['result']->isFailed() && ! in_array($entry['step']->name, $this->observed, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The gates that did not run because a tool is missing, except the ones the repository only observes.
     *
     * @return list<array{step: Step, result: StepResult}>
     */
    public function missingTools(): array
    {
        return array_values(array_filter(
            $this->results,
            fn (array $entry): bool => $entry['result']->isMissingTool() && ! in_array($entry['step']->name, $this->observed, true),
        ));
    }

    public function hasFailures(): bool
    {
        foreach ($this->results as $entry) {
            if ($entry['result']->isFailed()) {
                return true;
            }
        }

        return false;
    }

    public function print(Io $io): void
    {
        $io->title('Summary');

        foreach ($this->results as ['step' => $step, 'result' => $result]) {
            $status = match ($result->status) {
                StepResult::PASSED => $io->success('✔ passed '),
                StepResult::FAILED => $io->failure('✘ failed '),
                default => $result->isMissingTool() ? $io->warning('– no tool') : $io->warning('– skipped'),
            };

            $io->line(sprintf('  %s  %-24s %s', $status, $step->name, $io->muted(implode(' ', $step->rules))));

            if ($result->isFailed()) {
                foreach ($step->rules as $rule) {
                    $io->line($io->muted("               {$rule}: ".Catalogue::title($rule)));
                }
            }
        }

        $io->line();

        $missing = $this->missingTools();

        if ($missing !== []) {
            $io->line($io->warning(sprintf('%d gate(s) did not run because a tool is missing. This is not a pass, CI may still fail on what they guard:', count($missing))));

            foreach ($missing as ['step' => $step, 'result' => $result]) {
                $io->line(sprintf('  %s (%s): %s', $step->name, implode(' ', $step->rules), $result->install));
            }

            $io->line($this->strict
                ? $io->failure('Strict mode: install the missing tools, then run the check again.')
                : $io->muted('Add --strict to fail when a tool is missing.'));
            $io->line();
        }

        if (! $this->hasFailures()) {
            $io->line($missing === []
                ? $io->success('Every gate passed.')
                : $io->warning('Every gate that ran passed, but not every gate ran.'));

            return;
        }

        $io->line($this->hasBlockingFailures()
            ? $io->failure('Some gates failed. Fix them before merging.')
            : $io->warning('Some gates failed. Reported, not blocking (observe mode, or gates listed as observed).'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'profile' => $this->profile->value,
            'mode' => $this->mode->value,
            'gates' => $this->fast ? 'fast' : 'full',
            'failed' => $this->hasFailures(),
            'strict' => $this->strict,
            'steps' => array_map(static fn (array $entry): array => [
                'name' => $entry['step']->name,
                'rules' => $entry['step']->rules,
                'status' => $entry['result']->status,
                'seconds' => round($entry['result']->seconds, 2),
                'detail' => $entry['result']->detail,
                ...($entry['result']->isMissingTool() ? ['missing_tool' => true, 'install' => $entry['result']->install] : []),
            ], $this->results),
        ];
    }

    public function writeJson(string $path): void
    {
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0o755, true);
        }

        file_put_contents($path, json_encode($this->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
    }

    public function markdown(): string
    {
        $lines = [
            sprintf('### Pollora harness · %s · %s mode', $this->profile->value, $this->mode->value),
            '',
            '| Gate | Rules | Result |',
            '| --- | --- | --- |',
        ];

        foreach ($this->results as ['step' => $step, 'result' => $result]) {
            $lines[] = sprintf('| %s | %s | %s |', $step->name, implode(', ', $step->rules), match ($result->status) {
                StepResult::PASSED => '✅ passed',
                StepResult::FAILED => $this->mode->blocks() && ! in_array($step->name, $this->observed, true) ? '❌ failed' : '⚠️ failed (observed)',
                default => $result->isMissingTool()
                    ? '⚠️ not run, tool missing: '.str_replace('|', '\|', $result->detail).' — install: `'.str_replace('|', '\|', (string) $result->install).'`'
                    : '➖ skipped: '.str_replace('|', '\|', $result->detail),
            });
        }

        return implode("\n", $lines)."\n\n";
    }
}
