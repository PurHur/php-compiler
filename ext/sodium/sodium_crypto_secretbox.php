<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sodium;

/** sodium_crypto_secretbox() — authenticated encryption (php-src ext/sodium/libsodium.c; #13078). */
final class sodium_crypto_secretbox extends SodiumFunction
{
    public function __construct()
    {
        parent::__construct('sodium_crypto_secretbox');
    }

    protected function invoke(string $message, string $nonce, string $key): string
    {
        return VmSodium::secretbox($message, $nonce, $key);
    }
}
