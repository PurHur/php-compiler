<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sodium;

/** sodium_crypto_core_ristretto255_scalar_sub() — Ristretto255 (php-src ext/sodium/libsodium.c; #20084). */
final class sodium_crypto_core_ristretto255_scalar_sub extends SodiumTwoStringFunction
{
    public function __construct()
    {
        parent::__construct('sodium_crypto_core_ristretto255_scalar_sub');
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
        return VmSodium::ristretto255ScalarSub($a, $b);
    }
}
