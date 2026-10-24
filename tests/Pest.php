<?php

declare(strict_types=1);

/**
 * A fresh, empty directory for a test that writes files.
 */
function harnessTempDir(): string
{
    $directory = sys_get_temp_dir().'/pollora-harness-'.bin2hex(random_bytes(6));
    mkdir($directory, 0o755, true);

    return $directory;
}

function fixtureProject(): string
{
    return __DIR__.'/Fixtures/Project';
}
