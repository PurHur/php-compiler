<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sodium;

/** sodium_crypto_scalarmult() — Curve25519 scalar mult (php-src ext/sodium/libsodium.c; #15516). */
final class sodium_crypto_scalarmult extends SodiumScalarmultFunction
{
    public function __construct()
    {
        parent::__construct('sodium_crypto_scalarmult');
    }

    protected function invoke(string $n, string $p): string
    {
        return VmSodium::scalarmult($n, $p);
    }
}
