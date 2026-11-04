<?php

declare(strict_types=1);

namespace Pollora\Harness\Pest;

use Closure;
use Pollora\Harness\Architecture\AttributeClasses;
use Pollora\Harness\Architecture\Layers;
use Pollora\Harness\Architecture\StrictTypes;
use Pollora\Harness\Config\HarnessConfig;
use Pollora\Harness\Config\RuleExemption;
use Pollora\Harness\Profile;

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
 */
final readonly class Architecture
{
    private function __construct(
        private Layers $layers,
        private Profile $profile,
        /** @var array<string, RuleExemption> */
        private array $exemptions = [],
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

        return new self(Layers::discover($root), $profile, $byRule);
    }

    public function register(): void
    {
        $this->strictTypes();
        $this->hexagon();
        $this->attributes();
        $this->naming();
        $this->configuration();
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
            $this->rule('ARCH-01', "ARCH-01 {$domain} stays independent of Laravel and of the outer layers", static function () use ($domain, $outer): void {
                arch("ARCH-01 {$domain} stays independent of Laravel and of the outer layers")
                    ->expect($domain)
                    ->not->toUse(['Illuminate', ...$outer]);
            });

            $this->rule('CONV-07', "CONV-07 {$domain} receives its dependencies instead of resolving them", static function () use ($domain): void {
                arch("CONV-07 {$domain} receives its dependencies instead of resolving them")
                    ->expect($domain)
                    ->not->toUse(['app', 'resolve']);
            });
        }

        foreach ($this->layers->of('application') as $application) {
            if ($outer !== []) {
                $this->rule('ARCH-03', "ARCH-03 {$application} depends on the domain only", static function () use ($application, $outer): void {
                    arch("ARCH-03 {$application} depends on the domain only")
                        ->expect($application)
                        ->not->toUse($outer);
                });
            }

            $this->rule('CONV-07', "CONV-07 {$application} receives its dependencies instead of resolving them", static function () use ($application): void {
                arch("CONV-07 {$application} receives its dependencies instead of resolving them")
                    ->expect($application)
                    ->not->toUse(['app', 'resolve']);
            });
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
                $this->rule('CONV-03', "CONV-03 {$exceptions} names its classes ...Exception", static function () use ($exceptions): void {
                    arch("CONV-03 {$exceptions} names its classes ...Exception")
                        ->expect($exceptions)
                        ->toHaveSuffix('Exception');
                });
            }
        }
    }

    /** CONV-05 */
    private function configuration(): void
    {
        foreach ($this->layers->roots as $root) {
            $this->rule('CONV-05', "CONV-05 {$root} reads configuration, never env()", static function () use ($root): void {
                arch("CONV-05 {$root} reads configuration, never env()")
                    ->expect($root)
                    ->not->toUse(['env']);
            });
        }
    }

    /**
     * Registers the test of a rule, or a skipped one carrying the reason when `.harness.json` exempts the rule.
     *
     * @param  Closure(): mixed  $register
     */
    private function rule(string $rule, string $name, Closure $register): void
    {
        $exemption = $this->exemptions[$rule] ?? null;

        if ($exemption instanceof RuleExemption) {
            test($name, fn (): null => null)->skip($exemption->message())->group('arch');

            return;
        }

        $register();
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
