<?php

declare(strict_types=1);

namespace Pollora\Harness\Rector;

use Pollora\Harness\Profile;
use Rector\CodeQuality\Rector\BooleanNot\NegatedAndsToPositiveOrsRector;
use Rector\CodeQuality\Rector\ClassMethod\LocallyCalledStaticMethodToNonStaticRector;
use Rector\CodingStyle\Rector\String_\SimplifyQuoteEscapeRector;
use Rector\Config\RectorConfig;
use Rector\Configuration\RectorConfigBuilder;
use Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPublicMethodParameterRector;
use Rector\Php82\Rector\Class_\ReadOnlyClassRector;
use Rector\TypeDeclaration\Rector\ClassMethod\NarrowObjectReturnTypeRector;
use Rector\TypeDeclaration\Rector\ClassMethod\ReturnNeverTypeRector;

/**
 * The Rector configuration shared by every repository, built on the one the
 * framework already runs.
 *
 * A repository's rector.php starts from it and only adds its own skips:
 *
 *     return Pollora\Harness\Rector\Preset::configure(__DIR__)
 *         ->withSkip([...]);
 *
 * Rector runs with --dry-run in CI: a pull request that leaves a
 * modernisation on the table fails, and `vendor/bin/rector` applies it.
 */
final class Preset
{
    /**
     * Rules the harness never applies, each with its reason.
     *
     * @var list<class-string>
     */
    public const array SKIPPED = [
        // Pint writes `! $x`, these rules write `!$x` and double quotes: the two tools would undo each other
        NegatedAndsToPositiveOrsRector::class,
        SimplifyQuoteEscapeRector::class,
        // They change the public API of classes that applications extend (ARCH-09): a readonly class cannot be extended by a
        // mutable one, a narrower return type or a removed parameter breaks an override, a static method turned into an instance one breaks its callers
        ReadOnlyClassRector::class,
        RemoveUnusedPublicMethodParameterRector::class,
        NarrowObjectReturnTypeRector::class,
        ReturnNeverTypeRector::class,
        LocallyCalledStaticMethodToNonStaticRector::class,
    ];

    /**
     * @param  list<string>  $paths  Relative to $root; src and tests when they exist
     */
    public static function configure(string $root, ?Profile $profile = null, array $paths = []): RectorConfigBuilder
    {
        $paths = $paths === [] ? ['src', 'tests'] : $paths;
        $absolute = array_values(array_filter(
            array_map(static fn (string $path): string => rtrim($root, '/').'/'.$path, $paths),
            file_exists(...),
        ));

        $builder = RectorConfig::configure()
            ->withPaths($absolute)
            ->withPreparedSets(
                deadCode: true,
                codeQuality: true,
                codingStyle: true,
                typeDeclarations: true,
                privatization: true,
                earlyReturn: true,
            )
            ->withPhpSets()
            ->withSkip(self::skips());

        return self::withLaravelSets($builder, $profile);
    }

    /**
     * The skipped rules the installed Rector has: another version may not ship them.
     *
     * @return list<class-string>
     */
    private static function skips(): array
    {
        return array_values(array_filter(self::SKIPPED, class_exists(...)));
    }

    /**
     * Adds the Laravel sets when driftingly/rector-laravel is installed: the
     * framework, integration and project profiles install it, the core
     * profile does not (it stays Laravel-free).
     */
    private static function withLaravelSets(RectorConfigBuilder $builder, ?Profile $profile): RectorConfigBuilder
    {
        $sets = 'RectorLaravel\Set\LaravelSetList';

        if ($profile === Profile::Core || ! class_exists($sets)) {
            return $builder;
        }

        return $builder->withSets(array_values(array_filter([
            self::constant('RectorLaravel\Set\LaravelLevelSetList', 'UP_TO_LARAVEL_130'),
            self::constant($sets, 'LARAVEL_CODE_QUALITY'),
            self::constant($sets, 'LARAVEL_COLLECTION'),
            self::constant($sets, 'LARAVEL_TYPE_DECLARATIONS'),
        ], static fn (?string $set): bool => $set !== null)));
    }

    private static function constant(string $class, string $name): ?string
    {
        $constant = $class.'::'.$name;

        if (! class_exists($class) || ! defined($constant)) {
            return null;
        }

        $value = constant($constant);

        return is_string($value) ? $value : null;
    }
}
