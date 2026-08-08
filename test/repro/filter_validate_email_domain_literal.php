<?php
declare(strict_types=1);

// #29045 — FILTER_VALIDATE_EMAIL domain literals [IPv4] / [IPv6:…]
foreach ([
    'user@[127.0.0.1]',
    'user@[IPv6:2001:db8::1]',
    'user@[::1]',
    'ok@example.com',
] as $e) {
    echo $e, '=>', var_export(filter_var($e, FILTER_VALIDATE_EMAIL), true), "\n";
}
