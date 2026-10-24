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
final class Report
{
    /**
     * @param  list<array{step: Step, result: StepResult}>  $results
     */
    public function __construct(
        private readonly Profile $profile,
        private readonly Mode $mode,
        private readonly bool $fast,
        private readonly array $results,
    ) {}

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
                default => $io->warning('– skipped'),
            };

            $io->line(sprintf('  %s  %-24s %s', $status, $step->name, $io->muted(implode(' ', $step->rules))));

            if ($result->isFailed()) {
                foreach ($step->rules as $rule) {
                    $io->line($io->muted("               {$rule}: ".Catalogue::title($rule)));
                }
            }
        }

        $io->line();

        if (! $this->hasFailures()) {
            $io->line($io->success('Every gate passed.'));

            return;
        }

        $io->line($this->mode->blocks()
            ? $io->failure('Some gates failed. Fix them before merging.')
            : $io->warning('Some gates failed. Observe mode: reported, not blocking.'));
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
            'steps' => array_map(static fn (array $entry): array => [
                'name' => $entry['step']->name,
                'rules' => $entry['step']->rules,
                'status' => $entry['result']->status,
                'seconds' => round($entry['result']->seconds, 2),
                'detail' => $entry['result']->detail,
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
                StepResult::FAILED => $this->mode->blocks() ? '❌ failed' : '⚠️ failed (observed)',
                default => '➖ skipped: '.str_replace('|', '\|', $result->detail),
            });
        }

        return implode("\n", $lines)."\n\n";
    }
}
