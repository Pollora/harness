<?php

declare(strict_types=1);

namespace Pollora\Harness\Config;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;

/**
 * The `npm_audit` key of `.harness.json`: the advisories the npm audit gate
 * tolerates, each with a reason and an expiry.
 *
 * Without an entry the gate blocks on every high or critical advisory, as
 * `npm audit --audit-level=high` does. It exists for the advisory that has no
 * fixed version yet: the gate stays on for every other one.
 */
final readonly class NpmAuditSettings
{
    /** The furthest an exception may expire, in days from the day the file is read. */
    public const int MAX_DAYS = 90;

    /**
     * @param  list<NpmAuditException>  $allow
     */
    public function __construct(public array $allow = []) {}

    /**
     * @throws InvalidConfig
     */
    public static function fromArray(mixed $value, string $path, ?DateTimeImmutable $today = null): self
    {
        if ($value === null) {
            return new self;
        }

        $list = is_array($value) ? ($value['allow'] ?? []) : null;

        if (! is_array($list) || ! array_is_list($list)) {
            throw InvalidConfig::npmAuditMalformed($path);
        }

        $today ??= self::today();
        $allow = [];

        foreach ($list as $entry) {
            $exception = NpmAuditException::fromArray(is_array($entry) ? $entry : [], $path, $today);

            if (array_key_exists($exception->advisory, $allow)) {
                throw InvalidConfig::npmAuditDuplicate($path, $exception->advisory);
            }

            $allow[$exception->advisory] = $exception;
        }

        return new self(array_values($allow));
    }

    /**
     * Reads the key from a file that need not be a whole `.harness.json`: a template cannot carry the
     * harness, so it only declares its exceptions. No file, or no key, means no exception.
     *
     * @throws InvalidConfig
     */
    public static function fromFile(string $file, ?DateTimeImmutable $today = null): self
    {
        if (! is_file($file)) {
            return new self;
        }

        try {
            $data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $jsonException) {
            throw InvalidConfig::unreadable($file, $jsonException->getMessage());
        }

        if (! is_array($data)) {
            throw InvalidConfig::unreadable($file, 'the root must be an object');
        }

        return self::fromArray($data['npm_audit'] ?? null, $file, $today);
    }

    public static function today(): DateTimeImmutable
    {
        return new DateTimeImmutable('today', new DateTimeZone('UTC'));
    }

    public function find(string $advisory): ?NpmAuditException
    {
        foreach ($this->allow as $exception) {
            if (strcasecmp($exception->advisory, $advisory) === 0) {
                return $exception;
            }
        }

        return null;
    }

    /**
     * @return array{allow?: list<array{advisory: string, reason: string, expires: string}>}
     */
    public function toArray(): array
    {
        return $this->allow === [] ? [] : ['allow' => array_map(static fn (NpmAuditException $exception): array => $exception->toArray(), $this->allow)];
    }
}
