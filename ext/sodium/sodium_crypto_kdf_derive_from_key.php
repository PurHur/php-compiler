<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sodium;

/** sodium_crypto_kdf_derive_from_key() — derive subkey (php-src ext/sodium/libsodium.c; #20063). */
final class sodium_crypto_kdf_derive_from_key extends SodiumKdfDeriveFromKeyFunction
{
    public function __construct()
    {
        parent::__construct('sodium_crypto_kdf_derive_from_key');
    }

    protected function invoke(int $subkeyLength, int $subkeyId, string $context, string $key): string
    {
        return VmSodium::kdfDeriveFromKey($subkeyLength, $subkeyId, $context, $key);
    }
}
