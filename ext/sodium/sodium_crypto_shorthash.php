<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sodium;

/** sodium_crypto_shorthash() — SipHash-2-4 (php-src ext/sodium/libsodium.c; #20063). */
final class sodium_crypto_shorthash extends SodiumShorthashFunction
{
    public function __construct()
    {
        parent::__construct('sodium_crypto_shorthash');
    }

    protected function invoke(string $message, string $key): string
    {
        return VmSodium::shorthash($message, $key);
    }
}
