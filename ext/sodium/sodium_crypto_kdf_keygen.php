<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sodium;

/** sodium_crypto_kdf_keygen() — random KDF master key (php-src ext/sodium/libsodium.c; #20063). */
final class sodium_crypto_kdf_keygen extends SodiumKeygenFunction
{
    public function __construct()
    {
        parent::__construct('sodium_crypto_kdf_keygen');
    }

    protected function invoke(): string
    {
        return VmSodium::kdfKeygen();
    }
}
