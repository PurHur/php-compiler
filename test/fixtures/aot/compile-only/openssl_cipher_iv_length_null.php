<?php

declare(strict_types=1);

// Compile-only (#19491): openssl_cipher_iv_length(null) null guard lowers for AOT on 8.4 profile.
try {
    openssl_cipher_iv_length(null);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
