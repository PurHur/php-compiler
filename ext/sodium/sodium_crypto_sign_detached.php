<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sodium;

/** sodium_crypto_sign_detached() — Ed25519 detached signature (php-src ext/sodium/libsodium.c; #15541). */
final class sodium_crypto_sign_detached extends SodiumSignFunction
{
    public function __construct()
    {
        parent::__construct('sodium_crypto_sign_detached');
    }

    protected function invoke(string $message, string $secretkey): string
    {
        return VmSodium::signDetached($message, $secretkey);
    }
}
