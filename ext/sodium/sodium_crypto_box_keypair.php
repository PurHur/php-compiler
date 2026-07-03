<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sodium;

/** sodium_crypto_box_keypair() — generate box keypair (php-src ext/sodium/libsodium.c; #15515). */
final class sodium_crypto_box_keypair extends SodiumKeygenFunction
{
    public function __construct()
    {
        parent::__construct('sodium_crypto_box_keypair');
    }

    protected function invoke(): string
    {
        return VmSodium::boxKeypair();
    }
}
