<?php

/**
 * Repro #19341 — hash_hkdf(null) coerces then ValueError empty key (php-src ext/hash/hash_hkdf.c).
 * Non-strict (matches issue repro); declare(strict_types=1) is TypeError on Zend.
 */
try {
    echo bin2hex(hash_hkdf('sha256', 'key', 16, 'info', 'salt')), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
foreach ([null, ''] as $k) {
    try {
        hash_hkdf('sha256', $k);
        echo "unexpected_ok\n";
    } catch (Throwable $e) {
        echo var_export($k, true), ' ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}
