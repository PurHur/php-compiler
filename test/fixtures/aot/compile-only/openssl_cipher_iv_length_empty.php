<?php

declare(strict_types=1);

// Compile-only (#18154): openssl_cipher_iv_length('') empty guard lowers for AOT.
try {
    openssl_cipher_iv_length('');
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
