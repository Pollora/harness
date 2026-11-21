<?php

declare(strict_types=1);

namespace App;

use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Request;

final class RequestRedirect
{
    public function wordpress(): void
    {
        $target = $_GET['redirect_to'];

        // ruleid: sec-10
        wp_redirect($target);

        // ruleid: sec-10
        wp_redirect($_POST['next'], 301);

        // ruleid: sec-10
        wp_redirect(wp_unslash($_REQUEST['return']));

        // ruleid: sec-10
        wp_redirect('https://'.$_SERVER['HTTP_HOST'].'/login');

        // ruleid: sec-10
        header('Location: '.$_GET['u']);

        // ruleid: sec-10
        header("Location: {$_COOKIE['back']}");

        // ruleid: sec-10
        wp_redirect(filter_input(INPUT_GET, 'to'));
    }

    public function laravel(HttpRequest $request): void
    {
        // ruleid: sec-10
        redirect($request->input('next'));

        // ruleid: sec-10
        redirect()->away($request->query('url'));

        // ruleid: sec-10
        Redirect::to($request->get('to'));

        // ruleid: sec-10
        redirect(request('back'));

        // ruleid: sec-10
        wp_redirect(Request::query('to'));
    }

    public function safe(HttpRequest $request): void
    {
        // ok: sec-10
        wp_redirect(wp_validate_redirect($_GET['redirect_to'], home_url('/')));

        // ok: sec-10
        wp_redirect(home_url('/'.ltrim($_GET['path'], '/')));

        // ok: sec-10
        wp_redirect(admin_url('admin.php?page='.$_GET['page']));

        // ok: sec-10
        wp_redirect(get_permalink((int) $_GET['post']));

        // ok: sec-10
        wp_redirect(get_permalink(absint($_GET['post'])));

        // ok: sec-10
        wp_redirect(home_url());

        // ok: sec-10
        wp_redirect(wp_get_referer());

        // ok: sec-10
        wp_safe_redirect(wp_validate_redirect($_GET['u']));

        // ok: sec-10
        header('Location: '.home_url('/done'));

        // ok: sec-10
        redirect(route('home', ['id' => $request->input('id')]));

        // ok: sec-10
        redirect()->away($request->input('next')); // nosemgrep -- the value is an id from an allow-list

        $isAdmin = $_GET['admin'] === '1';
        // ok: sec-10
        wp_redirect($isAdmin ? admin_url() : home_url());
    }
}
