<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sodium;

/** sodium_crypto_stream_xor() — ChaCha20 XOR (php-src ext/sodium/libsodium.c; #15464). */
final class sodium_crypto_stream_xor extends SodiumStreamXorFunction
{
    public function __construct()
    {
        parent::__construct('sodium_crypto_stream_xor');
    }

    protected function invoke(string $message, string $nonce, string $key): string
    {
        return VmSodium::streamXor($message, $nonce, $key);
    }
}
