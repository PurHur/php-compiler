<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sodium;

/** sodium_crypto_stream_xchacha20_xor() — XChaCha20 XOR (php-src ext/sodium/libsodium.c; #15461). */
final class sodium_crypto_stream_xchacha20_xor extends SodiumStreamXorFunction
{
    public function __construct()
    {
        parent::__construct('sodium_crypto_stream_xchacha20_xor');
    }

    protected function invoke(string $message, string $nonce, string $key): string
    {
        return VmSodium::streamXchacha20Xor($message, $nonce, $key);
    }
}
