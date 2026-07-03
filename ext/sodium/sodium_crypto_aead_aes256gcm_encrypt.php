<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sodium;

/** sodium_crypto_aead_aes256gcm_encrypt() — AES-256-GCM AEAD (php-src ext/sodium/libsodium.c; #15542). */
final class sodium_crypto_aead_aes256gcm_encrypt extends SodiumAeadEncryptFunction
{
    public function __construct()
    {
        parent::__construct('sodium_crypto_aead_aes256gcm_encrypt');
    }

    protected function invoke(string $message, string $additionalData, string $nonce, string $key): string
    {
        return VmSodium::aeadAes256gcmEncrypt($message, $additionalData, $nonce, $key);
    }
}
