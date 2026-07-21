<?php

declare(strict_types=1);

/**
 * Issue #21834 — curl_multi_poll() is not a PHP function (re-#21826, php-src ext/curl).
 *
 * Zend exposes curl_multi_select(); libcurl curl_multi_poll(3) has no PHP wrapper.
 */
if (function_exists('curl_multi_poll')) {
    fwrite(STDERR, "FAIL: curl_multi_poll must not be registered (php-src-strict)\n");
    exit(1);
}
if (!function_exists('curl_multi_select')) {
    fwrite(STDERR, "SKIP: ext/curl multi not loaded\n");
    exit(0);
}
echo "ok\n";
