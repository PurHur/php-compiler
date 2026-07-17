<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sodium;

/** sodium_crypto_pwhash() — Argon2 key derivation (php-src ext/sodium/libsodium.c; #20048). */
final class sodium_crypto_pwhash extends SodiumPwhashFunction
{
    public function __construct()
    {
        parent::__construct('sodium_crypto_pwhash');
    }

    protected function invoke(
        int $length,
        string $password,
        string $salt,
        int $opslimit,
        int $memlimit,
        int $algo
    ): string {
        return VmSodium::pwhash($length, $password, $salt, $opslimit, $memlimit, $algo);
    }
}
