<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sodium;

/** sodium_crypto_aead_aes256gcm_keygen() — random AES-256-GCM key (php-src ext/sodium/libsodium.c; #21019). */
final class sodium_crypto_aead_aes256gcm_keygen extends SodiumKeygenFunction
{
    public function __construct()
    {
        parent::__construct('sodium_crypto_aead_aes256gcm_keygen');
    }

    protected function invoke(): string
    {
        return VmSodium::aeadAes256gcmKeygen();
    }
}
