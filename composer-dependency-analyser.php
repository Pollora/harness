<?php

declare(strict_types=1);

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

/*
 * ARCH-07 for the harness itself.
 *
 * The harness ships tools that run as binaries or as PHPStan includes rather
 * than being called from its code, and it registers Pest architecture tests
 * from src/ for the repositories that already require Pest.
 */

return (new Configuration)
    ->disableReportingUnmatchedIgnores()
    // Fixtures break the rules on purpose and call WordPress, which is not installed here.
    ->addPathToExclude(__DIR__.'/tests/Fixtures')
    ->ignoreErrorsOnPackages([
        'laravel/pint',
        'phpstan/phpstan-deprecation-rules',
        'phpstan/phpstan-strict-rules',
        'shipmonk/composer-dependency-analyser',
        'szepeviktor/phpstan-wordpress',
    ], [ErrorType::UNUSED_DEPENDENCY])
    ->ignoreErrorsOnPackages(['pestphp/pest', 'pestphp/pest-plugin-arch'], [ErrorType::DEV_DEPENDENCY_IN_PROD, ErrorType::SHADOW_DEPENDENCY])
    // The PHPStan rule uses the PHP-Parser that PHPStan bundles and loads:
    // requiring nikic/php-parser could install a second, incompatible copy.
    ->ignoreErrorsOnPackage('nikic/php-parser', [ErrorType::SHADOW_DEPENDENCY])
    // PHPStan and Rector ship their own prefixed copies of these classes.
    ->ignoreUnknownClassesRegex('~^(PHPStan|PhpParser|Rector)\\\\~')
    ->ignoreUnknownFunctions(['arch', 'test', 'expect']);
