<?php

declare(strict_types=1);

namespace Pollora\Harness\Console\Commands;

use Pollora\Harness\Console\Command;
use Pollora\Harness\Console\Io;
use Pollora\Harness\Rules\Catalogue;

final class RulesCommand implements Command
{
    public function name(): string
    {
        return 'rules';
    }

    public function description(): string
    {
        return 'List the rules, or show one: pollora-qa rules ARCH-02';
    }

    public function usage(): string
    {
        return '              --status=STATUS     Only enforced, observed or planned rules';
    }

    public function run(Io $io): int
    {
        $wanted = $io->arguments[0] ?? null;
        $status = $io->option('status');

        foreach (Catalogue::all() as $id => $rule) {
            if (($wanted !== null && strcasecmp($wanted, $id) !== 0) || ($status !== null && $rule['status'] !== $status)) {
                continue;
            }

            $io->line(sprintf('%-8s %-3s %-9s %s', $id, $rule['gate'], $rule['status'], $rule['title']));
            $io->line($io->muted(str_repeat(' ', 22).$rule['tool']));
        }

        return self::SUCCESS;
    }
}
