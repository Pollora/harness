<?php

declare(strict_types=1);

namespace Pollora\Harness\Support;

/**
 * Tells a network failure from a real answer, and how long to wait between two attempts.
 *
 * The dependency audits (SEC-09) call Packagist and the npm registry, which now and then time
 * out for a minute. A gate that failed on that would blame the author for an incident that has
 * nothing to do with the code; a gate that passed on it would hide a vulnerability. So only a
 * failure of the transport is attempted again, a few times and for a bounded time, and a gate
 * that is still unreachable afterwards fails: the retry never turns a failure into a pass.
 */
final class TransportErrors
{
    /** How many times in all an audit asks the advisory service before it gives up. */
    public const int ATTEMPTS = 3;

    /** Seconds to wait after the first and the second failed attempt (at most 20 s in all). */
    public const array DELAYS = [5, 15];

    /**
     * Texts that Composer, npm, curl and PHP's stream layer print when the transport fails: timeout
     * (curl 28), DNS (6, EAI_AGAIN), refused or reset connection (7, 56), TLS handshake (35),
     * empty reply (52), and an HTTP 5xx or 429 answer from the server or its proxy.
     */
    private const string PATTERN = '~'
        .'curl error (?:6|7|18|28|35|52|55|56) while downloading'
        .'|connection (?:timed out|refused|reset)'
        .'|(?:operation|resolving|connection|connect) timed out'
        .'|could not resolve host'
        .'|getaddrinfo'
        .'|php_network_getaddresses'
        .'|\b(?:EAI_AGAIN|ENOTFOUND|ETIMEDOUT|ECONNRESET|ECONNREFUSED|ESOCKETTIMEDOUT|EHOSTUNREACH|ENETUNREACH)\b'
        .'|socket hang up'
        .'|HTTP/[\d.]+ (?:5\d\d|429)\b'
        .'|\bE(?:5\d\d|429)\b'
        .'|failed to open stream: (?:connection|network)'
        .'~i';

    /**
     * Whether a tool's output is the sign of a transport failure.
     */
    public static function matches(string $output): bool
    {
        return preg_match(self::PATTERN, $output) === 1;
    }

    /**
     * Waits before the next attempt, when there is one.
     *
     * @param  int  $failed  The number of attempts that have failed so far (1 for the first)
     * @param  callable(int): void|null  $sleep  Replaces `sleep()` in tests
     */
    public static function wait(int $failed, ?callable $sleep = null): void
    {
        $seconds = self::DELAYS[$failed - 1] ?? self::DELAYS[array_key_last(self::DELAYS)];

        ($sleep ?? sleep(...))($seconds);
    }
}
