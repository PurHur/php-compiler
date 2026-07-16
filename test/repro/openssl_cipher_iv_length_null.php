<?php

declare(strict_types=1);

// Issue #19491 — openssl_cipher_iv_length(null) must TypeError on 8.4 forward profile.
try {
    openssl_cipher_iv_length(null);
    echo "uncaught null\n";
} catch (TypeError $e) {
    echo 'null: ', $e->getMessage(), "\n";
} catch (ValueError $e) {
    echo 'null valueerror: ', $e->getMessage(), "\n";
}

try {
    openssl_cipher_iv_length('');
} catch (ValueError $e) {
    echo 'empty: ', $e->getMessage(), "\n";
}
