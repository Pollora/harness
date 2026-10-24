<?php

declare(strict_types=1);

namespace Pollora\Harness\Pest;

use Pollora\Harness\Architecture\AttributeClasses;
use Pollora\Harness\Architecture\Layers;
use Pollora\Harness\Config\HarnessConfig;
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
 */
final class Architecture
{
    private function __construct(
        private readonly Layers $layers,
        private readonly Profile $profile,
    ) {}

    public static function for(string $root, ?Profile $profile = null): self
    {
        $profile ??= HarnessConfig::exists($root) ? HarnessConfig::load($root)->profile : Profile::Core;

        return new self(Layers::discover($root), $profile);
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
        foreach ($this->layers->roots as $root) {
            arch("ARCH-09 {$root} declares strict types")
                ->expect($root)
                ->toUseStrictTypes();
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
            arch("ARCH-01 {$domain} stays independent of Laravel and of the outer layers")
                ->expect($domain)
                ->not->toUse(['Illuminate', ...$outer]);

            arch("CONV-07 {$domain} receives its dependencies instead of resolving them")
                ->expect($domain)
                ->not->toUse(['app', 'resolve']);
        }

        foreach ($this->layers->of('application') as $application) {
            if ($outer !== []) {
                arch("ARCH-03 {$application} depends on the domain only")
                    ->expect($application)
                    ->not->toUse($outer);
            }

            arch("CONV-07 {$application} receives its dependencies instead of resolving them")
                ->expect($application)
                ->not->toUse(['app', 'resolve']);
        }
    }

    /** ARCH-08 */
    private function attributes(): void
    {
        $layers = $this->layers;

        test('ARCH-08 attribute classes are final, readonly and declare their targets', function () use ($layers): void {
            $problems = AttributeClasses::problems($layers);

            expect($problems)->toBeEmpty("ARCH-08\n".implode("\n", $problems));
        });
    }

    /** CONV-03 */
    private function naming(): void
    {
        foreach ($this->layers->of('domain') as $domain) {
            $exceptions = $domain.'\\Exceptions';

            if ($this->hasNamespace($exceptions)) {
                arch("CONV-03 {$exceptions} names its classes ...Exception")
                    ->expect($exceptions)
                    ->toHaveSuffix('Exception');
            }
        }
    }

    /** CONV-05 */
    private function configuration(): void
    {
        foreach ($this->layers->roots as $root) {
            arch("CONV-05 {$root} reads configuration, never env()")
                ->expect($root)
                ->not->toUse(['env']);
        }
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
