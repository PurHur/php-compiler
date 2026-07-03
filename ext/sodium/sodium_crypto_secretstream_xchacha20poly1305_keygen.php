<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sodium;

/** sodium_crypto_secretstream_xchacha20poly1305_keygen() — secretstream key (php-src ext/sodium/libsodium.c; #15462). */
final class sodium_crypto_secretstream_xchacha20poly1305_keygen extends SodiumKeygenFunction
{
    public function __construct()
    {
        parent::__construct('sodium_crypto_secretstream_xchacha20poly1305_keygen');
    }

    protected function invoke(): string
    {
        return VmSodium::secretstreamKeygen();
    }
}
