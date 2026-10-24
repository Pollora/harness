<?php

declare(strict_types=1);

namespace App;

use Pollora\Attributes\WpRestRoute;
use Pollora\WpRest\Permissions\IsAdmin;

// ruleid: sec-01
#[WpRestRoute('app/v1', '/orders')]
final class Orders {}

// ok: sec-01
#[WpRestRoute('app/v1', '/settings', permissionCallback: IsAdmin::class)]
final class Settings {}

// ok: sec-01
#[WpRestRoute('app/v1', '/status')] // nosemgrep -- health check, public on purpose
final class Status {}

final class Queries
{
    public function find(\wpdb $wpdb, string $slug): mixed
    {
        // ruleid: sec-03
        $wpdb->get_results("SELECT * FROM {$wpdb->posts} WHERE post_name = '$slug'");

        // ok: sec-03
        $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->posts} WHERE post_name = %s", $slug));

        $sql = $wpdb->prepare('SELECT ID FROM wp_posts WHERE post_name = %s', $slug);
        // ok: sec-03
        $wpdb->get_var($sql);

        // ok: sec-03
        $wpdb->query('SELECT 1');

        // ruleid: sec-05
        $data = unserialize($slug);

        // ruleid: conv-10
        throw new \RuntimeException('Nope');
    }
}
