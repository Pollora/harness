<?php

declare(strict_types=1);

use Pollora\Harness\Support\Text;

it('leaves a text that fits as it is', function (string $text): void {
    expect(Text::shorten($text, 10))->toBe($text);
})->with(['empty' => '', 'short' => 'abc', 'exactly the width' => '0123456789']);

it('cuts a longer text to the width, the last character being the ellipsis', function (): void {
    expect(Text::shorten('0123456789ABC', 10))->toBe('012345678…')
        ->and(Text::shorten(str_repeat('x', 200), 50))->toBe(str_repeat('x', 49).'…');
});

it('counts characters and never cuts a multi-byte character in two', function (): void {
    expect(Text::shorten('éééééééééééé', 5))->toBe('éééé…')
        ->and(Text::shorten('日本語日本語日本語', 4))->toBe('日本語…');
});

it('returns a text that is not valid UTF-8 as it is, and refuses a width below one', function (): void {
    expect(Text::shorten("abc\xff\xfe\xfd\xfc\xfb\xfa", 4))->toBe("abc\xff\xfe\xfd\xfc\xfb\xfa")
        ->and(Text::shorten('abcdef', 0))->toBe('abcdef');
});
