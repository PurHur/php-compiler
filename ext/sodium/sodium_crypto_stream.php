<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sodium;

/** sodium_crypto_stream() — ChaCha20 keystream (php-src ext/sodium/libsodium.c; #15464). */
final class sodium_crypto_stream extends SodiumStreamLengthFunction
{
    public function __construct()
    {
        parent::__construct('sodium_crypto_stream');
    }

    protected function invoke(int $length, string $nonce, string $key): string
    {
        return VmSodium::stream($length, $nonce, $key);
    }
}
