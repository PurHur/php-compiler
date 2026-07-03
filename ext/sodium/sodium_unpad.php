<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sodium;

/** sodium_unpad() — remove block padding (php-src ext/sodium/libsodium.c; #15532). */
final class sodium_unpad extends SodiumPadFunction
{
    public function __construct()
    {
        parent::__construct('sodium_unpad');
    }

    protected function invoke(string $string, int $blockSize): string
    {
        return VmSodium::unpad($string, $blockSize);
    }
}
