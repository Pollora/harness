<?php

declare(strict_types=1);

namespace App;

final class Signatures
{
    private string $secret = 'x';

    public function verify(string $signature, string $expected, ?string $token, array $headers): bool
    {
        // ruleid: sec-06
        if ($signature === $expected) {
            return true;
        }

        // ruleid: sec-06
        if ($expected !== $signature) {
            return false;
        }

        // ruleid: sec-06
        if ($_GET['token'] == $this->secret) {
            return true;
        }

        // ruleid: sec-06
        if (hash_hmac('sha256', 'body', 'k') === $headers['X-Signature']) {
            return true;
        }

        // ruleid: sec-06
        if ($this->secret !== $headers['x-secret']) {
            return false;
        }

        // ok: sec-06
        if (hash_equals($expected, $signature)) {
            return true;
        }

        // ok: sec-06
        if ($token === null || $token === '') {
            return false;
        }

        // ok: sec-06
        if ($signature !== 'none') {
            return false;
        }

        // ok: sec-06
        if ($signature === 'none' || $token === null || $this->secret === self::NONE) {
            return false;
        }

        // ok: sec-06
        if (strlen($signature) !== self::LENGTH || $headers['x-token'] === []) {
            return false;
        }

        // ok: sec-06
        foreach ($headers as $key => $value) {
            if ($key === 'content-type') {
                return false;
            }
        }

        // ruleid: sec-06
        if ($headers['x-api-key'] !== 'known' && $token == $expected) {
            return false;
        }

        // ok: sec-06
        return $expected === $this->normalise($expected);
    }

    /**
     * @param  array<string, array<string, string>>  $entries  Rows indexed by their signature
     */
    public function find(array $entries, string $key): ?array
    {
        foreach ($entries as $signature => $entry) {
            // ok: sec-06
            if ($key === $signature) {
                return $entry;
            }
        }

        foreach ($entries as $name => $entry) {
            // ruleid: sec-06
            if ($key === $entry['signature']) {
                return $entry;
            }
        }

        return null;
    }

    private function normalise(string $value): string
    {
        return $value;
    }
}
