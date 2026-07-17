<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sodium;

/** sodium_crypto_kx_publickey() — extract public key from kx keypair (#20047). */
final class sodium_crypto_kx_publickey extends SodiumBoxKeypairExtractFunction
{
    public function __construct()
    {
        parent::__construct('sodium_crypto_kx_publickey');
    }

    protected function invoke(string $keypair): string
    {
        return VmSodium::kxPublickey($keypair);
    }
}
