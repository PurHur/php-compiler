<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sodium;

/** sodium_crypto_aead_aegis128l_keygen() — random AEGIS-128L key (php-src ext/sodium/libsodium.c; #20518). */
final class sodium_crypto_aead_aegis128l_keygen extends SodiumKeygenFunction
{
    public function __construct()
    {
        parent::__construct('sodium_crypto_aead_aegis128l_keygen');
    }

    protected function invoke(): string
    {
        return VmSodium::aeadAegis128lKeygen();
    }
}
