<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sodium;

/** sodium_crypto_aead_aegis256_keygen() — random AEGIS-256 key (php-src ext/sodium/libsodium.c; #20518). */
final class sodium_crypto_aead_aegis256_keygen extends SodiumKeygenFunction
{
    public function __construct()
    {
        parent::__construct('sodium_crypto_aead_aegis256_keygen');
    }

    protected function invoke(): string
    {
        return VmSodium::aeadAegis256Keygen();
    }
}
