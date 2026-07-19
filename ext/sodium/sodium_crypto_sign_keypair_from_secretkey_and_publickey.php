<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sodium;

/** sodium_crypto_sign_keypair_from_secretkey_and_publickey() — assemble Ed25519 keypair (#21019). */
final class sodium_crypto_sign_keypair_from_secretkey_and_publickey extends SodiumBoxKeypairFromFunction
{
    public function __construct()
    {
        parent::__construct('sodium_crypto_sign_keypair_from_secretkey_and_publickey');
    }

    protected function invoke(string $secretkey, string $publickey): string
    {
        return VmSodium::signKeypairFromSecretkeyAndPublickey($secretkey, $publickey);
    }
}
