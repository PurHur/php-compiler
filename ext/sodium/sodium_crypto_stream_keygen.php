<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sodium;

/** sodium_crypto_stream_keygen() — random ChaCha20 key (php-src ext/sodium/libsodium.c; #15464). */
final class sodium_crypto_stream_keygen extends SodiumKeygenFunction
{
    public function __construct()
    {
        parent::__construct('sodium_crypto_stream_keygen');
    }

    protected function invoke(): string
    {
        return VmSodium::streamKeygen();
    }
}
