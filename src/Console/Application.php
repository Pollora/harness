<?php

declare(strict_types=1);

namespace Pollora\Harness\Console;

use Pollora\Harness\Config\InvalidConfig;
use Pollora\Harness\Console\Commands\AgentsCommand;
use Pollora\Harness\Console\Commands\ArchiveCommand;
use Pollora\Harness\Console\Commands\CheckCommand;
use Pollora\Harness\Console\Commands\DepsCommand;
use Pollora\Harness\Console\Commands\HookCommand;
use Pollora\Harness\Console\Commands\InitCommand;
use Pollora\Harness\Console\Commands\RatchetCommand;
use Pollora\Harness\Console\Commands\RulesCommand;
use Pollora\Harness\Console\Commands\SurfaceCommand;

final class Application
{
    /** @var array<string, Command> */
    private array $commands = [];

    /**
     * The command being run, until it returns.
     *
     * @var array{Io, string}|null
     */
    private static ?array $running = null;

    private static bool $watching = false;

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

        // A file the command loads can end the process with exit() or die(): a WordPress
        // plugin's `defined('ABSPATH') || exit;` does when WordPress is absent. The process
        // would then stop with status 0, before the verdict, and read as a pass.
        self::watchForExit();
        self::$running = [$io, $name];

        try {
            $code = $command->run($io);
        } catch (InvalidConfig $invalidConfig) {
            $io->error($invalidConfig->getMessage());
            $code = Command::FAILURE;
        }

        self::$running = null;

        return $code;
    }

    /**
     * Fails the run when the process ends while a command is still running.
     */
    private static function watchForExit(): void
    {
        if (self::$watching) {
            return;
        }

        self::$watching = true;

        register_shutdown_function(static function (): void {
            if (self::$running === null || error_get_last() !== null) {
                return;
            }

            [$io, $name] = self::$running;
            $io->error("pollora-qa {$name} stopped before it finished: a file it loaded called exit() or die(). "
                .'In a WordPress plugin that is usually the `defined(\'ABSPATH\') || exit;` guard of a file whose constant is not defined.');

            exit(Command::FAILURE);
        });
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
            new SurfaceCommand,
            new ArchiveCommand,
            new RulesCommand,
            new AgentsCommand,
            new HookCommand,
        ];
    }
}
