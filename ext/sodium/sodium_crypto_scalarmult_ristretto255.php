<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sodium;

/** sodium_crypto_scalarmult_ristretto255() — Ristretto255 (php-src ext/sodium/libsodium.c; #20084). */
final class sodium_crypto_scalarmult_ristretto255 extends SodiumTwoStringFunction
{
    public function __construct()
    {
        parent::__construct('sodium_crypto_scalarmult_ristretto255');
    }

    protected function argName0(): string
    {
        return 'n';
    }

    protected function argName1(): string
    {
        return 'p';
    }

    protected function invoke(string $a, string $b): string
    {
        return VmSodium::scalarmultRistretto255($a, $b);
    }
}
