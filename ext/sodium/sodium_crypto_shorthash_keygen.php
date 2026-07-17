<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sodium;

/** sodium_crypto_shorthash_keygen() — random SipHash key (php-src ext/sodium/libsodium.c; #20063). */
final class sodium_crypto_shorthash_keygen extends SodiumKeygenFunction
{
    public function __construct()
    {
        parent::__construct('sodium_crypto_shorthash_keygen');
    }

    protected function invoke(): string
    {
        return VmSodium::shorthashKeygen();
    }
}
