<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sodium;

/** sodium_crypto_box_seal_open() — decrypt box seal ciphertext (php-src ext/sodium/libsodium.c; #15515). */
final class sodium_crypto_box_seal_open extends SodiumBoxSealOpenFunction
{
    public function __construct()
    {
        parent::__construct('sodium_crypto_box_seal_open');
    }

    /**
     * @return string|false
     */
    protected function invoke(string $ciphertext, string $keypair): string|false
    {
        return VmSodium::boxSealOpen($ciphertext, $keypair);
    }
}
