<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sodium;

/** sodium_crypto_aead_xchacha20poly1305_ietf_encrypt() — XChaCha20-Poly1305 AEAD (php-src ext/sodium/libsodium.c; #15429). */
final class sodium_crypto_aead_xchacha20poly1305_ietf_encrypt extends SodiumAeadEncryptFunction
{
    public function __construct()
    {
        parent::__construct('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt');
    }

    protected function invoke(string $message, string $additionalData, string $nonce, string $key): string
    {
        return VmSodium::aeadXchacha20poly1305IetfEncrypt($message, $additionalData, $nonce, $key);
    }
}
