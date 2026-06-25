<?php

declare(strict_types=1);

// Issue #11654 — extension_loaded('curl') must agree with function_exists('curl_init').
echo 'loaded=', (int) extension_loaded('curl'), "\n";
echo 'funcs=', (int) function_exists('curl_init'), "\n";

if (!function_exists('curl_init')) {
    echo "curl_init=absent\n";
} else {
    try {
        curl_init();
        echo "curl_init=ok\n";
    } catch (Throwable $e) {
        echo 'curl_init=', get_class($e), "\n";
    }
}
