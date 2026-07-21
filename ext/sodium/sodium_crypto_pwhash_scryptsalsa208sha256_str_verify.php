<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sodium;

/** sodium_crypto_pwhash_scryptsalsa208sha256_str_verify() (php-src ext/sodium/libsodium.c; #21460). */
final class sodium_crypto_pwhash_scryptsalsa208sha256_str_verify extends SodiumPwhashStrVerifyFunction
{
    public function __construct()
    {
        parent::__construct('sodium_crypto_pwhash_scryptsalsa208sha256_str_verify');
    }

    protected function invoke(string $hash, string $password): bool
    {
        return VmSodium::pwhashScryptStrVerify($hash, $password);
    }
}
