<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sodium;

/** sodium_crypto_auth() — one-time MAC (php-src ext/sodium/libsodium.c; #15514). */
final class sodium_crypto_auth extends SodiumAuthFunction
{
    public function __construct()
    {
        parent::__construct('sodium_crypto_auth');
    }

    protected function invoke(string $message, string $key): string
    {
        return VmSodium::auth($message, $key);
    }
}
