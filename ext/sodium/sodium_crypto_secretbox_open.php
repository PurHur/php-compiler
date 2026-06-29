<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sodium;

/** sodium_crypto_secretbox_open() — decrypt secretbox (php-src ext/sodium/libsodium.c; #13078). */
final class sodium_crypto_secretbox_open extends SodiumFunction
{
    public function __construct()
    {
        parent::__construct('sodium_crypto_secretbox_open');
    }

    protected function invoke(string $message, string $nonce, string $key): string
    {
        return VmSodium::secretboxOpen($message, $nonce, $key);
    }
}
