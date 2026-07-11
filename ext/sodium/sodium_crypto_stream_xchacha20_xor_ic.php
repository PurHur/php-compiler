<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sodium;

/** sodium_crypto_stream_xchacha20_xor_ic() — XChaCha20 XOR with counter (php-src ext/sodium/libsodium.c; #15461). */
final class sodium_crypto_stream_xchacha20_xor_ic extends SodiumStreamXorIcFunction
{
    public function __construct()
    {
        parent::__construct('sodium_crypto_stream_xchacha20_xor_ic');
    }

    protected function invoke(string $message, string $nonce, int $counter, string $key): string
    {
        return VmSodium::streamXchacha20XorIc($message, $nonce, $counter, $key);
    }
}
