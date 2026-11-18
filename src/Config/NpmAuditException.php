<?php

declare(strict_types=1);

namespace Pollora\Harness\Config;

use DateTimeImmutable;
use DateTimeZone;

/**
 * One advisory the npm audit gate tolerates for a limited time (SEC-09).
 *
 * It is written in `.harness.json` as
 * `{"advisory": "GHSA-fc5q-39xc-vjpf", "reason": "…", "expires": "2026-12-18"}`.
 * The reason and the expiry are required, and the expiry may not be further than
 * {@see NpmAuditSettings::MAX_DAYS} days from today: an exception is a loan, not a
 * decision, and a date nobody has to revisit would blind the gate for good.
 */
final readonly class NpmAuditException
{
    /** The shape of a GitHub advisory identifier: the groups use the alphabet GitHub issues. */
    private const string ADVISORY = '/^GHSA(-[2-9cfghjmpqrvwx]{4}){3}$/';

    public function __construct(
        public string $advisory,
        public string $reason,
        public DateTimeImmutable $expires,
    ) {}

    /**
     * @param  array<mixed>  $data
     *
     * @throws InvalidConfig
     */
    public static function fromArray(array $data, string $path, DateTimeImmutable $today): self
    {
        $advisory = $data['advisory'] ?? null;
        $reason = $data['reason'] ?? null;
        $expires = $data['expires'] ?? null;

        if (! is_string($advisory) || preg_match(self::ADVISORY, $advisory) !== 1) {
            throw InvalidConfig::npmAuditAdvisory($path, $advisory);
        }

        if (! is_string($reason) || trim($reason) === '') {
            throw InvalidConfig::npmAuditWithoutReason($path, $advisory);
        }

        $date = self::parseDate($expires);

        if (! $date instanceof DateTimeImmutable) {
            throw InvalidConfig::npmAuditExpiry($path, $advisory, $expires);
        }

        $limit = $today->setTime(0, 0)->modify('+'.NpmAuditSettings::MAX_DAYS.' days');

        if ($date > $limit) {
            throw InvalidConfig::npmAuditTooFar($path, $advisory, $limit->format('Y-m-d'));
        }

        return new self($advisory, trim($reason), $date);
    }

    /**
     * Whether the exception no longer applies: it holds through the whole day it names.
     */
    public function isExpiredOn(DateTimeImmutable $today): bool
    {
        return $today->setTime(0, 0) > $this->expires;
    }

    /**
     * @return array{advisory: string, reason: string, expires: string}
     */
    public function toArray(): array
    {
        return ['advisory' => $this->advisory, 'reason' => $this->reason, 'expires' => $this->expires->format('Y-m-d')];
    }

    /**
     * A real calendar date written YYYY-MM-DD, as UTC midnight; null for anything else ("2026-02-30" included).
     */
    private static function parseDate(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));

        return $date !== false && $date->format('Y-m-d') === $value ? $date : null;
    }
}
