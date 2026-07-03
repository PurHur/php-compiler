<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sodium;

/** sodium_memcmp() — constant-time compare (php-src ext/sodium/libsodium.c; #15531). */
final class sodium_memcmp extends SodiumMemcmpFunction
{
    public function __construct()
    {
        parent::__construct('sodium_memcmp');
    }

    protected function invoke(string $string1, string $string2): int
    {
        return VmSodium::memcmp($string1, $string2);
    }
}
