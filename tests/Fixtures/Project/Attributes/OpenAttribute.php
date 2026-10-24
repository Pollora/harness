<?php

declare(strict_types=1);

namespace Pollora\Harness\Tests\Fixtures\Project\Attributes;

use Attribute;

#[Attribute]
class OpenAttribute
{
    public function __construct(public string $name, public readonly int $priority = 10) {}
}
