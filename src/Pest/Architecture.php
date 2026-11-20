<?php

declare(strict_types=1);

namespace Pollora\Harness\Pest;

use Closure;
use Pollora\Harness\Architecture\AttributeClasses;
use Pollora\Harness\Architecture\FacadeClasses;
use Pollora\Harness\Architecture\FinalClasses;
use Pollora\Harness\Architecture\ForeignNamespaces;
use Pollora\Harness\Architecture\Layers;
use Pollora\Harness\Architecture\StrictTypes;
use Pollora\Harness\Architecture\TestClasses;
use Pollora\Harness\Config\HarnessConfig;
use Pollora\Harness\Config\RuleExemption;
use Pollora\Harness\Profile;
use Pollora\Harness\Rules\Catalogue;

/**
 * Registers the architecture rules as Pest tests.
 *
 * A repository's tests/Arch.php holds one line:
 *
 *     Pollora\Harness\Pest\Architecture::for(dirname(__DIR__))->register();
 *
 * The rules follow the profile in .harness.json and the layers found in the
 * code, so adding a module or a layer needs no change to the test file. Each
 * test is named after its rule identifier, which the CI output and the agent
 * instructions both quote.
 *
 * A rule listed under `allow_rules` in .harness.json is registered as a
 * skipped test whose reason is "exempted: <rule> — <reason>"; the other rules
 * run as usual.
 *
 * A rule the catalogue (resources/rules.php) lists as `observed` never fails the run: when it finds
 * something, its test is reported as incomplete ("I" in Pest's output) with the findings as the message,
 * and passes when it finds nothing. Promoting a rule to `enforced` in the catalogue makes the same test fail.
 *
 * A rule on a namespace that an installed package also maps is run on the explicit list of the repository's
 * own classes (see ForeignNamespaces); it is only skipped, with the reason, when no such list can be judged.
 */
