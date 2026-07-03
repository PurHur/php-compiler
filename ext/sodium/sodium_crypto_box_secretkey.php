<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sodium;

/** sodium_crypto_box_secretkey() — extract secret key from keypair (php-src ext/sodium/libsodium.c; #15515). */
final class sodium_crypto_box_secretkey extends SodiumBoxKeypairExtractFunction
{
    public function __construct()
    {
        parent::__construct('sodium_crypto_box_secretkey');
    }

    protected function invoke(string $keypair): string
    {
        return VmSodium::boxSecretkey($keypair);
    }
}
