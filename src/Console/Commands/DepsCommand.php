<?php

declare(strict_types=1);

namespace Pollora\Harness\Console\Commands;

use Pollora\Harness\Config\HarnessConfig;
use Pollora\Harness\Console\Command;
use Pollora\Harness\Console\Io;
use Pollora\Harness\Deps\DependencyPolicy;

final class DepsCommand implements Command
{
    public function name(): string
    {
        return 'deps';
    }

    public function description(): string
    {
        return 'Check the declared dependencies against the profile (ARCH-04, ARCH-06)';
    }

    public function usage(): string
    {
        return '';
    }

    public function run(Io $io): int
    {
        $violations = (new DependencyPolicy(HarnessConfig::load($io->cwd)))->check(DependencyPolicy::readRequire($io->cwd));

        foreach ($violations as $violation) {
            $io->line($io->failure('✘ ').$violation);
        }

        if ($violations === []) {
            $io->line($io->success('✔ The dependencies follow ARCH-04 and ARCH-06.'));
        }

        return $violations === [] ? self::SUCCESS : self::FAILURE;
    }
}
