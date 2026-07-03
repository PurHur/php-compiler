<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sodium;

/** sodium_crypto_sign() — Ed25519 attached signature (php-src ext/sodium/libsodium.c; #15541). */
final class sodium_crypto_sign extends SodiumSignFunction
{
    public function __construct()
    {
        parent::__construct('sodium_crypto_sign');
    }

    protected function invoke(string $message, string $secretkey): string
    {
        return VmSodium::sign($message, $secretkey);
    }
}
