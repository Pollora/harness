<?php

declare(strict_types=1);

namespace Pollora\Harness\Tests\Fixtures\Quitting\Attributes;

use Attribute;

exit(0);

#[Attribute(Attribute::TARGET_CLASS)]
final class QuittingAttribute
{
    public function __construct(public string $name) {}
}
