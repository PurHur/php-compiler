<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sodium;

/** sodium_crypto_box_keypair_from_secretkey_and_publickey() — assemble box keypair (#20026). */
final class sodium_crypto_box_keypair_from_secretkey_and_publickey extends SodiumBoxKeypairFromFunction
{
    public function __construct()
    {
        parent::__construct('sodium_crypto_box_keypair_from_secretkey_and_publickey');
    }

    protected function invoke(string $secretkey, string $publickey): string
    {
        return VmSodium::boxKeypairFromSecretkeyAndPublickey($secretkey, $publickey);
    }
}
