<?php

declare(strict_types=1);

namespace Pollora\Harness\Support;

/**
 * Small text helpers that need nothing but PCRE, so that the harness does not require `ext-mbstring` from the
 * repositories it is installed in.
 */
final class Text
{
    /**
     * The text cut to at most `$width` characters, the last one being `…` when something was cut.
     *
     * Counts characters, not bytes, and never cuts a multi-byte character in two; a text that is not valid UTF-8 is
     * returned as it is rather than guessed at.
     */
    public static function shorten(string $text, int $width): string
    {
        if ($width < 1 || preg_match('/^.{'.($width + 1).',}/su', $text) !== 1) {
            return $text;
        }

        return preg_match('/^.{'.($width - 1).'}/su', $text, $head) === 1 ? $head[0].'…' : $text;
    }
}
