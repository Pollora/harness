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
        return '              --json              Print the report as JSON instead of text';
    }

    /**
     * Always succeeds: it is a report, not a gate.
     *
     * @throws JsonException
     */
    public function run(Io $io): int
    {
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
}
