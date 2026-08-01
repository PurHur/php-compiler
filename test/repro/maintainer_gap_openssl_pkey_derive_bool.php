<?php

declare(strict_types=1);

/**
 * Issue #26689 — openssl_pkey_derive() soft-fails non-key scalars (php-src "zz|l" + php_openssl_pkey_from_zval).
 */
foreach ([false, true, 0, 1, 1.5, [], null] as $a) {
    echo gettype($a), ':';
    try {
        var_export(openssl_pkey_derive($a, false));
        echo PHP_EOL;
    } catch (Throwable $e) {
        echo get_class($e), PHP_EOL;
    }
}
