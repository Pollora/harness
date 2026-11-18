<?php

declare(strict_types=1);

namespace App;

final class Thumbnails
{
    public function resolve(string $path, string $name): void
    {
        // ruleid: sec-06
        if (preg_match('/^[a-z0-9\/_-]+\.jpg$/', $path, $parts) === 1) {
            return;
        }

        // ruleid: sec-06
        if (preg_match('#^(\d+)x(\d+)$#i', $_GET['size'] ?? '', $parts)) {
            return;
        }

        // ruleid: sec-06
        if (preg_match("/^[a-z]+$/", $name)) {
            return;
        }

        // ok: sec-06
        if (preg_match('/^[a-z0-9\/_-]+\.jpg$/D', $path, $parts) === 1) {
            return;
        }

        // ok: sec-06
        if (preg_match('/^[a-z0-9\/_-]+\.jpg\z/', $path, $parts) === 1) {
            return;
        }

        // ok: sec-06
        if (preg_match('/^[a-z0-9\/_-]+\.jpg(?:\z)/', $path, $parts) === 1) {
            return;
        }

        // ok: sec-06
        if (preg_match('/^Version: (.*)$/mi', $path, $parts)) {
            return;
        }

        // ok: sec-06
        if (preg_match('/^[a-z0-9]+\.jpg/', $path)) {
            return;
        }

        // ok: sec-06
        if (preg_match('/^[a-z]+$/', 'a-constant-subject')) {
            return;
        }

        // ok: sec-06
        if (preg_match('/^[a-z]+$/', $this->label())) {
            return;
        }
    }

    private function label(): string
    {
        return 'a label';
    }
}
