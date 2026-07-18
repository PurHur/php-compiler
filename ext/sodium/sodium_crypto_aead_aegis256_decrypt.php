<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sodium;

/** sodium_crypto_aead_aegis256_decrypt() — AEGIS-256 AEAD open (php-src ext/sodium/libsodium.c; #20518). */
final class sodium_crypto_aead_aegis256_decrypt extends SodiumAeadDecryptFunction
{
    public function __construct()
    {
        parent::__construct('sodium_crypto_aead_aegis256_decrypt');
    }

    protected function invoke(string $ciphertext, string $additionalData, string $nonce, string $key): string|false
    {
        return VmSodium::aeadAegis256Decrypt($ciphertext, $additionalData, $nonce, $key);
    }
}
