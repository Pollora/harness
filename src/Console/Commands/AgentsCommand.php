<?php

declare(strict_types=1);

namespace Pollora\Harness\Console\Commands;

use Pollora\Harness\Config\HarnessConfig;
use Pollora\Harness\Console\Command;
use Pollora\Harness\Console\Io;
use Pollora\Harness\Scaffold\AgentsFile;

final class AgentsCommand implements Command
{
    public function name(): string
    {
        return 'agents';
    }

    public function description(): string
    {
        return 'Regenerate the harness block of AGENTS.md from the rule catalogue';
    }

    public function usage(): string
    {
        return '              --check             Fail if AGENTS.md is out of date instead of writing it';
    }

    public function run(Io $io): int
    {
        $path = $io->cwd.'/AGENTS.md';
        $current = is_file($path) ? (string) file_get_contents($path) : "# Agent instructions\n";
        $updated = AgentsFile::merge($current, AgentsFile::block(HarnessConfig::load($io->cwd)));

        if ($updated === $current) {
            $io->line($io->success('AGENTS.md is up to date.'));

            return self::SUCCESS;
        }

        if ($io->flag('check')) {
            $io->error('AGENTS.md is out of date: run vendor/bin/pollora-qa agents.');

            return self::FAILURE;
        }

        file_put_contents($path, $updated);
        $io->line($io->success('AGENTS.md updated.'));

        return self::SUCCESS;
    }
}
