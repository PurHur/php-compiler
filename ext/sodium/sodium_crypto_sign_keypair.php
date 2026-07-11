<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sodium;

/** sodium_crypto_sign_keypair() — Ed25519 keypair (php-src ext/sodium/libsodium.c; #15541). */
final class sodium_crypto_sign_keypair extends SodiumKeygenFunction
{
    public function __construct()
    {
        parent::__construct('sodium_crypto_sign_keypair');
    }

    protected function invoke(): string
    {
        return VmSodium::signKeypair();
    }
}
