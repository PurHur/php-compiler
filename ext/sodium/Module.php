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
            'SODIUM_CRYPTO_GENERICHASH_BYTES' => VmSodium::CRYPTO_GENERICHASH_BYTES,
            'SODIUM_CRYPTO_GENERICHASH_BYTES_MIN' => VmSodium::CRYPTO_GENERICHASH_BYTES_MIN,
            'SODIUM_CRYPTO_GENERICHASH_BYTES_MAX' => VmSodium::CRYPTO_GENERICHASH_BYTES_MAX,
            'SODIUM_CRYPTO_GENERICHASH_KEYBYTES' => VmSodium::CRYPTO_GENERICHASH_KEYBYTES,
            'SODIUM_CRYPTO_GENERICHASH_KEYBYTES_MIN' => VmSodium::CRYPTO_GENERICHASH_KEYBYTES_MIN,
            'SODIUM_CRYPTO_GENERICHASH_KEYBYTES_MAX' => VmSodium::CRYPTO_GENERICHASH_KEYBYTES_MAX,
            'SODIUM_CRYPTO_SCALARMULT_BYTES' => VmSodium::CRYPTO_SCALARMULT_BYTES,
            'SODIUM_CRYPTO_SCALARMULT_SCALARBYTES' => VmSodium::CRYPTO_SCALARMULT_SCALARBYTES,
            'SODIUM_CRYPTO_BOX_SECRETKEYBYTES' => VmSodium::CRYPTO_BOX_SECRETKEYBYTES,
            'SODIUM_CRYPTO_BOX_PUBLICKEYBYTES' => VmSodium::CRYPTO_BOX_PUBLICKEYBYTES,
            'SODIUM_CRYPTO_BOX_KEYPAIRBYTES' => VmSodium::CRYPTO_BOX_KEYPAIRBYTES,
            'SODIUM_CRYPTO_BOX_MACBYTES' => VmSodium::CRYPTO_BOX_MACBYTES,
            'SODIUM_CRYPTO_BOX_SEALBYTES' => VmSodium::CRYPTO_BOX_SEALBYTES,
            'SODIUM_CRYPTO_AEAD_AES256GCM_KEYBYTES' => VmSodium::CRYPTO_AEAD_AES256GCM_KEYBYTES,
            'SODIUM_CRYPTO_AEAD_AES256GCM_NPUBBYTES' => VmSodium::CRYPTO_AEAD_AES256GCM_NPUBBYTES,
            'SODIUM_CRYPTO_AEAD_AES256GCM_NSECBYTES' => VmSodium::CRYPTO_AEAD_AES256GCM_NSECRETBYTES,
            'SODIUM_CRYPTO_AEAD_AES256GCM_ABYTES' => VmSodium::CRYPTO_AEAD_AES256GCM_ABYTES,
            'SODIUM_CRYPTO_SIGN_BYTES' => VmSodium::CRYPTO_SIGN_BYTES,
            'SODIUM_CRYPTO_SIGN_SEEDBYTES' => VmSodium::CRYPTO_SIGN_SEEDBYTES,
            'SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES' => VmSodium::CRYPTO_SIGN_PUBLICKEYBYTES,
            'SODIUM_CRYPTO_SIGN_SECRETKEYBYTES' => VmSodium::CRYPTO_SIGN_SECRETKEYBYTES,
            'SODIUM_CRYPTO_SIGN_KEYPAIRBYTES' => VmSodium::CRYPTO_SIGN_KEYPAIRBYTES,
            'SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES' => VmSodium::CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES,
            'SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES' => VmSodium::CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES,
            'SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES' => VmSodium::CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES,
            'SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE' => VmSodium::CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE,
            'SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_PUSH' => VmSodium::CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_PUSH,
            'SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_REKEY' => VmSodium::CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_REKEY,
            'SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL' => VmSodium::CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL,
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
            new sodium_pad(),
            new sodium_unpad(),
            new sodium_crypto_generichash(),
            new sodium_crypto_scalarmult(),
            new sodium_crypto_scalarmult_base(),
            new sodium_crypto_box_keypair(),
            new sodium_crypto_box_publickey(),
            new sodium_crypto_box_secretkey(),
            new sodium_crypto_box_seal(),
            new sodium_crypto_box_seal_open(),
            new sodium_crypto_aead_aes256gcm_is_available(),
            new sodium_crypto_aead_aes256gcm_encrypt(),
            new sodium_crypto_aead_aes256gcm_decrypt(),
            new sodium_crypto_sign_keypair(),
            new sodium_crypto_sign_publickey(),
            new sodium_crypto_sign_secretkey(),
            new sodium_crypto_sign_publickey_from_secretkey(),
            new sodium_crypto_sign(),
            new sodium_crypto_sign_open(),
            new sodium_crypto_sign_detached(),
            new sodium_crypto_sign_verify_detached(),
            new sodium_crypto_secretstream_xchacha20poly1305_keygen(),
            new sodium_crypto_secretstream_xchacha20poly1305_init_push(),
            new sodium_crypto_secretstream_xchacha20poly1305_init_pull(),
            new sodium_crypto_secretstream_xchacha20poly1305_push(),
            new sodium_crypto_secretstream_xchacha20poly1305_pull(),
            new sodium_crypto_secretstream_xchacha20poly1305_rekey(),
        ];
    }
}
