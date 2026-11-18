<?php

declare(strict_types=1);

namespace App;

use Pollora\Hook\Facades\Action;

final class AdminActions
{
    public function register(object $action, string $name): void
    {
        // ruleid: sec-07
        add_action('admin_post_save_order', function (): void {
            update_option('order', $_POST['order']);
        });

        // ruleid: sec-07 ruleid: sec-02
        add_action('wp_ajax_save_order', function () use ($name): void {
            check_ajax_referer('order');
            update_option($name, 1);
        });

        // ruleid: sec-07 ruleid: sec-02
        add_action('wp_ajax_save_order', fn () => update_option('order', 1));

        // ruleid: sec-07
        add_action("admin_post_{$name}", static function (): void {
            update_option('order', 1);
        });

        // ruleid: sec-07
        Action::add('admin_post_save_order', function (): void {
            update_option('order', 1);
        });

        // ruleid: sec-07 ruleid: sec-02
        $action->add('wp_ajax_nopriv_save_order', function (): void {
            update_option('order', 1);
        });

        // ok: sec-07
        add_action('admin_post_save_order', function (): void {
            if (! current_user_can('manage_options')) {
                wp_die('Forbidden', '', 403);
            }
            update_option('order', 1);
        });

        // ok: sec-07 ruleid: sec-02
        add_action('wp_ajax_save_order', function () use ($name): void {
            abort_unless(user_can(get_current_user_id(), 'edit_posts'), 403);
            update_option($name, 1);
        });

        // ok: sec-07
        add_action('admin_post_save_order', [$this, 'save']);

        // ok: sec-07
        add_action('init', function (): void {
            update_option('order', 1);
        });

        // ok: sec-07
        add_action('wp_ajax_legacy', function (): void { // nosemgrep -- the capability is checked by LegacyGuard
            update_option('order', 1);
        });
    }

    public function save(): void {}
}
