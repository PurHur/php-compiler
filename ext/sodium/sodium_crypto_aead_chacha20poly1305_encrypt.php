<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sodium;

/** sodium_crypto_aead_chacha20poly1305_encrypt() — classic ChaCha20-Poly1305 AEAD (php-src ext/sodium/libsodium.c; #20031). */
final class sodium_crypto_aead_chacha20poly1305_encrypt extends SodiumAeadEncryptFunction
{
    public function __construct()
    {
        parent::__construct('sodium_crypto_aead_chacha20poly1305_encrypt');
    }

    protected function invoke(string $message, string $additionalData, string $nonce, string $key): string
    {
        return VmSodium::aeadChacha20poly1305Encrypt($message, $additionalData, $nonce, $key);
    }
}
