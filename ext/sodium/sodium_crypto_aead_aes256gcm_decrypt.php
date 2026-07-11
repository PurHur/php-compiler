<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sodium;

/** sodium_crypto_aead_aes256gcm_decrypt() — AES-256-GCM AEAD (php-src ext/sodium/libsodium.c; #15542). */
final class sodium_crypto_aead_aes256gcm_decrypt extends SodiumAeadDecryptFunction
{
    public function __construct()
    {
        parent::__construct('sodium_crypto_aead_aes256gcm_decrypt');
    }

    protected function invoke(string $ciphertext, string $additionalData, string $nonce, string $key): string|false
    {
        return VmSodium::aeadAes256gcmDecrypt($ciphertext, $additionalData, $nonce, $key);
    }
}
