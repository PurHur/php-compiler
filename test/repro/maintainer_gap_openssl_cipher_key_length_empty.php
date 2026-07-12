<?php

declare(strict_types=1);

try {
    openssl_cipher_key_length('');
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
