<?php

declare(strict_types=1);

namespace App;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

final class RequestHttpCall
{
    public function unsafe(Request $request): void
    {
        $url = $_POST['feed_url'];

        // ruleid: sec-11
        wp_remote_get($url);

        // ruleid: sec-11
        wp_remote_post($_GET['endpoint'], ['body' => []]);

        // ruleid: sec-11
        wp_remote_request(wp_unslash($_REQUEST['u']), ['method' => 'HEAD']);

        // ruleid: sec-11
        wp_remote_head('https://'.$_SERVER['HTTP_HOST'].'/ping');

        // ruleid: sec-11
        $handle = curl_init($_GET['url']);

        // ruleid: sec-11
        curl_setopt($handle, CURLOPT_URL, $_GET['url']);

        // ruleid: sec-11
        Http::get($request->input('url'));

        // ruleid: sec-11
        Http::post($request->query('callback'), ['ok' => true]);

        // ruleid: sec-11
        Http::withHeaders(['Accept' => 'application/json'])->get($request->get('url'));
    }

    public function safe(Request $request): void
    {
        // ok: sec-11
        wp_remote_get('https://api.example.com/items/'.absint($_GET['id']));

        // ok: sec-11
        wp_remote_get(rest_url('wp/v2/posts/'.(int) $_GET['id']));

        // ok: sec-11
        wp_remote_get(wp_http_validate_url($_GET['url']));

        // ok: sec-11
        wp_safe_remote_get($_GET['url']);

        // ok: sec-11
        Http::get('https://api.github.com/repos/'.$this->repository);

        // ok: sec-11
        Http::get($request->input('url')); // nosemgrep -- the host is checked against the allow-list above

        // ok: sec-11
        wp_remote_get(self::ENDPOINT, ['body' => $_POST['payload']]);

        // ok: sec-11
        Http::acceptJson()->get('https://api.example.com', $request->query());
    }
}
