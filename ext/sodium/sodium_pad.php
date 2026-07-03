<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sodium;

/** sodium_pad() — block-aligned padding (php-src ext/sodium/libsodium.c; #15532). */
final class sodium_pad extends SodiumPadFunction
{
    public function __construct()
    {
        parent::__construct('sodium_pad');
    }

    protected function invoke(string $string, int $blockSize): string
    {
        return VmSodium::pad($string, $blockSize);
    }
}
