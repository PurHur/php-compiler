<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sodium;

/** sodium_crypto_kx_keypair() — generate X25519 kx keypair (php-src ext/sodium/libsodium.c; #20047). */
final class sodium_crypto_kx_keypair extends SodiumKeygenFunction
{
    public function __construct()
    {
        parent::__construct('sodium_crypto_kx_keypair');
    }

    protected function invoke(): string
    {
        return VmSodium::kxKeypair();
    }
}
