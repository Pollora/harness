<?php

declare(strict_types=1);

namespace Pollora\Harness\Console;

/**
 * Parsed arguments and plain console output.
 *
 * The harness keeps its own runtime dependency-free so that it runs before
 * `composer install` has finished and inside agent hooks, where a heavy
 * console component would only slow every edit down.
 */
final class Io
{
    /**
     * @param  list<string>  $arguments  Positional arguments after the command name
     * @param  array<array-key, string|true>  $options  --name=value and --flag options
     * @param  resource  $stdout
     * @param  resource  $stderr
     */
    public function __construct(
        public readonly string $cwd,
        public readonly array $arguments,
        public readonly array $options,
        private $stdout = STDOUT,
        private $stderr = STDERR,
        private readonly bool $decorated = false,
    ) {}

    /**
     * @param  list<string>  $argv  Arguments after the command name
     * @param  resource  $stdout
     * @param  resource  $stderr
     */
    public static function fromArgv(string $cwd, array $argv, $stdout = STDOUT, $stderr = STDERR): self
    {
        $arguments = [];
        $options = [];

        foreach ($argv as $argument) {
            if (str_starts_with($argument, '--')) {
                [$name, $value] = array_pad(explode('=', substr($argument, 2), 2), 2, true);
                $options[$name] = $value;
            } else {
                $arguments[] = $argument;
            }
        }

        $decorated = ! isset($options['no-ansi']) && getenv('NO_COLOR') === false && stream_isatty($stdout);

        return new self($cwd, $arguments, $options, $stdout, $stderr, $decorated);
    }

    public function option(string $name): ?string
    {
        $value = $this->options[$name] ?? null;

        return is_string($value) ? $value : null;
    }

    public function flag(string $name): bool
    {
        return array_key_exists($name, $this->options);
    }

    /**
     * Every value of a repeatable option, given as --name=a --name=b or --name=a,b.
     *
     * @return list<string>
     */
    public function list(string $name): array
    {
        $value = $this->option($name);

        return $value === null ? [] : array_values(array_filter(
            array_map(trim(...), explode(',', $value)),
            static fn (string $item): bool => $item !== '',
        ));
    }

    public function line(string $text = ''): void
    {
        fwrite($this->stdout, $text."\n");
    }

    public function error(string $text): void
    {
        fwrite($this->stderr, $text."\n");
    }

    public function title(string $text): void
    {
        $this->line();
        $this->line($this->style($text, '1'));
    }

    public function success(string $text): string
    {
        return $this->style($text, '32');
    }

    public function failure(string $text): string
    {
        return $this->style($text, '31');
    }

    public function warning(string $text): string
    {
        return $this->style($text, '33');
    }

    public function muted(string $text): string
    {
        return $this->style($text, '2');
    }

    private function style(string $text, string $code): string
    {
        return $this->decorated ? "\033[{$code}m{$text}\033[0m" : $text;
    }
}
