<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sodium;

/** sodium_crypto_pwhash_str() — Argon2id password hash string (php-src ext/sodium/libsodium.c; #20048). */
final class sodium_crypto_pwhash_str extends SodiumPwhashStrFunction
{
    public function __construct()
    {
        parent::__construct('sodium_crypto_pwhash_str');
    }

    protected function invoke(string $password, int $opslimit, int $memlimit): string
    {
        return VmSodium::pwhashStr($password, $opslimit, $memlimit);
    }
}
