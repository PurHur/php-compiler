<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sodium;

/** sodium_crypto_pwhash_str_needs_rehash() — check hash params (php-src ext/sodium/libsodium.c; #20048). */
final class sodium_crypto_pwhash_str_needs_rehash extends SodiumPwhashStrNeedsRehashFunction
{
    public function __construct()
    {
        parent::__construct('sodium_crypto_pwhash_str_needs_rehash');
    }

    protected function invoke(string $hash, int $opslimit, int $memlimit): bool
    {
        return VmSodium::pwhashStrNeedsRehash($hash, $opslimit, $memlimit);
    }
}
