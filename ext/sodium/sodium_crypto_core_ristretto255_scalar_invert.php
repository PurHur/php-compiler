<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sodium;

/** sodium_crypto_core_ristretto255_scalar_invert() — Ristretto255 (php-src ext/sodium/libsodium.c; #20084). */
final class sodium_crypto_core_ristretto255_scalar_invert extends SodiumOneStringFunction
{
    public function __construct()
    {
        parent::__construct('sodium_crypto_core_ristretto255_scalar_invert');
    }

    protected function argName(): string
    {
        return 's';
    }

    protected function invoke(string $value): string
    {
        return VmSodium::ristretto255ScalarInvert($value);
    }
}
