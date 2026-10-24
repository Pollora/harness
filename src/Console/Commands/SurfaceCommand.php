<?php

declare(strict_types=1);

namespace Pollora\Harness\Console\Commands;

use Pollora\Harness\Console\Command;
use Pollora\Harness\Console\Io;
use Pollora\Harness\Surface\SurfaceCheck;
use Pollora\Harness\Surface\SurfaceExtractor;
use Pollora\Harness\Surface\SurfaceFile;

final class SurfaceCommand implements Command
{
    public function name(): string
    {
        return 'surface';
    }

    public function description(): string
    {
        return 'Compare the public surface with api-surface.json (QA-04), or record it with --update';
    }

    public function usage(): string
    {
        return <<<'TXT'
              --update            Record the current surface in api-surface.json (a person does this)
              --allow=WHAT        none, additions (api-change) or all (breaking); CI reads the PR labels
              --print             Print the current surface
            TXT;
    }

    public function run(Io $io): int
    {
        if ($io->flag('print')) {
            foreach ((new SurfaceExtractor($io->cwd))->extract() as $key => $description) {
                $io->line("{$key}  {$description}");
            }

            return self::SUCCESS;
        }

        if ($io->flag('update')) {
            $surface = (new SurfaceExtractor($io->cwd))->extract();
            SurfaceFile::write($io->cwd, $surface);
            $io->line($io->success(sprintf('%s recorded (%d entries).', SurfaceFile::FILE, count($surface))));

            return self::SUCCESS;
        }

        if (! SurfaceFile::exists($io->cwd)) {
            $io->error(SurfaceFile::FILE.' not found. Record the surface once with vendor/bin/pollora-qa surface --update and commit it.');

            return self::FAILURE;
        }

        $result = (new SurfaceCheck($io->cwd))->run(SurfaceCheck::allowance($io->option('allow')));

        foreach ($result['lines'] as $line) {
            $io->line($line);
        }

        return $result['passed'] ? self::SUCCESS : self::FAILURE;
    }
}
