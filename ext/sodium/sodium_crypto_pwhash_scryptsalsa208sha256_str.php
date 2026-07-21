<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sodium;

/** sodium_crypto_pwhash_scryptsalsa208sha256_str() — scrypt password hash (php-src ext/sodium/libsodium.c; #21460). */
final class sodium_crypto_pwhash_scryptsalsa208sha256_str extends SodiumPwhashStrFunction
{
    public function __construct()
    {
        parent::__construct('sodium_crypto_pwhash_scryptsalsa208sha256_str');
    }

    protected function invoke(string $password, int $opslimit, int $memlimit): string
    {
        return VmSodium::pwhashScryptStr($password, $opslimit, $memlimit);
    }
}
