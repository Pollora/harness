<?php

declare(strict_types=1);

use Pollora\Harness\Profile;
use Pollora\Harness\Rector\Preset;

return Preset::configure(__DIR__, Profile::Core, ['src', 'bin', 'resources'])
    ->withSkip([
        // Fixtures break the rules on purpose: they are what the tests check is reported.
        __DIR__.'/tests/Fixtures',
    ]);
