<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sodium;

/** sodium_crypto_aead_chacha20poly1305_ietf_decrypt() — IETF ChaCha20-Poly1305 AEAD open (php-src ext/sodium/libsodium.c; #20031). */
final class sodium_crypto_aead_chacha20poly1305_ietf_decrypt extends SodiumAeadDecryptFunction
{
    public function __construct()
    {
        parent::__construct('sodium_crypto_aead_chacha20poly1305_ietf_decrypt');
    }

    protected function invoke(string $ciphertext, string $additionalData, string $nonce, string $key): string|false
    {
        return VmSodium::aeadChacha20poly1305IetfDecrypt($ciphertext, $additionalData, $nonce, $key);
    }
}
