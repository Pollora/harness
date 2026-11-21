<?php

declare(strict_types=1);

use Pollora\Harness\Rules\Catalogue;

if (! function_exists('withCatalogueStatus')) {
    /**
     * Runs a callback while the catalogue lists a rule with another status, then restores it.
     *
     * The catalogue is read once per process from resources/rules.php; this swaps the cached array so that a
     * test can ask what happens "once the rule is enforced" without editing the catalogue.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    function withCatalogueStatus(string $rule, string $status, callable $callback): mixed
    {
        $property = new ReflectionProperty(Catalogue::class, 'rules');
        $original = Catalogue::all();
        $changed = $original;
        $changed[$rule]['status'] = $status;
        $property->setValue(null, $changed);

        try {
            return $callback();
        } finally {
            $property->setValue(null, $original);
        }
    }
}
