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
        foreach (SodiumConstants::registeredConstants() as $name => $value) {
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
            new sodium_crypto_secretbox_keygen(),
            new sodium_crypto_auth(),
            new sodium_crypto_auth_verify(),
            new sodium_crypto_auth_keygen(),
            new sodium_memcmp(),
            new sodium_compare(),
            new sodium_increment(),
            new sodium_add(),
            new sodium_bin2hex(),
            new sodium_hex2bin(),
            new sodium_memzero(),
            new sodium_crypto_stream(),
            new sodium_crypto_stream_xor(),
            new sodium_crypto_stream_keygen(),
            new sodium_crypto_stream_xchacha20(),
            new sodium_crypto_stream_xchacha20_xor(),
            new sodium_crypto_stream_xchacha20_xor_ic(),
            new sodium_crypto_stream_xchacha20_keygen(),
            new sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(),
            new sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(),
            new sodium_crypto_aead_chacha20poly1305_keygen(),
            new sodium_crypto_aead_chacha20poly1305_encrypt(),
            new sodium_crypto_aead_chacha20poly1305_decrypt(),
            new sodium_crypto_aead_chacha20poly1305_ietf_keygen(),
            new sodium_crypto_aead_chacha20poly1305_ietf_encrypt(),
            new sodium_crypto_aead_chacha20poly1305_ietf_decrypt(),
            new sodium_pad(),
            new sodium_unpad(),
            new sodium_crypto_generichash(),
            new sodium_crypto_generichash_init(),
            new sodium_crypto_generichash_update(),
            new sodium_crypto_generichash_final(),
            new sodium_crypto_generichash_keygen(),
            new sodium_crypto_scalarmult(),
            new sodium_crypto_scalarmult_base(),
            new sodium_crypto_box_keypair(),
            new sodium_crypto_box_publickey(),
            new sodium_crypto_box_secretkey(),
            new sodium_crypto_box(),
            new sodium_crypto_box_open(),
            new sodium_crypto_box_keypair_from_secretkey_and_publickey(),
            new sodium_crypto_box_publickey_from_secretkey(),
            new sodium_crypto_box_seal(),
            new sodium_crypto_box_seal_open(),
            new sodium_crypto_kx_keypair(),
            new sodium_crypto_kx_publickey(),
            new sodium_crypto_kx_secretkey(),
            new sodium_crypto_kx_seed_keypair(),
            new sodium_crypto_kx_client_session_keys(),
            new sodium_crypto_kx_server_session_keys(),
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
            new sodium_crypto_shorthash(),
            new sodium_crypto_shorthash_keygen(),
            new sodium_crypto_kdf_keygen(),
            new sodium_crypto_kdf_derive_from_key(),
        ];
    }
}
