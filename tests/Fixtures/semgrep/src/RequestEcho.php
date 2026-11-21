<?php

declare(strict_types=1);

namespace App;

final class RequestEcho
{
    public function unsafe(): void
    {
        $search = $_GET['s'];

        // ruleid: sec-13
        echo $search;

        // ruleid: sec-13
        echo '<input value="'.$_POST['name'].'">';

        // ruleid: sec-13
        echo sanitize_text_field(wp_unslash($_GET['message']));

        // ruleid: sec-13
        printf('<p>%s</p>', $_GET['title']);

        // ruleid: sec-13
        echo '<form action="'.$_SERVER['REQUEST_URI'].'">';

        // ruleid: sec-13
        echo '<form action="'.$_SERVER['PHP_SELF'].'">';

        // ruleid: sec-13
        wp_die($_GET['error']);

        // ruleid: sec-13
        echo $_COOKIE['theme'];
    }

    public function safe(): void
    {
        // ok: sec-13
        echo esc_html($_GET['s']);

        // ok: sec-13
        echo esc_html(wp_unslash($_GET['s']));

        // ok: sec-13
        echo '<input value="'.esc_attr($_POST['name']).'">';

        // ok: sec-13
        echo '<form action="'.esc_url($_SERVER['REQUEST_URI']).'">';

        // ok: sec-13
        echo absint($_GET['page']);

        // ok: sec-13
        echo (int) $_GET['page'];

        // ok: sec-13
        echo wp_kses_post($_POST['html']);

        // ok: sec-13
        echo wp_json_encode($_GET['filters']);

        // ok: sec-13
        echo $_GET['tab'] === 'general' ? 'active' : '';

        // ok: sec-13
        echo isset($_GET['saved']) ? 'Saved' : '';

        // ok: sec-13
        echo htmlspecialchars($_GET['s'], ENT_QUOTES);

        // ok: sec-13
        echo $_GET['s']; // nosemgrep -- the value is a number checked by the caller

        // ok: sec-13
        echo esc_html__('Saved', 'domain');
    }
}
