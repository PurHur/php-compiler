<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sodium;

/** sodium_crypto_stream_xchacha20() — XChaCha20 keystream (php-src ext/sodium/libsodium.c; #15461). */
final class sodium_crypto_stream_xchacha20 extends SodiumStreamLengthFunction
{
    public function __construct()
    {
        parent::__construct('sodium_crypto_stream_xchacha20');
    }

    protected function invoke(int $length, string $nonce, string $key): string
    {
        return VmSodium::streamXchacha20($length, $nonce, $key);
    }
}
