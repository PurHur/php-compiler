<?php

declare(strict_types=1);

/**
 * Issue #11095 — session_cache_limiter() missing (ext/session/session.c).
 */
$prev = session_cache_limiter('nocache');
$current = session_cache_limiter();
session_set_cookie_params(['lifetime' => 3600, 'path' => '/']);
$ok = function_exists('session_get_cookie_params')
    && function_exists('session_set_cookie_params')
    && function_exists('session_cache_limiter');
echo $ok ? "interface_dispatch_ok\n" : "interface_dispatch_fail\n";
echo $prev, "\n";
echo $current, "\n";
