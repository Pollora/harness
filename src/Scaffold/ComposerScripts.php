<?php

declare(strict_types=1);

namespace Pollora\Harness\Scaffold;

/**
 * Adds entries to the "scripts" of a composer.json by editing its text, so
 * that the rest of the file keeps the author's formatting (indentation, inline
 * arrays such as "keywords").
 */
final class ComposerScripts
{
    /**
     * @param  array<string, string>  $entries  Script name => command
     * @return string|null The new content, or null when the text cannot be patched safely
     */
    public static function add(string $json, array $entries): ?string
    {
        $span = self::scriptsSpan($json);
        $indent = preg_match('/^([ \t]+)"/m', $json, $match) === 1 ? $match[1] : '    ';

        if ($span === false) {
            return null;
        }

        if ($span === null) {
            return self::appendBlock($json, $entries, $indent);
        }

        [$open, $close] = $span;
        $body = substr($json, $open + 1, $close - $open - 1);
        $trimmed = rtrim($body);
        $lines = self::lines($entries, $indent.$indent);

        if (trim($trimmed) === '') {
            return substr($json, 0, $open + 1)."\n".implode(",\n", $lines)."\n".$indent.substr($json, $close);
        }

        $trailing = substr($body, strlen($trimmed));

        return substr($json, 0, $open + 1).$trimmed.",\n".implode(",\n", $lines).$trailing.substr($json, $close);
    }

    /**
     * @param  array<string, string>  $entries
     */
    private static function appendBlock(string $json, array $entries, string $indent): ?string
    {
        $end = strrpos($json, '}');

        if ($end === false) {
            return null;
        }

        $head = rtrim(substr($json, 0, $end));

        if ($head === '' || ! str_starts_with(ltrim($json), '{')) {
            return null;
        }

        $separator = str_ends_with($head, '{') ? '' : ',';
        $block = $indent.'"scripts": {'."\n".implode(",\n", self::lines($entries, $indent.$indent))."\n".$indent.'}';

        return $head.$separator."\n".$block."\n".substr($json, $end);
    }

    /**
     * @param  array<string, string>  $entries
     * @return list<string>
     */
    private static function lines(array $entries, string $indent): array
    {
        $lines = [];

        foreach ($entries as $name => $command) {
            $lines[] = $indent.json_encode($name, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).': '.json_encode($command, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return $lines;
    }

    /**
     * The offsets of the braces of the top-level "scripts" object: null when
     * there is none, false when it is not an object or the text is unreadable.
     *
     * @return array{int, int}|null|false
     */
    private static function scriptsSpan(string $json): array|null|false
    {
        $length = strlen($json);
        $depth = 0;
        $found = false;
        $open = null;

        for ($i = 0; $i < $length; $i++) {
            $char = $json[$i];

            if ($char === '"') {
                $start = $i;

                for ($i++; $i < $length && $json[$i] !== '"'; $i++) {
                    if ($json[$i] === '\\') {
                        $i++;
                    }
                }

                if ($depth === 1 && ! $found && substr($json, $start, $i - $start + 1) === '"scripts"') {
                    $rest = ltrim(substr($json, $i + 1));

                    if (str_starts_with($rest, ':')) {
                        $found = true;
                    }
                }

                continue;
            }

            if ($char === '{' || $char === '[') {
                $depth++;

                if ($found && $open === null) {
                    if ($char !== '{') {
                        return false;
                    }

                    $open = $i;
                }

                continue;
            }

            if ($char === '}' || $char === ']') {
                if ($open !== null && $depth === 2) {
                    return [$open, $i];
                }

                $depth--;

                continue;
            }

            if ($found && $open === null && $depth === 1 && trim($char) !== '' && $char !== ':') {
                return false;
            }
        }

        return $found ? false : null;
    }
}
