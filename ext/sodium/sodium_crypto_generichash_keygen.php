<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sodium;

/** sodium_crypto_generichash_keygen() — random BLAKE2b key (php-src ext/sodium/libsodium.c; #20062). */
final class sodium_crypto_generichash_keygen extends SodiumKeygenFunction
{
    public function __construct()
    {
        parent::__construct('sodium_crypto_generichash_keygen');
    }

    protected function invoke(): string
    {
        return VmSodium::generichashKeygen();
    }
}
