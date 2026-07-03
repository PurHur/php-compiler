<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sodium;

/** sodium_crypto_scalarmult_base() — Curve25519 base-point mult (php-src ext/sodium/libsodium.c; #15516). */
final class sodium_crypto_scalarmult_base extends SodiumScalarmultBaseFunction
{
    public function __construct()
    {
        parent::__construct('sodium_crypto_scalarmult_base');
    }

    protected function invoke(string $n): string
    {
        return VmSodium::scalarmultBase($n);
    }
}
