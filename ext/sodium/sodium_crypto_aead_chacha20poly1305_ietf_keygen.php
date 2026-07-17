<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sodium;

/** sodium_crypto_aead_chacha20poly1305_ietf_keygen() — random IETF ChaCha20-Poly1305 key (php-src ext/sodium/libsodium.c; #20031). */
final class sodium_crypto_aead_chacha20poly1305_ietf_keygen extends SodiumKeygenFunction
{
    public function __construct()
    {
        parent::__construct('sodium_crypto_aead_chacha20poly1305_ietf_keygen');
    }

    protected function invoke(): string
    {
        return VmSodium::aeadChacha20poly1305IetfKeygen();
    }
}
