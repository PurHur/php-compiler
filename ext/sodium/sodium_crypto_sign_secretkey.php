<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sodium;

/** sodium_crypto_sign_secretkey() — extract secret key from keypair (php-src ext/sodium/libsodium.c; #15541). */
final class sodium_crypto_sign_secretkey extends SodiumSignKeypairExtractFunction
{
    public function __construct()
    {
        parent::__construct('sodium_crypto_sign_secretkey');
    }

    protected function invoke(string $keypair): string
    {
        return VmSodium::signSecretkey($keypair);
    }
}
