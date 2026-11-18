<?php

declare(strict_types=1);

namespace App;

final class MenuPages
{
    public function register(): void
    {
        // ruleid: sec-07
        add_menu_page('Title', 'Menu', 'read', 'slug', [$this, 'render']);

        // ruleid: sec-07
        add_menu_page('Title', 'Menu', '', 'slug', [$this, 'render']);

        // ruleid: sec-07
        add_submenu_page('tools.php', 'Title', 'Menu', 'read', 'slug', [$this, 'render']);

        // ruleid: sec-07
        add_submenu_page('tools.php', 'Title', 'Menu', '', 'slug', [$this, 'render']);

        // ruleid: sec-07
        add_options_page('Title', 'Menu', 'read', 'slug', [$this, 'render']);

        // ruleid: sec-07
        add_management_page('Title', 'Menu', '', 'slug', [$this, 'render']);

        // ok: sec-07
        add_menu_page('Title', 'Menu', 'manage_options', 'slug', [$this, 'render']);

        // ok: sec-07
        add_submenu_page('tools.php', 'Title', 'Menu', 'edit_posts', 'slug', [$this, 'render']);

        // ok: sec-07
        add_options_page('Title', 'Menu', self::CAPABILITY, 'slug', [$this, 'render']);

        // ok: sec-07
        add_dashboard_page('Read', 'Menu', 'manage_options', 'slug', [$this, 'render']);
    }

    public function render(): void {}
}
