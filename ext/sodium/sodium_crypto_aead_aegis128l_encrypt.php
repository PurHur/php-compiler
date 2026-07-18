<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sodium;

/** sodium_crypto_aead_aegis128l_encrypt() — AEGIS-128L AEAD (php-src ext/sodium/libsodium.c; #20518). */
final class sodium_crypto_aead_aegis128l_encrypt extends SodiumAeadEncryptFunction
{
    public function __construct()
    {
        parent::__construct('sodium_crypto_aead_aegis128l_encrypt');
    }

    protected function invoke(string $message, string $additionalData, string $nonce, string $key): string
    {
        return VmSodium::aeadAegis128lEncrypt($message, $additionalData, $nonce, $key);
    }
}
