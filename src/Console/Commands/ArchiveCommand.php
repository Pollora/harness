<?php

declare(strict_types=1);

namespace Pollora\Harness\Console\Commands;

use Pollora\Harness\Config\HarnessConfig;
use Pollora\Harness\Console\Command;
use Pollora\Harness\Console\Io;
use Pollora\Harness\Distribution\Archive;

final class ArchiveCommand implements Command
{
    public function name(): string
    {
        return 'archive';
    }

    public function description(): string
    {
        return 'Check that development files stay out of the release archive (QA-06), or fix .gitattributes with --fix';
    }

    public function usage(): string
    {
        return <<<'TXT'
              --fix               Add the missing export-ignore lines to .gitattributes
            TXT;
    }

    public function run(Io $io): int
    {
        $config = HarnessConfig::load($io->cwd);

        if (! Archive::applies($config->profile)) {
            $io->line($io->muted('A '.$config->profile->value.' is its own product: nothing to check.'));

            return self::SUCCESS;
        }

        $archive = new Archive($io->cwd);
        $entries = $archive->entries();

        if ($entries === null) {
            $io->error('git archive cannot be built here: run it in a git repository with at least one commit.');

            return self::FAILURE;
        }

        $offenders = Archive::offenders($entries);

        if ($offenders === []) {
            $io->line($io->success(count($entries).' files in the archive, none for development.'));

            return self::SUCCESS;
        }

        $lines = Archive::linesFor($offenders);

        if (! $io->flag('fix')) {
            $io->line(count($offenders).' development files would travel in the archive Composer downloads:');

            foreach ($archive->missing($lines) as $line) {
                $io->line('  '.$line);
            }

            $io->line();
            $io->line('Run pollora-qa archive --fix to add these lines to .gitattributes.');

            return self::FAILURE;
        }

        $added = $archive->ensure($lines);

        foreach ($added as $line) {
            $io->line('  + '.$line);
        }

        $remaining = Archive::offenders($archive->entries() ?? []);
        $io->line($remaining === []
            ? $io->success('.gitattributes updated: the archive holds no development file.')
            : $io->failure(count($remaining).' development files still in the archive: check the patterns of .gitattributes.'));

        return $remaining === [] ? self::SUCCESS : self::FAILURE;
    }
}
