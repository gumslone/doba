<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Trusted proxies (§14)
    |--------------------------------------------------------------------------
    |
    | Whose X-Forwarded-For is believed. It used to be everyone's: with
    | "*" a visitor who connects directly — which is every visitor on
    | ordinary Apache hosting — picks their own IP address by sending the
    | header, and every per-IP limit in this application (admin login, the
    | second factor, booking, voucher codes) stops applying to them.
    |
    | The default trusts only a proxy on this same machine, which nobody
    | outside can pretend to be. Behind something else — Cloudflare, a load
    | balancer, a reverse proxy on another host — list its addresses or
    | ranges here, comma-separated, or the whole site shares one address
    | and one set of limits. "*" restores the old behaviour and is only
    | safe when the application cannot be reached except through the proxy.
    |
    */

    'proxies' => env('TRUSTED_PROXIES', '127.0.0.1,::1'),

];
