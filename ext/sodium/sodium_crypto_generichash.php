<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sodium;

/** sodium_crypto_generichash() — BLAKE2b generic hash (php-src ext/sodium/libsodium.c; #15530). */
final class sodium_crypto_generichash extends SodiumGenerichashFunction
{
    public function __construct()
    {
        parent::__construct('sodium_crypto_generichash');
    }

    protected function invoke(string $message, string $key, int $length): string
    {
        return VmSodium::generichash($message, $key, $length);
    }
}
