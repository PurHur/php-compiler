<?php
/**
 * Issue #21079 / #21319 — hash_hkdf() null $key/$algo soft-null on PROFILE=8.4
 * then empty-key ValueError (php-src ext/hash/hash.stub.php).
 */
try {
    hash_hkdf('sha256', null);
    echo "null_key=OK\n";
} catch (TypeError $e) {
    echo "null_key=TYPEERROR\n";
} catch (ValueError $e) {
    echo "null_key=VALUEERROR\n";
}
try {
    hash_hkdf(null, 'k');
    echo "null_algo=OK\n";
} catch (TypeError $e) {
    echo "null_algo=TYPEERROR\n";
} catch (ValueError $e) {
    echo "null_algo=VALUEERROR\n";
}
try {
    hash_hkdf('sha256', '');
    echo "empty_key=OK\n";
} catch (ValueError $e) {
    echo "empty_key=VALUEERROR\n";
}
