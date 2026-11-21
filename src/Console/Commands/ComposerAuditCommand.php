<?php

declare(strict_types=1);

namespace Pollora\Harness\Console\Commands;

use Pollora\Harness\Check\ComposerAudit;
use Pollora\Harness\Console\Command;
use Pollora\Harness\Console\Io;
use Pollora\Harness\Support\Paths;

/**
 * The Composer dependency audit (SEC-09) on its own, with the retry on network failures: the
 * reusable workflow of the templates runs it. It is the same implementation `check` uses.
 */
final class ComposerAuditCommand implements Command
{
    public function name(): string
    {
        return 'composer-audit';
    }

    public function description(): string
    {
        return 'Audit composer.lock, trying again when Packagist is unreachable (SEC-09)';
    }

    public function usage(): string
    {
        return <<<'TXT'
              --locked            Audit composer.lock without installing anything
            TXT;
    }

    public function run(Io $io): int
    {
        $composer = Paths::which('composer');

        if ($composer === null) {
            $io->error('composer is not on the PATH: https://getcomposer.org/download/');

            return self::FAILURE;
        }

        $result = (new ComposerAudit($io->cwd, $composer, $io->flag('locked') ? ['--locked', '--no-interaction'] : ['--no-interaction']))->run();

        if ($result->detail !== '') {
            $io->line($result->detail);
        }

        return $result->isFailed() ? self::FAILURE : self::SUCCESS;
    }
}
