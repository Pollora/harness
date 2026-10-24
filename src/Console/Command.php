<?php

declare(strict_types=1);

namespace Pollora\Harness\Console;

interface Command
{
    public const SUCCESS = 0;

    public const FAILURE = 1;

    /** Exit code that tells an agent hook to block and show stderr to the agent. */
    public const BLOCK = 2;

    public function name(): string;

    public function description(): string;

    /** One line per option, shown by `pollora-qa help <command>`. */
    public function usage(): string;

    public function run(Io $io): int;
}
