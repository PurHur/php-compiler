<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sodium;

/** sodium_crypto_pwhash_scryptsalsa208sha256() — scrypt KDF (php-src ext/sodium/libsodium.c; #21460). */
final class sodium_crypto_pwhash_scryptsalsa208sha256 extends SodiumPwhashScryptFunction
{
    public function __construct()
    {
        parent::__construct('sodium_crypto_pwhash_scryptsalsa208sha256');
    }

    protected function invoke(
        int $length,
        string $password,
        string $salt,
        int $opslimit,
        int $memlimit
    ): string {
        return VmSodium::pwhashScrypt($length, $password, $salt, $opslimit, $memlimit);
    }
}
