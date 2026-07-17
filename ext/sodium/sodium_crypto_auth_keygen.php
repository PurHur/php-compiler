<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sodium;

/** sodium_crypto_auth_keygen() — random auth key (php-src ext/sodium/libsodium.c; #20082). */
final class sodium_crypto_auth_keygen extends SodiumKeygenFunction
{
    public function __construct()
    {
        parent::__construct('sodium_crypto_auth_keygen');
    }

    protected function invoke(): string
    {
        return VmSodium::authKeygen();
    }
}
