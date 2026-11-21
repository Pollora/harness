<?php

declare(strict_types=1);

namespace Pollora\Harness\Console\Commands;

use Pollora\Harness\Config\HarnessConfig;
use Pollora\Harness\Config\Mode;
use Pollora\Harness\Config\MutationSettings;
use Pollora\Harness\Config\NpmAuditSettings;
use Pollora\Harness\Config\PerformanceSettings;
use Pollora\Harness\Config\Ratchet;
use Pollora\Harness\Console\Command;
use Pollora\Harness\Console\Io;
use Pollora\Harness\Deps\DependencyPolicy;
use Pollora\Harness\Metrics\Collector;
use Pollora\Harness\Metrics\TypeCoverage;
use Pollora\Harness\Profile;
use Pollora\Harness\Scaffold\Scaffolder;

final class InitCommand implements Command
{
    /** The level a new PHPStan configuration starts at, as most Pollora packages do today. */
    private const int DEFAULT_LEVEL = 5;

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
              --config=FILE       With --profile=template, where to write the file (default .harness.json; .github/harness.json is read too)
              --downstream=LIST   Repositories to run against this one (none by default, for instance framework)
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

        if ($profile === Profile::Template) {
            return $this->template($io);
        }

        $collector = new Collector($io->cwd);
        $level = $collector->phpstanLevel();
        $require = DependencyPolicy::readRequire($io->cwd);

        $downstream = $io->list('downstream');

        $config = new HarnessConfig(
            profile: $profile,
            mode: Mode::tryFrom($io->option('mode') ?? '') ?? $existing->mode ?? Mode::Observe,
            downstream: $downstream !== [] ? $downstream : ($existing->downstream ?? []),
            // ARCH-04 starts green: the Laravel packages a core package needs
            // today are recorded as exceptions, each one to remove explicitly.
            allowLaravel: $existing->allowLaravel ?? ($profile->mustStayLaravelFree() ? $this->laravelPackages($require) : []),
            ratchet: $existing->ratchet ?? Ratchet::fromArray([
                'phpstan_level' => $level ?? self::DEFAULT_LEVEL,
                'phpstan_baseline' => $collector->phpstanBaselineEntries(),
            ]),
            protected: $existing->protected ?? HarnessConfig::DEFAULT_PROTECTED,
            observed: $existing->observed ?? [],
            allowCommands: $existing->allowCommands ?? [],
            allowRules: $existing->allowRules ?? [],
            // What init does not decide is carried over whole: running it again must never erase a setting.
            mutation: $existing->mutation ?? new MutationSettings,
            npmAudit: $existing->npmAudit ?? new NpmAuditSettings,
            services: $existing->services ?? [],
            slowStepShare: $existing?->slowStepShare,
            performance: $existing->performance ?? new PerformanceSettings,
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
     * `init --profile=template`: the one file a template can carry, `template.yml` reading it, and nothing else.
     *
     * A template cannot hold the rest of the harness (no `pollora/harness`, no `AGENTS.md`, no workflow of its own
     * but the one that calls `template.yml`), so this writes the profile and the type coverage floor measured now,
     * rounded down to a tenth. A file that already exists keeps everything it has: only a floor it lacks is added.
     */
    private function template(Io $io): int
    {
        $file = $io->option('config') ?? HarnessConfig::locateForTemplate($io->cwd) ?? HarnessConfig::FILE;
        $existing = is_file(HarnessConfig::path($io->cwd, $file)) ? HarnessConfig::loadAny($io->cwd, $file) : null;
        $config = $existing ?? new HarnessConfig(Profile::Template);
        $measured = TypeCoverage::measure($io->cwd)->percentage();

        if ($measured !== null && $config->ratchet->get('type_coverage') === null) {
            $config = $config->withRatchet(new Ratchet([...$config->ratchet->values, 'type_coverage' => floor($measured * 10) / 10]));
        }

        $config->save($io->cwd, $file, template: true);

        $io->line('  · wrote '.$file.($measured === null ? ' (no PHP to measure: no type coverage floor recorded)' : ''));
        $io->line();
        $io->line($io->success("Template file written ({$file}). Nothing else was created: template.yml reads it."));

        return self::SUCCESS;
    }

    /**
     * @param  array<string, string>  $require
     * @return list<string>
     */
    private function laravelPackages(array $require): array
    {
        return array_values(array_filter(
            array_keys($require),
            static fn (string $package): bool => str_starts_with($package, 'illuminate/') || str_starts_with($package, 'laravel/'),
        ));
    }
}
