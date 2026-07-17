<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sodium;

/** sodium_crypto_scalarmult_ristretto255_base() — Ristretto255 (php-src ext/sodium/libsodium.c; #20084). */
final class sodium_crypto_scalarmult_ristretto255_base extends SodiumOneStringFunction
{
    public function __construct()
    {
        parent::__construct('sodium_crypto_scalarmult_ristretto255_base');
    }

    protected function argName(): string
    {
        return 'n';
    }

    protected function invoke(string $value): string
    {
        return VmSodium::scalarmultRistretto255Base($value);
    }
}
