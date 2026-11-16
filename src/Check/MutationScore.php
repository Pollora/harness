<?php

declare(strict_types=1);

namespace Pollora\Harness\Check;

/**
 * The mutation score the "Mutation testing" step measured, handed to the ratchet step that runs after it.
 */
final class MutationScore
{
    public ?float $value = null;
}
