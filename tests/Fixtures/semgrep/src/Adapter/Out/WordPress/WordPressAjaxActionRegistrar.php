<?php

declare(strict_types=1);

namespace App\Adapter\Out\WordPress;

final class WordPressAjaxActionRegistrar
{
    public function register(string $name, callable $callback): void
    {
        // ok: sec-02
        add_action('wp_ajax_'.$name, $callback);

        // ok: sec-02
        add_action('wp_ajax_nopriv_'.$name, $callback);
    }
}
