<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sodium;

/** sodium_crypto_box_publickey_from_secretkey() — derive box public key (#20026). */
final class sodium_crypto_box_publickey_from_secretkey extends SodiumBoxPublickeyFromSecretkeyFunction
{
    public function __construct()
    {
        parent::__construct('sodium_crypto_box_publickey_from_secretkey');
    }

    protected function invoke(string $secretkey): string
    {
        return VmSodium::boxPublickeyFromSecretkey($secretkey);
    }
}
