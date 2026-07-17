<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sodium;

/** sodium_crypto_core_ristretto255_scalar_random() — Ristretto255 (php-src ext/sodium/libsodium.c; #20084). */
final class sodium_crypto_core_ristretto255_scalar_random extends SodiumKeygenFunction
{
    public function __construct()
    {
        parent::__construct('sodium_crypto_core_ristretto255_scalar_random');
    }

    protected function invoke(): string
    {
        return VmSodium::ristretto255ScalarRandom();
    }
}
