<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sodium;

/** sodium_crypto_stream_xchacha20_keygen() — random XChaCha20 key (php-src ext/sodium/libsodium.c; #15461). */
final class sodium_crypto_stream_xchacha20_keygen extends SodiumKeygenFunction
{
    public function __construct()
    {
        parent::__construct('sodium_crypto_stream_xchacha20_keygen');
    }

    protected function invoke(): string
    {
        return VmSodium::streamXchacha20Keygen();
    }
}
