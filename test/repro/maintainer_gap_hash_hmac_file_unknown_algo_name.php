<?php
/**
 * #30646 — hash_hmac_file() unknown/null-coerced algo ValueError cites hash_hmac_file()
 * (not hash_hmac()). php-src: ext/hash/hash.c PHP_FUNCTION(hash_hmac_file).
 */
declare(strict_types=0);

foreach (['nope', null] as $algo) {
    try {
        hash_hmac_file($algo, '/etc/hosts', 'k');
        echo 'uncaught:', var_export($algo, true), "\n";
    } catch (Throwable $e) {
        echo get_class($e), ': ', $e->getMessage(), "\n";
    }
}

try {
    hash_hmac('nope', 'data', 'key');
    echo "hash_hmac uncaught\n";
} catch (Throwable $e) {
    echo 'hash_hmac ', get_class($e), ': ', $e->getMessage(), "\n";
}
