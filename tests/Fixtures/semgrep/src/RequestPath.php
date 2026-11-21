<?php

declare(strict_types=1);

namespace App;

use Illuminate\Http\Request;

final class RequestPath
{
    public function unsafe(Request $request): void
    {
        $file = $_GET['file'];

        // ruleid: sec-12
        $contents = file_get_contents($file);

        // ruleid: sec-12
        include $_GET['template'];

        // ruleid: sec-12
        require_once __DIR__.'/views/'.$_POST['view'].'.php';

        // ruleid: sec-12
        readfile(WP_CONTENT_DIR.'/uploads/'.wp_unslash($_REQUEST['f']));

        // ruleid: sec-12
        unlink($request->input('path'));

        // ruleid: sec-12
        file_put_contents($request->query('target'), 'x');

        // ruleid: sec-12
        $handle = fopen('php://'.$_GET['stream'], 'r');

        // ruleid: sec-12
        wp_delete_file(sanitize_text_field($_POST['file']));

        // ruleid: sec-12
        get_template_part('parts/'.$_GET['part']);
    }

    public function safe(Request $request): void
    {
        // ok: sec-12
        file_get_contents(__DIR__.'/data/'.basename($_GET['file']));

        // ok: sec-12
        file_get_contents(__DIR__.'/data/'.sanitize_file_name($_GET['file']));

        // ok: sec-12
        include __DIR__.'/views/'.sanitize_key($_GET['view']).'.php';

        // ok: sec-12
        readfile(WP_CONTENT_DIR.'/uploads/'.absint($_GET['id']).'.pdf');

        // ok: sec-12
        readfile(WP_CONTENT_DIR.'/uploads/'.(int) $_GET['id'].'.pdf');

        // ok: sec-12
        file_get_contents(self::ROOT.'/composer.json');

        // ok: sec-12
        file_put_contents($this->path, $_POST['body']);

        // ok: sec-12
        include $_GET['template']; // nosemgrep -- the value is a key of the allow-list checked above

        // ok: sec-12
        file_get_contents($request->input('id') === '1' ? 'a.json' : 'b.json');
    }
}
