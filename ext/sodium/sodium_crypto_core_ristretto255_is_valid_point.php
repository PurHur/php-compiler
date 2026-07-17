<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sodium;

/** sodium_crypto_core_ristretto255_is_valid_point() — Ristretto255 (php-src ext/sodium/libsodium.c; #20084). */
final class sodium_crypto_core_ristretto255_is_valid_point extends SodiumOneStringBoolFunction
{
    public function __construct()
    {
        parent::__construct('sodium_crypto_core_ristretto255_is_valid_point');
    }

    protected function argName(): string
    {
        return 's';
    }

    protected function invoke(string $value): bool
    {
        return VmSodium::ristretto255IsValidPoint($value);
    }
}