final readonly class Architecture
{
    private function __construct(
        private Layers $layers,
        private Profile $profile,
        /** @var array<string, RuleExemption> */
        private array $exemptions = [],
        private ForeignNamespaces $foreign = new ForeignNamespaces([]),
        private string $root = '',
    ) {}

    /**
     * @param  list<RuleExemption>|null  $exemptions  Read from .harness.json when null
     */
    public static function for(string $root, ?Profile $profile = null, ?array $exemptions = null): self
    {
        $config = HarnessConfig::exists($root) ? HarnessConfig::load($root) : null;
        $profile ??= $config instanceof HarnessConfig ? $config->profile : Profile::Core;
        $exemptions ??= $config instanceof HarnessConfig ? $config->allowRules : [];

        $byRule = [];

        foreach ($exemptions as $exemption) {
            $byRule[$exemption->rule] = $exemption;
        }

        return new self(Layers::discover($root), $profile, $byRule, ForeignNamespaces::discover($root), $root);
    }

    public function register(): void
    {
        $this->strictTypes();
        $this->hexagon();
        $this->attributes();
        $this->naming();
        $this->configuration();
        $this->conventions();
    }

    /** ARCH-09 */
    private function strictTypes(): void
    {
        $layers = $this->layers;

        foreach ($layers->roots as $root) {
            $this->rule('ARCH-09', "ARCH-09 {$root} declares strict types", static function () use ($layers, $root): void {
                test("ARCH-09 {$root} declares strict types", function () use ($layers, $root): void {
                    $violations = StrictTypes::violations($layers, $root);

                    expect($violations)->toBeEmpty("ARCH-09\n".implode("\n", $violations));
                })->group('arch');
            });
        }
    }

    /** ARCH-01, ARCH-03, CONV-07 */
    private function hexagon(): void
    {
        if (! $this->profile->isHexagonal() || ! $this->layers->isHexagonal()) {
            return;
        }

        $outer = [...$this->layers->of('infrastructure'), ...$this->layers->of('ui')];

        foreach ($this->layers->of('domain') as $domain) {
            $this->rule('ARCH-01', "ARCH-01 {$domain} stays independent of Laravel and of the outer layers", static function (string|array $target) use ($domain, $outer): void {
                arch("ARCH-01 {$domain} stays independent of Laravel and of the outer layers")
                    ->expect($target)
                    ->not->toUse(['Illuminate', ...$outer]);
            }, $domain);

            $this->rule('CONV-07', "CONV-07 {$domain} receives its dependencies instead of resolving them", static function (string|array $target) use ($domain): void {
                arch("CONV-07 {$domain} receives its dependencies instead of resolving them")
                    ->expect($target)
                    ->not->toUse(['app', 'resolve']);
            }, $domain);
        }

        foreach ($this->layers->of('application') as $application) {
            if ($outer !== []) {
                $this->rule('ARCH-03', "ARCH-03 {$application} depends on the domain only", static function (string|array $target) use ($application, $outer): void {
                    arch("ARCH-03 {$application} depends on the domain only")
                        ->expect($target)
                        ->not->toUse($outer);
                }, $application);
            }

            $this->rule('CONV-07', "CONV-07 {$application} receives its dependencies instead of resolving them", static function (string|array $target) use ($application): void {
                arch("CONV-07 {$application} receives its dependencies instead of resolving them")
                    ->expect($target)
                    ->not->toUse(['app', 'resolve']);
            }, $application);
        }
    }

    /** ARCH-08 */
    private function attributes(): void
    {
        $layers = $this->layers;

        $this->rule('ARCH-08', 'ARCH-08 attribute classes are final, readonly and declare their targets', static function () use ($layers): void {
            test('ARCH-08 attribute classes are final, readonly and declare their targets', function () use ($layers): void {
                $problems = AttributeClasses::problems($layers);

                expect($problems)->toBeEmpty("ARCH-08\n".implode("\n", $problems));
            });
        });
    }

    /** CONV-03 */
    private function naming(): void
    {
        foreach ($this->layers->of('domain') as $domain) {
            $exceptions = $domain.'\\Exceptions';

            if ($this->hasNamespace($exceptions)) {
                $this->rule('CONV-03', "CONV-03 {$exceptions} names its classes ...Exception", static function (string|array $target) use ($exceptions): void {
                    arch("CONV-03 {$exceptions} names its classes ...Exception")
                        ->expect($target)
                        ->toHaveSuffix('Exception');
                }, $exceptions);
            }
        }
    }

    /** CONV-05 */
    private function configuration(): void
    {
        foreach ($this->layers->roots as $root) {
            $this->rule('CONV-05', "CONV-05 {$root} reads configuration, never env()", static function (string|array $target) use ($root): void {
                arch("CONV-05 {$root} reads configuration, never env()")
                    ->expect($target)
                    ->not->toUse(['env']);
            }, $root);
        }
    }

    /** CONV-08, CONV-09, CONV-12: rules read from the files, observed until the catalogue says otherwise */
    private function conventions(): void
    {
        $layers = $this->layers;
        $root = $this->root;

        $noClass = $layers->files === [] ? 'no class under the PSR-4 roots of composer.json' : null;
        $noTest = $root === '' || TestClasses::files($root) === [] ? 'no test file under tests/ or the autoload-dev roots' : null;

        $this->observed('CONV-08', 'CONV-08 classes are final unless they are an extension point', static fn (): array => FinalClasses::problems($layers), $noClass);
        $this->observed('CONV-09', 'CONV-09 a facade is a thin front for a contract', static fn (): array => FacadeClasses::problems($layers), $noClass);
        $this->observed('CONV-12', 'CONV-12 tests are Pest functions, not PHPUnit classes', static fn (): array => TestClasses::problems($root), $noTest);
    }

    /**
     * Registers a rule that reads the files of the repository and reports problems.
     *
     * The rule fails the run when the catalogue lists it as `enforced`. When it is `observed` the test never
     * fails: it is marked incomplete, with the problems as its message, so the report shows them and the
     * blocking step stays green (see the README, "Architecture rules that are observed").
     *
     * A rule with nothing to read (no class, no test file) is registered as skipped with that reason, never as a pass.
     *
     * @param  Closure(): list<string>  $problems
     * @param  string|null  $nothingToRead  Why the rule has nothing to judge, when that is the case
     */
    private function observed(string $rule, string $name, Closure $problems, ?string $nothingToRead = null): void
    {
        if ($nothingToRead !== null && ! ($this->exemptions[$rule] ?? null) instanceof RuleExemption) {
            test($name, fn (): null => null)->skip("not evaluated: {$rule} — {$nothingToRead}")->group('arch');

            return;
        }

        $observed = (Catalogue::all()[$rule]['status'] ?? 'enforced') === 'observed';

        $this->rule($rule, $name, static function () use ($rule, $name, $problems, $observed): void {
            test($name, function () use ($rule, $problems, $observed): void {
                $found = $problems();

                if ($observed && $found !== []) {
                    test()->markTestIncomplete("observed, not blocking: {$rule} — ".count($found)." problem(s)\n".implode("\n", $found));
                }

                expect($found)->toBeEmpty("{$rule}\n".implode("\n", $found));
            })->group('arch');
        });
    }

    /**
     * Registers the test of a rule, or a skipped one carrying the reason when `.harness.json` exempts the rule.
     *
     * A rule that judges a namespace an installed package also maps (see ForeignNamespaces) is run on the
     * explicit list of the repository's own classes: the engine would otherwise load the package's classes and
     * fail describing them. A class the package has under the same name cannot be told apart and is left out of
     * the list, with a skipped test that names it; when no class is left, the rule is registered as skipped
     * with that reason. A rule is never dropped without a trace.
     *
     * @param  Closure(string|list<string>): mixed  $register  Registers the test, given the namespace or the list of classes to judge
     * @param  string|null  $namespace  The namespace the rule is about, when it is one the engine resolves
     */
    private function rule(string $rule, string $name, Closure $register, ?string $namespace = null): void
    {
        $exemption = $this->exemptions[$rule] ?? null;

        if ($exemption instanceof RuleExemption) {
            test($name, fn (): null => null)->skip($exemption->message())->group('arch');

            return;
        }

        $target = $namespace ?? '';
        $prefix = $namespace === null ? null : $this->foreign->prefixFor($namespace);

        if ($namespace !== null && $prefix !== null) {
            $classes = [];
            $shadowed = [];

            foreach ($this->layers->classesIn($namespace) as $class) {
                if ($this->foreign->shadows($class)) {
                    $shadowed[] = $class;
                } else {
                    $classes[] = $class;
                }
            }

            if ($shadowed !== []) {
                test($name.' (classes shared with a package)', fn (): null => null)->skip("not evaluated: {$rule} — ".count($shadowed)." class(es) of {$namespace} also exist under the same name in an installed package ({$prefix}, under vendor/) and cannot be told apart: ".implode(', ', $shadowed))->group('arch');
            }

            if ($classes === []) {
                $reason = $shadowed === [] ? 'the repository has no class of its own in it' : 'every class of the repository in it is shared with the package';
                test($name, fn (): null => null)->skip("not evaluated: {$rule} — {$namespace} is also mapped by an installed package ({$prefix}, under vendor/), and {$reason}")->group('arch');

                return;
            }

            $target = $classes;
        }

        $register($target);
    }

    private function hasNamespace(string $namespace): bool
    {
        foreach (array_keys($this->layers->files) as $class) {
            if (str_starts_with($class, $namespace.'\\')) {
                return true;
            }
        }

        return false;
    }
}
