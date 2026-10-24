<?php

declare(strict_types=1);

namespace Pollora\Harness\Tests\Fixtures\Project\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class GoodAttribute
{
    public function __construct(public string $name) {}
}
