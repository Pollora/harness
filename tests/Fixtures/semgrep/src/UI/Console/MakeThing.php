<?php

declare(strict_types=1);

namespace App\UI\Console;

final class MakeThing
{
    public function handle(string $name): bool
    {
        // ok: sec-06
        return preg_match('/^[a-z]+$/', $name) === 1;
    }
}
