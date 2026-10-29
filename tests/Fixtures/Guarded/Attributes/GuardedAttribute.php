<?php

declare(strict_types=1);

namespace Pollora\Harness\Tests\Fixtures\Guarded\Attributes;

use Attribute;

defined('ABSPATH') || exit;

#[Attribute(Attribute::TARGET_CLASS)]
final class GuardedAttribute
{
    public function __construct(public string $name) {}
}
