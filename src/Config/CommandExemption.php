<?php

declare(strict_types=1);

namespace Pollora\Harness\Config;

/**
 * A console command, or a family of them, that keeps a name which does not
 * start with `pollora:` (CONV-06), with the reason it does.
 *
 * It is written in `.harness.json` as `{"command": "new", "reason": "…"}` for
 * one name, or `{"prefix": "nectar:", "reason": "…"}` for every name that
 * starts so. The reason is required: nothing is exempted without a trace.
 */
final readonly class CommandExemption
{
    public function __construct(
        public string $pattern,
        public bool $isPrefix,
        public string $reason,
    ) {}

    /**
     * @param  array<mixed>  $data
     *
     * @throws InvalidConfig
     */
    public static function fromArray(array $data, string $path): self
    {
        $command = $data['command'] ?? null;
        $prefix = $data['prefix'] ?? null;
        $reason = $data['reason'] ?? null;

        if (! is_string($reason) || trim($reason) === '') {
            throw InvalidConfig::exemptionWithoutReason($path, $command ?? $prefix);
        }

        if (is_string($command) && trim($command) !== '' && $prefix === null) {
            return new self(trim($command), false, trim($reason));
        }

        if (is_string($prefix) && trim($prefix) !== '' && $command === null) {
            return new self(trim($prefix), true, trim($reason));
        }

        throw InvalidConfig::exemptionWithoutTarget($path);
    }

    public function matches(string $commandName): bool
    {
        return $this->isPrefix ? str_starts_with($commandName, $this->pattern) : $commandName === $this->pattern;
    }

    /**
     * @return array{command?: string, prefix?: string, reason: string}
     */
    public function toArray(): array
    {
        return [($this->isPrefix ? 'prefix' : 'command') => $this->pattern, 'reason' => $this->reason];
    }
}
