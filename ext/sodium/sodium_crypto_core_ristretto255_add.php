<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sodium;

/** sodium_crypto_core_ristretto255_add() — Ristretto255 (php-src ext/sodium/libsodium.c; #20084). */
final class sodium_crypto_core_ristretto255_add extends SodiumTwoStringFunction
{
    public function __construct()
    {
        parent::__construct('sodium_crypto_core_ristretto255_add');
    }

    protected function argName0(): string
    {
        return 'p';
    }

    protected function argName1(): string
    {
        return 'q';
    }

    protected function invoke(string $a, string $b): string
    {
        return VmSodium::ristretto255Add($a, $b);
    }
}
