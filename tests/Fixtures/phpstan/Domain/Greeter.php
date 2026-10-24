<?php

declare(strict_types=1);

namespace Acme\Shop\Domain;

final class Greeter
{
    public function greet(string $name): string
    {
        add_action('init', static fn (): null => null); // error: ARCH-02
        $title = \get_option('blogname'); // error: ARCH-02
        $slug = sanitize($name); // namespaced function: not WordPress

        return strtoupper($title.$slug); // PHP function: allowed
    }
}

function sanitize(string $value): string
{
    return trim($value);
}
