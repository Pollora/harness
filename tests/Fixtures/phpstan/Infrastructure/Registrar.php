<?php

declare(strict_types=1);

namespace Acme\Shop\Infrastructure;

final class Registrar
{
    public function register(): void
    {
        add_action('init', static fn (): null => null); // allowed outside the domain
    }
}
