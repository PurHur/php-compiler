<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sodium;

/** sodium_crypto_secretbox_keygen() — random secretbox key (php-src ext/sodium/libsodium.c; #18314). */
final class sodium_crypto_secretbox_keygen extends SodiumKeygenFunction
{
    public function __construct()
    {
        parent::__construct('sodium_crypto_secretbox_keygen');
    }

    protected function invoke(): string
    {
        return VmSodium::secretboxKeygen();
    }
}
