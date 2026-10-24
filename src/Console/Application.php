<?php

declare(strict_types=1);

namespace Pollora\Harness\Console;

use Pollora\Harness\Config\InvalidConfig;
use Pollora\Harness\Console\Commands\AgentsCommand;
use Pollora\Harness\Console\Commands\CheckCommand;
use Pollora\Harness\Console\Commands\DepsCommand;
use Pollora\Harness\Console\Commands\HookCommand;
use Pollora\Harness\Console\Commands\InitCommand;
use Pollora\Harness\Console\Commands\RatchetCommand;
use Pollora\Harness\Console\Commands\RulesCommand;

final class Application
{
    /** @var array<string, Command> */
    private array $commands = [];

    /**
     * @param  list<Command>|null  $commands
     */
    public function __construct(?array $commands = null)
    {
        foreach ($commands ?? $this->defaultCommands() as $command) {
            $this->commands[$command->name()] = $command;
        }
    }

    /**
     * @param  list<string>  $argv  The raw arguments, script name first
     */
    public function run(array $argv, string $cwd): int
    {
        $name = $argv[1] ?? 'help';
        $io = Io::fromArgv($cwd, array_slice($argv, 2));

        if (in_array($name, ['help', '--help', '-h'], true)) {
            return $this->help($io, $io->arguments[0] ?? null);
        }

        $command = $this->commands[$name] ?? null;

        if ($command === null) {
            $io->error("Unknown command \"{$name}\". Run pollora-qa help.");

            return Command::FAILURE;
        }

        try {
            return $command->run($io);
        } catch (InvalidConfig $invalidConfig) {
            $io->error($invalidConfig->getMessage());

            return Command::FAILURE;
        }
    }

    private function help(Io $io, ?string $name): int
    {
        if ($name !== null && isset($this->commands[$name])) {
            $command = $this->commands[$name];
            $io->line("pollora-qa {$name}: {$command->description()}");

            if ($command->usage() !== '') {
                $io->line($command->usage());
            }

            return Command::SUCCESS;
        }

        $io->line('Pollora harness');
        $io->line();
        $io->line('Usage: pollora-qa <command> [options]');
        $io->line();

        foreach ($this->commands as $command) {
            $io->line(sprintf('  %-9s %s', $command->name(), $command->description()));
        }

        $io->line();
        $io->line('pollora-qa help <command> shows its options.');

        return Command::SUCCESS;
    }

    /**
     * @return list<Command>
     */
    private function defaultCommands(): array
    {
        return [
            new InitCommand,
            new CheckCommand,
            new RatchetCommand,
            new DepsCommand,
            new RulesCommand,
            new AgentsCommand,
            new HookCommand,
        ];
    }
}
