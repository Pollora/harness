<?php

declare(strict_types=1);

namespace Pollora\Harness\Check;

use Pollora\Harness\Config\HarnessConfig;
use Pollora\Harness\Deps\DependencyPolicy;
use Pollora\Harness\Metrics\Collector;
use Pollora\Harness\Profile;
use Pollora\Harness\Support\Paths;
use Pollora\Harness\Surface\SurfaceCheck;
use Pollora\Harness\Surface\SurfaceFile;

/**
 * Decides which steps `pollora-qa check` runs for a repository.
 *
 * The fast plan (G0, agent hooks, pre-commit) only looks at what changed and
 * stays under half a minute. The full plan (G1 and G2 in CI) runs every gate.
 */
final readonly class Plan
{
    public const string NPM_AUDIT = 'Dependency audit (npm)';

    /**
     * Gates reported but never blocking, whatever `.harness.json` says: a new
     * gate starts here so that a release does not turn a green repository red.
     *
     * @var list<string>
     */
    public const array OBSERVED_BY_DEFAULT = [self::NPM_AUDIT];

    public function __construct(
        private HarnessConfig $config,
        private string $cwd,
    ) {}

    /**
     * @param  list<string>  $changedFiles  PHP files changed in the working tree, relative to $cwd
     * @return list<Step>
     */
    public function fast(array $changedFiles): array
    {
        return [
            $this->style(dirty: true),
            $changedFiles === []
                ? Step::skipped('Static analysis', ['ARCH-02', 'CONV-11'], 'no PHP file changed')
                : $this->staticAnalysis($changedFiles),
            new Step('Architecture', ['ARCH-01', 'ARCH-03', 'ARCH-08', 'ARCH-09', 'CONV-03', 'CONV-05', 'CONV-07'], ['vendor/bin/pest', 'tests/Arch.php'], requires: ['tests/Arch.php', 'vendor/bin/pest']),
            $this->dependencyPolicy(),
        ];
    }

    /**
     * @return list<Step>
     */
    public function full(?string $coverage = null, ?string $infection = null): array
    {
        return [
            $this->style(dirty: false),
            $this->staticAnalysis([]),
            new Step('Rector', ['CONV-11'], ['vendor/bin/rector', 'process', '--dry-run', '--no-progress-bar'], requires: ['rector.php', 'vendor/bin/rector']),
            new Step(
                'Tests and architecture',
                ['ARCH-01', 'ARCH-03', 'ARCH-08', 'ARCH-09', 'CONV-03', 'CONV-05', 'CONV-07'],
                // With --coverage, the suite writes the report the ratchet reads.
                ['vendor/bin/pest', ...($coverage !== null ? ['--coverage-clover', $coverage] : [])],
                requires: ['vendor/bin/pest'],
            ),
            $this->dependencyPolicy(),
            $this->dependencyAnalysis(),
            $this->semgrep(),
            $this->audit(),
            $this->npmAudit(),
            $this->publicSurface(),
            $this->ratchet($coverage, $infection),
        ];
    }

    private function dependencyAnalysis(): Step
    {
        $command = ['vendor/bin/composer-dependency-analyser'];

        // A repository with its own configuration keeps it; the others get the preset.
        if (! is_file($this->cwd.'/composer-dependency-analyser.php')) {
            $command[] = '--config='.Paths::preset('composer-dependency-analyser.php');
        }

        return new Step('Dependency analysis', ['ARCH-07'], $command, requires: ['vendor/bin/composer-dependency-analyser']);
    }

    private function publicSurface(): Step
    {
        $rules = ['QA-04', 'CONV-04', 'CONV-06'];
        $cwd = $this->cwd;

        if (! SurfaceFile::exists($cwd)) {
            return Step::skipped('Public surface', $rules, SurfaceFile::FILE.' not recorded yet (vendor/bin/pollora-qa surface --update)');
        }

        return new Step('Public surface', $rules, static function () use ($cwd): StepResult {
            $result = (new SurfaceCheck($cwd))->run(SurfaceCheck::allowance(null));
            $detail = implode("\n", $result['lines']);

            return $result['passed'] ? StepResult::passed($detail) : StepResult::failed($detail);
        });
    }

    private function style(bool $dirty): Step
    {
        $command = ['vendor/bin/pint', '--test', '--config', Paths::pintConfig($this->cwd)];

        return new Step('Code style', ['ARCH-09'], $dirty ? [...$command, '--dirty'] : $command, requires: ['vendor/bin/pint']);
    }

    /**
     * @param  list<string>  $files  Analyse only these files; all configured paths when empty
     */
    private function staticAnalysis(array $files): Step
    {
        return new Step(
            'Static analysis',
            ['ARCH-02', 'CONV-11'],
            ['vendor/bin/phpstan', 'analyse', '--no-progress', '--memory-limit=1G', ...$files],
            requires: ['vendor/bin/phpstan'],
        );
    }

    private function dependencyPolicy(): Step
    {
        $config = $this->config;
        $cwd = $this->cwd;

        return new Step('Dependency policy', ['ARCH-04', 'ARCH-06'], static function () use ($config, $cwd): StepResult {
            $violations = (new DependencyPolicy($config))->check(DependencyPolicy::readRequire($cwd));

            return $violations === []
                ? StepResult::passed()
                : StepResult::failed(implode("\n", array_map(static fn ($violation): string => '  '.$violation, $violations)));
        });
    }

    private function semgrep(): Step
    {
        $rules = ['SEC-01', 'SEC-03', 'SEC-04', 'SEC-05', 'CONV-10'];
        $semgrep = Paths::which('semgrep');

        if ($semgrep === null) {
            return Step::skipped('Security patterns', $rules, 'semgrep is not installed (pipx install semgrep); CI runs it');
        }

        $configs = ['--config', Paths::preset('semgrep/common.yml')];

        if ($this->config->profile === Profile::Framework) {
            $configs = [...$configs, '--config', Paths::preset('semgrep/framework.yml')];
            $rules[] = 'ARCH-05';
        }

        $targets = array_values(array_filter(['src', 'app', 'config', 'resources', 'routes'], fn (string $path): bool => is_dir($this->cwd.'/'.$path)));

        if ($targets === []) {
            return Step::skipped('Security patterns', $rules, 'no source directory to scan');
        }

        return new Step('Security patterns', $rules, [$semgrep, 'scan', '--metrics=off', '--disable-version-check', '--error', '--quiet', ...$configs, ...$targets]);
    }

    private function audit(): Step
    {
        $composer = Paths::which('composer');

        return $composer === null
            ? Step::skipped('Dependency audit', ['SEC-09'], 'composer is not on the PATH')
            : new Step('Dependency audit', ['SEC-09'], [$composer, 'audit', '--no-interaction']);
    }

    /**
     * `npm audit` over every lock file of the repository (the root and the test
     * suites that have their own). Composer's audit does not see these.
     */
    private function npmAudit(): Step
    {
        $name = self::NPM_AUDIT;
        $npm = Paths::which('npm');
        $directories = $this->npmLockDirectories();

        if ($directories === []) {
            return Step::skipped($name, ['SEC-09'], 'no package-lock.json to audit');
        }

        if ($npm === null) {
            return Step::skipped($name, ['SEC-09'], 'npm is not on the PATH');
        }

        $commands = array_map(
            static fn (string $directory): string => sprintf('%s audit --audit-level=high --prefix %s || status=1', escapeshellarg($npm), escapeshellarg($directory)),
            $directories,
        );

        return new Step($name, ['SEC-09'], ['sh', '-c', 'status=0; '.implode('; ', $commands).'; exit $status']);
    }

    /**
     * @return list<string> Directories (relative to the repository) that hold a package-lock.json
     */
    private function npmLockDirectories(): array
    {
        $directories = [];

        foreach (['', '*/', '*/*/'] as $depth) {
            $locks = glob($this->cwd.'/'.$depth.'package-lock.json');

            if ($locks === false) {
                continue;
            }

            foreach ($locks as $lock) {
                $directory = ltrim(substr(dirname($lock), strlen($this->cwd)), '/');

                if (preg_match('#(^|/)(node_modules|vendor|\.worktrees|\.git)(/|$)#', $directory) !== 1) {
                    $directories[] = $directory === '' ? '.' : $directory;
                }
            }
        }

        return $directories;
    }

    private function ratchet(?string $coverage, ?string $infection): Step
    {
        $config = $this->config;
        $cwd = $this->cwd;

        return new Step('Ratchet', ['QA-01'], static function () use ($config, $cwd, $coverage, $infection): StepResult {
            $rows = $config->ratchet->compare((new Collector($cwd))->collect($coverage, $infection));
            $regressions = array_filter($rows, static fn (array $row): bool => $row['verdict'] === 'regressed');
            $lines = array_map(static fn (array $row): string => sprintf(
                '  %-30s recorded %-8s measured %-8s %s',
                $row['label'],
                $row['recorded'] ?? '-',
                $row['measured'] ?? '-',
                $row['verdict'],
            ), $rows);

            return $regressions === []
                ? StepResult::passed(implode("\n", $lines))
                : StepResult::failed(implode("\n", $lines));
        });
    }
}
