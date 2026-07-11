<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sodium;

/** sodium_crypto_auth_verify() — verify one-time MAC (php-src ext/sodium/libsodium.c; #15514). */
final class sodium_crypto_auth_verify extends SodiumAuthVerifyFunction
{
    public function __construct()
    {
        parent::__construct('sodium_crypto_auth_verify');
    }

    protected function invoke(string $mac, string $message, string $key): bool
    {
        return VmSodium::authVerify($mac, $message, $key);
    }
}
