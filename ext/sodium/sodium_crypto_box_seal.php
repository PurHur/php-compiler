<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sodium;

/** sodium_crypto_box_seal() — anonymous sender encryption (php-src ext/sodium/libsodium.c; #15515). */
final class sodium_crypto_box_seal extends SodiumBoxSealFunction
{
    public function __construct()
    {
        parent::__construct('sodium_crypto_box_seal');
    }

    protected function invoke(string $message, string $publickey): string
    {
        return VmSodium::boxSeal($message, $publickey);
    }
}
