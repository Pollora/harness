<?php

declare(strict_types=1);

namespace Pollora\Harness\Check;

use Pollora\Harness\Architecture\ClassExemptions;
use Pollora\Harness\Architecture\Layers;
use Pollora\Harness\Config\HarnessConfig;
use Pollora\Harness\Config\NpmAuditSettings;
use Pollora\Harness\Config\Ratchet;
use Pollora\Harness\Config\RuleExemption;
use Pollora\Harness\Console\Commands\NpmAuditCommand;
use Pollora\Harness\Deps\DependencyPolicy;
use Pollora\Harness\Distribution\Archive;
use Pollora\Harness\Metrics\Collector;
use Pollora\Harness\Metrics\MissingReports;
use Pollora\Harness\Metrics\TypeCoverage;
use Pollora\Harness\Npm\NpmAudit;
use Pollora\Harness\Performance\PerformanceCheck;
use Pollora\Harness\Profile;
use Pollora\Harness\Rules\Catalogue;
use Pollora\Harness\Support\ChangedSources;
use Pollora\Harness\Support\InterpreterFlags;
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

    public const string ARCHIVE = 'Distribution archive';

    public const string MUTATION = 'Mutation testing';

    /**
     * The mutation gate of a repository that declares `mutation.scope: "changed"`. Unlike the whole-repository one
     * it is not observed by default: the repository chose a minimum, and a change below it blocks (in enforce mode).
     */
    public const string MUTATION_CHANGED = 'Mutation testing (changed files)';

    /** The same gate for `mutation.scope: "added"`: only the files a change adds are mutated. */
    public const string MUTATION_ADDED = 'Mutation testing (new files)';

    /** PERF-01 and PERF-07: the query budgets and the nightly measures, read from the files. */
    public const string PERFORMANCE = 'Performance budgets';

    public const string GIT_UNUSABLE = 'git is not usable here (a worktree whose .git points outside this environment, no git, or an untrusted directory): checking the whole tree instead of the changed files.';

    public const string ARCH_OUTSIDE_SUITES = 'tests/Arch.php is in no test suite of phpunit.xml, so vendor/bin/pest alone would skip the architecture rules: running it explicitly too (add it to a suite to run it once).';

    /**
     * The rules the architecture steps name in the summary: those `Architecture::register()` evaluates.
     * `RuleExemption::EXEMPTIBLE` lists the same rules (a test keeps them aligned).
     *
     * @var list<string>
     */
    public const array ARCHITECTURE_RULES = ['ARCH-01', 'ARCH-03', 'ARCH-08', 'ARCH-09', 'CONV-02', 'CONV-03', 'CONV-05', 'CONV-07', 'CONV-08', 'CONV-09', 'CONV-12'];

    /**
     * Gates reported but never blocking, whatever `.harness.json` says: a new
     * gate starts here so that a release does not turn a green repository red.
     *
     * @var list<string>
     */
    public const array OBSERVED_BY_DEFAULT = [self::NPM_AUDIT, self::MUTATION, self::PERFORMANCE];

    public function __construct(
        private HarnessConfig $config,
        private string $cwd,
    ) {}

    /**
     * @param  list<string>|null  $changedFiles  PHP files changed in the working tree, relative to $cwd; null when git cannot say, and the whole tree is checked
     * @return list<Step>
     */
    public function fast(?array $changedFiles): array
    {
        $note = self::GIT_UNUSABLE;

        return [
            $changedFiles === null ? $this->style(dirty: false, note: $note) : $this->style(dirty: true),
            $changedFiles === null ? $this->staticAnalysis([], $note) : ($changedFiles === []
                ? Step::skipped('Static analysis', ['ARCH-02', 'CONV-11'], 'no PHP file changed')
                : $this->staticAnalysis($changedFiles)),
            new Step('Architecture', self::ARCHITECTURE_RULES, ['vendor/bin/pest', 'tests/Arch.php'], requires: ['tests/Arch.php', 'vendor/bin/pest'], note: $this->exemptedRules(), exempted: $this->exemptedRuleIds()),
            $this->dependencyPolicy(),
        ];
    }

    /**
     * @param  string|null  $mutationBase  The commit the changed files are counted from (`mutation.scope: "changed"`); `HEAD^1` when null
     * @param  string|null  $budgetsBase  The commit the query budgets are compared with (PERF-01); `HEAD^1` when null
     * @return list<Step>
     */
    public function full(?string $coverage = null, ?string $infection = null, bool $mutation = false, ?string $mutationBase = null, ?string $budgetsBase = null): array
    {
        $score = new MutationScore;

        return [
            $this->style(dirty: false),
            $this->staticAnalysis([]),
            new Step('Rector', ['CONV-11'], ['vendor/bin/rector', 'process', '--dry-run', '--no-progress-bar'], requires: ['rector.php', 'vendor/bin/rector']),
            new Step(
                'Tests and architecture',
                self::ARCHITECTURE_RULES,
                $this->tests($coverage),
                requires: ['vendor/bin/pest'],
                note: $this->joinNotes($this->architectureOutsideSuites() ? self::ARCH_OUTSIDE_SUITES : null, $this->exemptedRules()),
                exempted: $this->exemptedRuleIds(),
            ),
            $this->dependencyPolicy(),
            $this->dependencyAnalysis(),
            $this->semgrep(),
            $this->audit(),
            $this->npmAudit(),
            $this->publicSurface(),
            $this->distributionArchive(),
            $this->performanceBudgets($budgetsBase),
            ...($mutation ? [$this->config->mutation->limited() ? $this->mutationOfChangedFiles($mutationBase) : $this->mutation($score)] : []),
            $this->ratchet($coverage, $infection, $score, $mutation && $this->config->mutation->limited()),
        ];
    }

    /**
     * The rules `.harness.json` exempts (allow_rules), one line each, so the gate says what it does not evaluate
     * (and which declared exemptions it does not apply yet, because the rule is observed).
     */
    private function exemptedRules(): ?string
    {
        if ($this->config->allowRules === []) {
            return null;
        }

        $lines = [];
        $partials = [];

        foreach ($this->config->allowRules as $exemption) {
            if ($exemption->isPartial()) {
                $partials[$exemption->rule][] = $exemption;
            } else {
                $lines[] = $exemption->message().' (allow_rules)';
            }
        }

        if ($partials !== []) {
            $layers = Layers::discover($this->cwd);

            foreach ($partials as $rule => $exemptions) {
                $applied = (Catalogue::all()[$rule]['status'] ?? 'enforced') !== 'observed';

                foreach (ClassExemptions::apply($exemptions, ClassExemptions::problemsOf($rule, $layers, $this->cwd), $layers, $this->cwd)->effects as $effect) {
                    array_push($lines, $effect->message($applied).' (allow_rules)', ...$effect->warnings());
                }
            }
        }

        return implode("\n  ", $lines);
    }

    /**
     * The exemptions that take effect now: a declared exemption of an observed rule is not one, and an exemption
     * limited to some classes does not stop the rule, which keeps running on the others.
     *
     * @return list<string>
     */
    private function exemptedRuleIds(): array
    {
        return array_values(array_map(
            static fn (RuleExemption $exemption): string => $exemption->rule,
            array_filter($this->config->allowRules, static fn (RuleExemption $exemption): bool => $exemption->applies() && ! $exemption->isPartial()),
        ));
    }

    private function joinNotes(?string ...$notes): ?string
    {
        $notes = array_values(array_filter($notes, static fn (?string $note): bool => $note !== null));

        return $notes === [] ? null : implode("\n  ", $notes);
    }

    /**
     * With --coverage, the suite writes the report the ratchet reads, in a
     * process that has the coverage driver this one was started with.
     *
     * @return list<string>
     */
    private function tests(?string $coverage): array
    {
        $flags = $coverage === null ? [] : [...InterpreterFlags::inherited(), ...InterpreterFlags::coverageScope($this->cwd)];
        $command = $coverage === null
            ? ['vendor/bin/pest']
            : [...($flags === [] ? [] : [PHP_BINARY, ...$flags]), 'vendor/bin/pest', '--coverage-clover', $coverage];

        if (! $this->architectureOutsideSuites()) {
            return $command;
        }

        // `pest tests/Arch.php` replaces the suites, so it is a second run: both must pass, whatever the first says

        return ['sh', '-c', sprintf('status=0; %s || status=1; %s || status=1; exit $status', self::shellLine($command), self::shellLine(['vendor/bin/pest', 'tests/Arch.php']))];
    }

    /**
     * @param  list<string>  $words
     */
    private static function shellLine(array $words): string
    {
        $escaped = [];

        foreach ($words as $word) {
            $escaped[] = escapeshellarg($word);
        }

        return implode(' ', $escaped);
    }

    /**
     * tests/Arch.php exists but no suite of phpunit.xml runs it: `vendor/bin/pest` would never evaluate the architecture rules.
     */
    private function architectureOutsideSuites(): bool
    {
        return is_file($this->cwd.'/tests/Arch.php') && ! (new PhpUnitSuites($this->cwd))->runs('tests/Arch.php');
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
        $exemptions = $this->config->allowCommands;

        if (! SurfaceFile::exists($cwd)) {
            return Step::skipped('Public surface', $rules, SurfaceFile::FILE.' not recorded yet (vendor/bin/pollora-qa surface --update)');
        }

        return new Step('Public surface', $rules, static function () use ($cwd, $exemptions): StepResult {
            $result = (new SurfaceCheck($cwd, $exemptions))->run(SurfaceCheck::allowance(null));
            $detail = implode("\n", $result['lines']);

            return $result['passed'] ? StepResult::passed($detail) : StepResult::failed($detail);
        });
    }

    /**
     * PERF-01 and PERF-07: the budgets file and the nightly measures, checked statically. Skipped, with the reason,
     * in a repository that declares neither: a skipped step is never a pass.
     */
    private function performanceBudgets(?string $base): Step
    {
        $rules = ['PERF-01', 'PERF-07'];
        $check = new PerformanceCheck($this->cwd, $this->config->performance, $base ?? ChangedSources::DEFAULT_BASE);
        $reason = $check->skipReason();

        if ($reason !== null) {
            return Step::skipped(self::PERFORMANCE, $rules, $reason);
        }

        return new Step(self::PERFORMANCE, $rules, $check->run(...));
    }

    /**
     * QA-06: what `git archive` would hold. A template or a project is the product itself, so it is not checked.
     */
    private function distributionArchive(): Step
    {
        $name = self::ARCHIVE;
        $rules = ['QA-06'];

        if (! Archive::applies($this->config->profile)) {
            return Step::skipped($name, $rules, 'a '.$this->config->profile->value.' is its own product, not installed from an archive');
        }

        $archive = new Archive($this->cwd);

        return new Step($name, $rules, static function () use ($archive): StepResult {
            $entries = $archive->entries();

            if ($entries === null) {
                return StepResult::skipped('git archive cannot be built here (no git, or no commit yet)');
            }

            $offenders = Archive::offenders($entries);

            if ($offenders === []) {
                return StepResult::passed(count($entries).' files in the archive, none for development');
            }

            return StepResult::failed(implode("\n", [
                '  '.count($offenders).' development files would travel in the archive Composer downloads:',
                ...array_map(static fn (string $line): string => '    '.$line, array_slice($offenders, 0, 8)),
                ...(count($offenders) > 8 ? ['    … and '.(count($offenders) - 8).' more'] : []),
                '  Fix: vendor/bin/pollora-qa archive --fix, or add to .gitattributes:',
                ...array_map(static fn (string $line): string => '    '.$line, $archive->missing(Archive::linesFor($offenders))),
            ]));
        });
    }

    private function style(bool $dirty, ?string $note = null): Step
    {
        $command = ['vendor/bin/pint', '--test', '--config', Paths::pintConfig($this->cwd)];

        return new Step('Code style', ['ARCH-09'], $dirty ? [...$command, '--dirty'] : $command, requires: ['vendor/bin/pint'], note: $note);
    }

    /**
     * @param  list<string>  $files  Analyse only these files; all configured paths when empty
     */
    private function staticAnalysis(array $files, ?string $note = null): Step
    {
        return new Step(
            'Static analysis',
            ['ARCH-02', 'CONV-11'],
            ['vendor/bin/phpstan', 'analyse', '--no-progress', '--memory-limit=1G', ...$files],
            requires: ['vendor/bin/phpstan'],
            note: $note,
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
        $rules = ['SEC-01', 'SEC-02', 'SEC-03', 'SEC-04', 'SEC-05', 'SEC-06', 'SEC-07', 'CONV-10'];
        $semgrep = Paths::which('semgrep');

        if ($semgrep === null) {
            return Step::missingTool('Security patterns', $rules, 'semgrep is not installed, so these rules were not checked (CI runs it)', 'pipx install semgrep');
        }

        $configs = ['--config', Paths::preset('semgrep/common.yml')];

        if ($this->config->profile === Profile::Framework) {
            $configs = [...$configs, '--config', Paths::preset('semgrep/framework.yml')];
            $rules[] = 'ARCH-05';
        }

        $targets = $this->semgrepTargets();

        if ($targets === []) {
            return Step::skipped('Security patterns', $rules, 'no source directory to scan');
        }

        return new Step('Security patterns', $rules, [$semgrep, 'scan', '--metrics=off', '--disable-version-check', '--error', '--quiet', ...$configs, ...$targets]);
    }

    /**
     * The directories Semgrep scans: those of the source tree that exist.
     *
     * @return list<string>
     */
    private function semgrepTargets(): array
    {
        return array_values(array_filter(['src', 'app', 'config', 'resources', 'routes'], fn (string $path): bool => is_dir($this->cwd.'/'.$path)));
    }

    private function audit(): Step
    {
        $composer = Paths::which('composer');

        return $composer === null
            ? Step::missingTool('Dependency audit', ['SEC-09'], 'composer is not on the PATH', 'https://getcomposer.org/download/')
            : new Step('Dependency audit', ['SEC-09'], [$composer, 'audit', '--no-interaction']);
    }

    /**
     * `npm audit` over every lock file of the repository (the root and the test
     * suites that have their own). Composer's audit does not see these. Only the
     * advisories `npm_audit.allow` lists, until their expiry, are tolerated.
     */
    private function npmAudit(): Step
    {
        $name = self::NPM_AUDIT;
        $npm = Paths::which('npm');
        $directories = NpmAudit::lockDirectories($this->cwd);

        if ($directories === []) {
            return Step::skipped($name, ['SEC-09'], 'no package-lock.json to audit');
        }

        if ($npm === null) {
            return Step::missingTool($name, ['SEC-09'], 'npm is not on the PATH', 'install Node.js (https://nodejs.org)');
        }

        $cwd = $this->cwd;
        $settings = $this->config->npmAudit;

        return new Step(
            $name,
            ['SEC-09'],
            static function () use ($cwd, $npm, $settings, $directories): StepResult {
                $result = (new NpmAudit($cwd, $npm, $settings, NpmAuditSettings::today()))->audit($directories);
                NpmAuditCommand::writeSummary($result);

                return $result->passed() ? StepResult::passed($result->render()) : StepResult::failed($result->render());
            },
        );
    }

    /**
     * Mutation testing with Pest. Reported, not enforced: it feeds the `msi` metric of the ratchet, which only
     * fails a repository that recorded a score and let it drop.
     */
    private function mutation(MutationScore $score): Step
    {
        $cwd = $this->cwd;

        return new Step(
            self::MUTATION,
            ['QA-01'],
            static function () use ($cwd, $score): StepResult {
                $run = MutationRun::run($cwd);
                $score->value = $run['score'];

                if ($run['unwritable']) {
                    return StepResult::failed(MutationRun::unwritableMessage());
                }

                return $run['score'] === null
                    ? StepResult::failed(($run['empty'] ? 'Pest created no mutation: the tests cover no code it can mutate.' : 'Pest printed no mutation score: the tests must pass first.')."\n".trim(substr($run['output'], -600)))
                    : StepResult::passed(sprintf('  Mutation score %s %% (%s)', $run['score'], $run['summary']));
            },
            requires: ['vendor/pestphp/pest-plugin-mutate'],
        );
    }

    /**
     * Mutation testing limited to the source files a change touches, held to `mutation.min`.
     *
     * The score of some files is not comparable with the score of other files, so it never reaches the ratchet:
     * the threshold is a fixed number of `.harness.json`, checked here, and the step blocks below it. A change
     * that touches no source file, or only files Pest creates no mutation in (an interface), passes; a change
     * whose files cannot be listed (a history too short, an unknown base) fails rather than skip the check.
     */
    private function mutationOfChangedFiles(?string $base): Step
    {
        $cwd = $this->cwd;
        $min = $this->config->mutation->min;
        $added = $this->config->mutation->addedOnly();
        $base ??= ChangedSources::DEFAULT_BASE;

        return new Step(
            $added ? self::MUTATION_ADDED : self::MUTATION_CHANGED,
            ['QA-01'],
            static function () use ($cwd, $min, $base, $added): StepResult {
                $files = ChangedSources::since($cwd, $base, $added ? ChangedSources::ADDED : ChangedSources::CHANGED);
                $skipped = $added && $files !== null ? count(ChangedSources::since($cwd, $base) ?? []) - count($files) : 0;
                $edited = $skipped > 0 ? sprintf(' (%d modified file%s not mutated: the gate holds new files only)', $skipped, $skipped === 1 ? '' : 's') : '';

                if ($files === null) {
                    return StepResult::failed(sprintf('Cannot list the files changed since %s. The checkout needs a history of two commits (`fetch-depth: 2` in actions/checkout) or a base given with --mutation-base=<ref>.', $base));
                }

                if ($files === []) {
                    return StepResult::passed(sprintf('  No %ssource file %s since %s: nothing to mutate%s.', $added ? 'new ' : '', $added ? 'added' : 'changed', $base, $edited));
                }

                $run = MutationRun::run($cwd, $files);

                if ($run['unwritable']) {
                    return StepResult::failed(MutationRun::unwritableMessage());
                }

                $count = sprintf('%d %s file%s', count($files), $added ? 'new' : 'changed', count($files) === 1 ? '' : 's');

                if ($run['empty']) {
                    return StepResult::passed(sprintf('  Pest created no mutation in the %s (interfaces, or nothing to mutate): nothing to measure.', $count));
                }

                if ($run['score'] === null) {
                    return StepResult::failed("Pest printed no mutation score: the tests must pass first.\n".trim(substr($run['output'], -600)));
                }

                $line = sprintf('  Mutation score of the %s: %s %% (%s)%s', $count, $run['score'], $run['summary'], $edited);

                if ($min === null) {
                    return StepResult::passed($line.' — no minimum set (mutation.min), reported only');
                }

                if ($min <= $run['score'] + Ratchet::TOLERANCE) {
                    return StepResult::passed(sprintf('%s, minimum %s %%', $line, $min));
                }

                $survivors = MutationRun::survivors($run['output']);

                return StepResult::failed(implode("\n", [
                    sprintf('%s is below the minimum of %s %% (mutation.min).', $line, $min),
                    'A score is computed on whole files: a mutant that survives in a file you touched counts even if you did not write it. Add the test that would fail without that line, or raise the score of the files you changed.',
                    ...array_map(static fn (string $survivor): string => '  '.$survivor, $survivors),
                ]));
            },
            requires: ['vendor/pestphp/pest-plugin-mutate'],
        );
    }

    /**
     * @param  bool  $mutationLimited  The mutation run covered some files only, so `msi` is not measured on purpose
     */
    private function ratchet(?string $coverage, ?string $infection, ?MutationScore $mutation = null, bool $mutationLimited = false): Step
    {
        $config = $this->config;
        $cwd = $this->cwd;

        return new Step('Ratchet', ['QA-01'], static function () use ($config, $cwd, $coverage, $infection, $mutation, $mutationLimited): StepResult {
            $collector = new Collector($cwd);
            $measured = $mutation?->value;
            $types = TypeCoverage::measure($cwd);
            $overrides = ['type_coverage' => $types->percentage()] + ($measured === null ? [] : ['msi' => $measured]);
            $rows = $config->ratchet->compare($collector->collect($coverage, $infection, $overrides, Collector::exemptedRules($config), Collector::exemptedClasses($config, $cwd)));
            $source = $coverage === null ? $collector->findClover() : null;
            $regressions = array_filter($rows, static fn (array $row): bool => $row['verdict'] === 'regressed');
            $lines = array_map(static fn (array $row): string => sprintf(
                '  %-30s recorded %-8s measured %-8s %s',
                $row['label'],
                $row['recorded'] ?? '-',
                $row['measured'] ?? '-',
                MissingReports::verdict($row),
            ), $rows);

            if ($source !== null) {
                $lines[] = sprintf('  Line coverage read from %s (no --coverage given); run `check --coverage` to refresh it.', $source);
            }

            $notes = MissingReports::notes($rows, $mutationLimited);

            array_push($lines, ...$types->lines(), ...$notes);

            return $regressions === []
                ? StepResult::passed(implode("\n", $lines))
                : StepResult::failed(implode("\n", $lines));
        });
    }
}
