<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sodium;

/** sodium_crypto_box_open() — decrypt authenticated box ciphertext (php-src ext/sodium/libsodium.c; #20026). */
final class sodium_crypto_box_open extends SodiumBoxOpenFunction
{
    public function __construct()
    {
        parent::__construct('sodium_crypto_box_open');
    }

    /**
     * @return string|false
     */
    protected function invoke(string $ciphertext, string $nonce, string $keypair): string|false
    {
        return VmSodium::boxOpen($ciphertext, $nonce, $keypair);
    }
}
