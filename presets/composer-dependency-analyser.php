<?php

declare(strict_types=1);

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

/*
 * ARCH-07 for a Pollora repository that has no composer-dependency-analyser.php of its own.
 *
 * The analyser reads the classes the code uses and compares them with composer.json.
 * Two kinds of finding are noise in a WordPress repository:
 *  - WordPress classes (WP_Post, WP_Error, WP_CLI…): WordPress is not a Composer
 *    dependency, so they never autoload;
 *  - classes of the tests (fixtures declared in the test files, helpers the suite
 *    defines at run time) and the libraries the test runner brings.
 * A repository that needs more than this writes its own composer-dependency-analyser.php,
 * which then replaces this one.
 */
$root = getcwd() ?: '.';

return (new Configuration)
    ->disableReportingUnmatchedIgnores()
    ->ignoreUnknownClassesRegex('~^(WP_|Walker_|Requests_|wpdb$)~')
    ->ignoreErrorsOnPath($root.'/tests', [ErrorType::UNKNOWN_CLASS, ErrorType::UNKNOWN_FUNCTION, ErrorType::SHADOW_DEPENDENCY]);
