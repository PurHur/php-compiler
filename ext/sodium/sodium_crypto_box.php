<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sodium;

/** sodium_crypto_box() — authenticated public-key encryption (php-src ext/sodium/libsodium.c; #20026). */
final class sodium_crypto_box extends SodiumBoxFunction
{
    public function __construct()
    {
        parent::__construct('sodium_crypto_box');
    }

    protected function invoke(string $message, string $nonce, string $keypair): string
    {
        return VmSodium::box($message, $nonce, $keypair);
    }
}
