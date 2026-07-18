<?php

declare(strict_types=1);

/**
 * Issue #20493 — curl_escape/curl_unescape require CurlHandle (php-src-strict).
 */
$ch = curl_init();
try {
    echo 'ok ', var_export(curl_escape($ch, 'a b'), true), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    echo 'ok ', var_export(curl_unescape($ch, 'a%20b'), true), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    curl_escape('a b');
    echo "1arg_uncaught\n";
} catch (Throwable $e) {
    echo '1arg ', get_class($e), "\n";
}
try {
    curl_escape('a b', 'x');
    echo "badhandle_uncaught\n";
} catch (Throwable $e) {
    echo 'badhandle ', get_class($e), ':', $e->getMessage(), "\n";
}
