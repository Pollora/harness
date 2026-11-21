<?php

declare(strict_types=1);

namespace Pollora\Harness\Console\Commands;

use JsonException;
use Pollora\Harness\Console\Command;
use Pollora\Harness\Console\Io;
use Pollora\Harness\Rules\PromotionReport;

/**
 * Says what would block in this repository if each observed rule became enforced.
 */
final class PromoteReportCommand implements Command
{
    public function name(): string
    {
        return 'promote-report';
    }

    public function description(): string
    {
        return 'What would block here if each observed rule became enforced (read only)';
    }

    public function usage(): string
    {
        return "              --json              Print the report as JSON instead of text\n"
            ."              <path>...           Report on these repositories instead of the current one (one aggregated matrix)\n"
            ."              --root=<dir>        Report on every sub-directory of <dir> that has a composer.json (or on --only ones)\n"
            .'              --only=a,b          With --root, keep only these sub-directories';
    }

    /**
     * Always succeeds: it is a report, not a gate.
     *
     * @throws JsonException
     */
    public function run(Io $io): int
    {
        if ($io->arguments !== [] || $io->option('root') !== null || $io->flag('root')) {
            return $this->runMany($io);
        }

        $rows = PromotionReport::build($io->cwd);

        if ($io->flag('json')) {
            $io->line(json_encode(['rules' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        if ($rows === []) {
            $io->line('No rule is observed in the catalogue: nothing to promote.');

            return self::SUCCESS;
        }

        foreach ($rows as $row) {
            $label = match ($row['verdict']) {
                PromotionReport::WOULD_BLOCK => $io->failure('would block'),
                PromotionReport::CLEAN => $io->success('clean'),
                PromotionReport::NOT_APPLICABLE => $io->muted('not applicable (nothing to read)'),
                default => $io->warning('no probe'),
            };

            $detail = $row['count'] > 0 ? sprintf(' (%d problem%s)', $row['count'], $row['count'] === 1 ? '' : 's') : '';

            if ($row['exempted_problems'] > 0) {
                $detail = sprintf(
                    ' (%d found, %d exempted by allow_rules in %d %s: %d remaining)',
                    $row['found'],
                    $row['exempted_problems'],
                    count($row['exempted_classes']),
                    count($row['exempted_classes']) === 1 ? 'class' : 'classes',
                    $row['count'],
                );
            }

            $io->line(sprintf('%-8s %s%s', $row['rule'], $label, $detail));

            foreach ($row['first'] as $problem) {
                $io->line('           '.$problem);
            }

            if ($row['count'] > count($row['first'])) {
                $io->line(sprintf('           … and %d more', $row['count'] - count($row['first'])));
            }

            if ($row['note'] !== '') {
                $io->line('           '.$row['note']);
            }

            foreach ($row['stale'] as $warning) {
                $io->line('           '.$warning);
            }

            if ($row['exempted'] !== null) {
                $io->line('           allow_rules exempts it ("'.$row['exempted'].'"): the exemption applies once the rule is enforced.');
            }
        }

        return self::SUCCESS;
    }

    /**
     * The matrix over several repositories: rule x repository, then the details of each repository.
     *
     * Unlike the single report this one can fail: a directory that cannot be read ends with status 1 after the
     * rest of the matrix is printed, so a repository that was not measured is never mistaken for a clean one.
     *
     * @throws JsonException
     */
    private function runMany(Io $io): int
    {
        $roots = [];
        $missing = [];

        foreach ($io->arguments as $path) {
            $roots[] = str_starts_with($path, '/') ? $path : $io->cwd.'/'.$path;
        }

        $base = $io->option('root');

        if ($base !== null) {
            $base = str_starts_with($base, '/') ? $base : $io->cwd.'/'.$base;

            if (! is_dir($base)) {
                $io->error("--root: {$base} is not a directory");

                return self::FAILURE;
            }

            $only = $io->list('only');
            $found = [];

            $composers = glob(rtrim($base, '/').'/*/composer.json');

            foreach ($composers === false ? [] : $composers as $composer) {
                $found[basename(dirname($composer))] = dirname($composer);
            }

            ksort($found);

            foreach ($only as $name) {
                // A directory without composer.json is still measured (every rule is "not applicable"):
                // a repository of the list is never dropped silently.
                is_dir(rtrim($base, '/').'/'.$name) ? $roots[] = rtrim($base, '/').'/'.$name : $missing[] = $name;
            }

            if ($only === []) {
                array_push($roots, ...array_values($found));
            }
        } elseif ($io->flag('root')) {
            $io->error('--root needs a directory: --root=<dir>');

            return self::FAILURE;
        }

        foreach ($missing as $name) {
            $io->error("--only: {$name} is not a directory under --root");
        }

        $report = PromotionReport::buildMany($roots);
        $failed = $missing !== [];

        foreach ($report['repositories'] as $name => $entry) {
            if ($entry['error'] !== null) {
                $io->error("{$name}: {$entry['error']}");
                $failed = true;
            }
        }

        if ($io->flag('json')) {
            $io->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return $failed ? self::FAILURE : self::SUCCESS;
        }

        $cells = [];
        $rules = [];

        foreach ($report['matrix'] as $cell) {
            $rules[$cell['rule']] = true;
            $cells[$cell['repository']][$cell['rule']] = match ($cell['verdict']) {
                PromotionReport::WOULD_BLOCK => (string) $cell['count'],
                PromotionReport::CLEAN => 'clean',
                PromotionReport::NOT_APPLICABLE => 'n/a',
                default => 'no probe',
            };
        }

        $rules = array_keys($rules);
        $io->line(sprintf('%-22s %s', 'repository', implode(' ', array_map(static fn (string $rule): string => sprintf('%-8s', $rule), $rules))));

        foreach ($report['repositories'] as $name => $entry) {
            $line = $entry['error'] !== null
                ? 'ERROR '.$entry['error']
                : implode(' ', array_map(static fn (string $rule): string => sprintf('%-8s', $cells[$name][$rule] ?? '-'), $rules));
            $io->line(sprintf('%-22s %s', $name, $line));
        }

        $io->line('(number = problems that would block, n/a = nothing to read)');

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
