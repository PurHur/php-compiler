<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sodium;

use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;
use PHPCompiler\VM;

/**
 * sodium extension module entry (php-src ext/sodium/sodium.c; issue #13078, #3438).
 *
 * Probe + secretbox surface; full ext/sodium matrix tracked in #3438.
 */
class Module extends ModuleAbstract
{
    public function init(Runtime $runtime): void
    {
        require_once __DIR__.'/bootstrap_sodiumexception.php';
        parent::init($runtime);
        if (!SodiumExtensionPolicy::advertisesExtension()) {
            return;
        }
        foreach ([
            'SODIUM_CRYPTO_SECRETBOX_KEYBYTES' => VmSodium::CRYPTO_SECRETBOX_KEYBYTES,
            'SODIUM_CRYPTO_SECRETBOX_NONCEBYTES' => VmSodium::CRYPTO_SECRETBOX_NONCEBYTES,
            'SODIUM_CRYPTO_SECRETBOX_MACBYTES' => VmSodium::CRYPTO_SECRETBOX_MACBYTES,
            'SODIUM_CRYPTO_AUTH_KEYBYTES' => VmSodium::CRYPTO_AUTH_KEYBYTES,
            'SODIUM_CRYPTO_AUTH_BYTES' => VmSodium::CRYPTO_AUTH_BYTES,
            'SODIUM_CRYPTO_STREAM_KEYBYTES' => VmSodium::CRYPTO_STREAM_KEYBYTES,
            'SODIUM_CRYPTO_STREAM_NONCEBYTES' => VmSodium::CRYPTO_STREAM_NONCEBYTES,
            'SODIUM_CRYPTO_STREAM_XCHACHA20_KEYBYTES' => VmSodium::CRYPTO_STREAM_XCHACHA20_KEYBYTES,
            'SODIUM_CRYPTO_STREAM_XCHACHA20_NONCEBYTES' => VmSodium::CRYPTO_STREAM_XCHACHA20_NONCEBYTES,
            'SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES' => VmSodium::CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES,
            'SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES' => VmSodium::CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES,
            'SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NSECRETBYTES' => VmSodium::CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NSECRETBYTES,
            'SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES' => VmSodium::CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES,
        ] as $name => $value) {
            $var = new VM\Variable();
            $var->int($value);
            $runtime->vmContext->defineConstant($name, $var);
        }
    }

    public function getFunctions(): array
    {
        if (!SodiumExtensionPolicy::advertisesExtension()) {
            return [];
        }

        return [
            new sodium_crypto_secretbox(),
            new sodium_crypto_secretbox_open(),
            new sodium_crypto_auth(),
            new sodium_crypto_auth_verify(),
            new sodium_memcmp(),
            new sodium_crypto_stream(),
            new sodium_crypto_stream_xor(),
            new sodium_crypto_stream_keygen(),
            new sodium_crypto_stream_xchacha20(),
            new sodium_crypto_stream_xchacha20_xor(),
            new sodium_crypto_stream_xchacha20_xor_ic(),
            new sodium_crypto_stream_xchacha20_keygen(),
            new sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(),
            new sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(),
        ];
    }
}
