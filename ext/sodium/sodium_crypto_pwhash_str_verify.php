<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sodium;

/** sodium_crypto_pwhash_str_verify() — verify Argon2id hash string (php-src ext/sodium/libsodium.c; #20048). */
final class sodium_crypto_pwhash_str_verify extends SodiumPwhashStrVerifyFunction
{
    public function __construct()
    {
        parent::__construct('sodium_crypto_pwhash_str_verify');
    }

    protected function invoke(string $hash, string $password): bool
    {
        return VmSodium::pwhashStrVerify($hash, $password);
    }
}
