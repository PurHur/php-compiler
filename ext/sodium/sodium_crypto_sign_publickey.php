<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sodium;

/** sodium_crypto_sign_publickey() — extract public key from keypair (php-src ext/sodium/libsodium.c; #15541). */
final class sodium_crypto_sign_publickey extends SodiumSignKeypairExtractFunction
{
    public function __construct()
    {
        parent::__construct('sodium_crypto_sign_publickey');
    }

    protected function invoke(string $keypair): string
    {
        return VmSodium::signPublickey($keypair);
    }
}
