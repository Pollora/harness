<?php

declare(strict_types=1);

namespace Pollora\Harness\Console\Commands;

use Pollora\Harness\Config\HarnessConfig;
use Pollora\Harness\Config\Mode;
use Pollora\Harness\Config\Ratchet;
use Pollora\Harness\Console\Command;
use Pollora\Harness\Console\Io;
use Pollora\Harness\Deps\DependencyPolicy;
use Pollora\Harness\Metrics\Collector;
use Pollora\Harness\Profile;
use Pollora\Harness\Scaffold\Scaffolder;

final class InitCommand implements Command
{
    /** The level a new PHPStan configuration starts at, as most Pollora packages do today. */
    private const DEFAULT_LEVEL = 5;

    public function name(): string
    {
        return 'init';
    }

    public function description(): string
    {
        return 'Apply the harness to this repository (observe mode, nothing overwritten)';
    }

    public function usage(): string
    {
        return <<<'TXT'
              --profile=PROFILE   core, framework, integration, wp-plugin, template or project
              --mode=MODE         observe (default) or enforce
              --downstream=LIST   Repositories to run against this one (core default: framework)
            TXT;
    }

    public function run(Io $io): int
    {
        $existing = HarnessConfig::exists($io->cwd) ? HarnessConfig::load($io->cwd) : null;
        $profile = Profile::tryFrom($io->option('profile') ?? '') ?? $existing?->profile;

        if ($profile === null) {
            $io->error('Choose a profile: --profile='.implode('|', Profile::values()));

            return self::FAILURE;
        }

        $collector = new Collector($io->cwd);
        $level = $collector->phpstanLevel();
        $require = DependencyPolicy::readRequire($io->cwd);

        $downstream = $io->list('downstream');

        $config = new HarnessConfig(
            profile: $profile,
            mode: Mode::tryFrom($io->option('mode') ?? '') ?? $existing->mode ?? Mode::Observe,
            downstream: $downstream !== [] ? $downstream : ($existing->downstream ?? ($profile->hasDownstream() ? ['framework'] : [])),
            // ARCH-04 starts green: the Laravel packages a core package needs
            // today are recorded as exceptions, each one to remove explicitly.
            allowLaravel: $existing->allowLaravel ?? ($profile->mustStayLaravelFree() ? self::laravelPackages($require) : []),
            ratchet: $existing->ratchet ?? Ratchet::fromArray([
                'phpstan_level' => $level ?? self::DEFAULT_LEVEL,
                'phpstan_baseline' => $collector->phpstanBaselineEntries(),
            ]),
        );

        $changes = (new Scaffolder($io->cwd, $config))->apply($level ?? self::DEFAULT_LEVEL);

        foreach ($changes as $change) {
            $io->line('  · '.$change);
        }

        $io->line();
        $io->line($io->success("Harness applied (profile {$profile->value}, {$config->mode->value} mode)."));
        $io->line('Next: composer require --dev pollora/harness, then composer qa.');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, string>  $require
     * @return list<string>
     */
    private static function laravelPackages(array $require): array
    {
        return array_values(array_filter(
            array_keys($require),
            static fn (string $package): bool => str_starts_with($package, 'illuminate/') || str_starts_with($package, 'laravel/'),
        ));
    }
}
