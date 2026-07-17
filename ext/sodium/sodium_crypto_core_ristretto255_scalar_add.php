<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sodium;

/** sodium_crypto_core_ristretto255_scalar_add() — Ristretto255 (php-src ext/sodium/libsodium.c; #20084). */
final class sodium_crypto_core_ristretto255_scalar_add extends SodiumTwoStringFunction
{
    public function __construct()
    {
        parent::__construct('sodium_crypto_core_ristretto255_scalar_add');
    }

    protected function argName0(): string
    {
        return 'x';
    }

    protected function argName1(): string
    {
        return 'y';
    }

    protected function invoke(string $a, string $b): string
    {
        return VmSodium::ristretto255ScalarAdd($a, $b);
    }
}
