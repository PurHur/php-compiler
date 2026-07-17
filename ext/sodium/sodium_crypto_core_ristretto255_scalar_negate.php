<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sodium;

/** sodium_crypto_core_ristretto255_scalar_negate() — Ristretto255 (php-src ext/sodium/libsodium.c; #20084). */
final class sodium_crypto_core_ristretto255_scalar_negate extends SodiumOneStringFunction
{
    public function __construct()
    {
        parent::__construct('sodium_crypto_core_ristretto255_scalar_negate');
    }

    protected function argName(): string
    {
        return 's';
    }

    protected function invoke(string $value): string
    {
        return VmSodium::ristretto255ScalarNegate($value);
    }
}
