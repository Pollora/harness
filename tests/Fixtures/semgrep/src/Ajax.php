<?php

declare(strict_types=1);

namespace App;

use Pollora\Hook\Facades\Action;

final class Ajax
{
    public function register(object $action, string $name): void
    {
        // ruleid: sec-02
        add_action('wp_ajax_save_order', [$this, 'save']);

        // ruleid: sec-02
        add_action('wp_ajax_nopriv_save_order', [$this, 'save']);

        // ruleid: sec-02
        add_action("wp_ajax_{$name}", [$this, 'save']);

        // ruleid: sec-02
        add_action('wp_ajax_'.$name, [$this, 'save']);

        // ruleid: sec-02
        Action::add('wp_ajax_save_order', [$this, 'save']);

        // ruleid: sec-02
        $action->add('wp_ajax_nopriv_save_order', [$this, 'save']);

        // ok: sec-02
        add_action('wp_ajax_legacy', [$this, 'save']); // nosemgrep -- verifies its own HMAC

        // ok: sec-02
        add_action('init', [$this, 'save']);

        // ok: sec-02
        add_action('admin_post_save_order', [$this, 'save']);
    }

    public function save(): void {}
}
