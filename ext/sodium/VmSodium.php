<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sodium;

use PHPCompiler\VM\Variable;

/**
 * libsodium secretbox via host ext/sodium or FFI (issue #13078, #3438).
 *
 * php-src: ext/sodium/libsodium.c — crypto_secretbox / crypto_secretbox_open
 */
final class VmSodium
{
    public const CRYPTO_SECRETBOX_KEYBYTES = 32;

    public const CRYPTO_SECRETBOX_NONCEBYTES = 24;

    public const CRYPTO_SECRETBOX_MACBYTES = 16;

    public const CRYPTO_AUTH_KEYBYTES = 32;

    public const CRYPTO_AUTH_BYTES = 32;

    public const CRYPTO_STREAM_KEYBYTES = 32;

    public const CRYPTO_STREAM_NONCEBYTES = 24;

    public const CRYPTO_STREAM_XCHACHA20_KEYBYTES = 32;

    public const CRYPTO_STREAM_XCHACHA20_NONCEBYTES = 24;

    public const CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES = 32;

    public const CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES = 24;

    public const CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NSECRETBYTES = 0;

    public const CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES = 16;

    /** Classic ChaCha20-Poly1305 AEAD (8-byte nonce; php-src #20031). */
    public const CRYPTO_AEAD_CHACHA20POLY1305_KEYBYTES = 32;

    public const CRYPTO_AEAD_CHACHA20POLY1305_NPUBBYTES = 8;

    public const CRYPTO_AEAD_CHACHA20POLY1305_NSECRETBYTES = 0;

    public const CRYPTO_AEAD_CHACHA20POLY1305_ABYTES = 16;

    /** IETF ChaCha20-Poly1305 AEAD (12-byte nonce; php-src #20031). */
    public const CRYPTO_AEAD_CHACHA20POLY1305_IETF_KEYBYTES = 32;

    public const CRYPTO_AEAD_CHACHA20POLY1305_IETF_NPUBBYTES = 12;

    public const CRYPTO_AEAD_CHACHA20POLY1305_IETF_NSECRETBYTES = 0;

    public const CRYPTO_AEAD_CHACHA20POLY1305_IETF_ABYTES = 16;

    public const CRYPTO_GENERICHASH_BYTES = 32;

    public const CRYPTO_GENERICHASH_BYTES_MIN = 16;

    public const CRYPTO_GENERICHASH_BYTES_MAX = 64;

    public const CRYPTO_GENERICHASH_KEYBYTES = 32;

    public const CRYPTO_GENERICHASH_KEYBYTES_MIN = 16;

    public const CRYPTO_GENERICHASH_KEYBYTES_MAX = 32;

    /** Opaque BLAKE2b state size (libsodium crypto_generichash_statebytes(); #20062). */
    public const CRYPTO_GENERICHASH_STATEBYTES = 384;

    public const CRYPTO_SCALARMULT_BYTES = 32;

    public const CRYPTO_SCALARMULT_SCALARBYTES = 32;

    public const CRYPTO_BOX_SECRETKEYBYTES = 32;

    public const CRYPTO_BOX_PUBLICKEYBYTES = 32;

    public const CRYPTO_BOX_KEYPAIRBYTES = 64;

    public const CRYPTO_BOX_MACBYTES = 16;

    public const CRYPTO_BOX_NONCEBYTES = 24;

    public const CRYPTO_BOX_SEALBYTES = 48;

    public const CRYPTO_AEAD_AES256GCM_KEYBYTES = 32;

    public const CRYPTO_AEAD_AES256GCM_NPUBBYTES = 12;

    public const CRYPTO_AEAD_AES256GCM_NSECRETBYTES = 0;

    public const CRYPTO_AEAD_AES256GCM_ABYTES = 16;

    public const CRYPTO_SIGN_PUBLICKEYBYTES = 32;

    public const CRYPTO_SIGN_SECRETKEYBYTES = 64;

    public const CRYPTO_SIGN_KEYPAIRBYTES = 96;

    public const CRYPTO_SIGN_BYTES = 64;

    public const CRYPTO_SIGN_SEEDBYTES = 32;

    public const CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES = 32;

    public const CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES = 24;

    public const CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES = 17;

    public const CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_STATEBYTES = 52;

    public const CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE = 0;

    public const CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_PUSH = 1;

    public const CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_REKEY = 2;

    public const CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL = 3;

    /** SipHash-2-4 short hash (php-src crypto_shorthash_*; #20063). */
    public const CRYPTO_SHORTHASH_BYTES = 8;

    public const CRYPTO_SHORTHASH_KEYBYTES = 16;

    /** BLAKE2b-based key derivation (php-src crypto_kdf_*; #20063). */
    public const CRYPTO_KDF_BYTES_MIN = 16;

    public const CRYPTO_KDF_BYTES_MAX = 64;

    public const CRYPTO_KDF_CONTEXTBYTES = 8;

    public const CRYPTO_KDF_KEYBYTES = 32;

    private static ?\FFI $ffi = null;

    private static bool $ffiUnavailable = false;

    public static function available(): bool
    {
        return \function_exists('sodium_crypto_secretbox')
            || \function_exists('sodium_crypto_auth')
            || \function_exists('sodium_crypto_stream')
            || \function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')
            || \function_exists('sodium_crypto_aead_chacha20poly1305_encrypt')
            || \function_exists('sodium_pad')
            || \function_exists('sodium_crypto_generichash')
            || \function_exists('sodium_crypto_scalarmult')
            || \function_exists('sodium_crypto_box_seal')
            || \function_exists('sodium_crypto_box')
            || \function_exists('sodium_crypto_aead_aes256gcm_encrypt')
            || \function_exists('sodium_crypto_sign_keypair')
            || \function_exists('sodium_crypto_secretstream_xchacha20poly1305_keygen')
            || \function_exists('sodium_crypto_shorthash')
            || \function_exists('sodium_crypto_kdf_derive_from_key')
            || null !== self::ffi();
    }

    public static function aeadAes256gcmIsAvailable(): bool
    {
        if (\function_exists('sodium_crypto_aead_aes256gcm_is_available')) {
            return \sodium_crypto_aead_aes256gcm_is_available();
        }
        $ffi = self::ffi();
        if (null === $ffi) {
            return false;
        }

        return 1 === (int) $ffi->crypto_aead_aes256gcm_is_available();
    }

    public static function pad(string $string, int $blockSize): string
    {
        if ($blockSize <= 0) {
            self::throwSodium(\sprintf(
                'sodium_pad(): Argument #2 ($block_size) must be greater than 0'
            ));
        }
        if (\function_exists('sodium_pad')) {
            return \sodium_pad($string, $blockSize);
        }

        return self::ffiPad($string, $blockSize);
    }

    public static function unpad(string $string, int $blockSize): string
    {
        if ($blockSize <= 0) {
            self::throwSodium(\sprintf(
                'sodium_unpad(): Argument #2 ($block_size) must be greater than 0'
            ));
        }
        if (\strlen($string) < $blockSize) {
            self::throwSodium(
                'sodium_unpad(): Argument #1 ($string) must be at least as long as the block size'
            );
        }
        if (\function_exists('sodium_unpad')) {
            return \sodium_unpad($string, $blockSize);
        }

        return self::ffiUnpad($string, $blockSize);
    }

    public static function generichash(string $message, string $key = '', int $length = self::CRYPTO_GENERICHASH_BYTES): string
    {
        self::validateGenerichashLength($length);
        self::validateGenerichashKey($key);
        if (\function_exists('sodium_crypto_generichash')) {
            return \sodium_crypto_generichash($message, $key, $length);
        }

        return self::ffiGenerichash($message, $key, $length);
    }

    /**
     * sodium_crypto_generichash_init() — streaming BLAKE2b state (php-src ext/sodium/libsodium.c; #20062).
     */
    public static function generichashInit(string $key = '', int $length = self::CRYPTO_GENERICHASH_BYTES): string
    {
        self::validateGenerichashLength($length);
        self::validateGenerichashKey($key);
        if (\function_exists('sodium_crypto_generichash_init')) {
            return \sodium_crypto_generichash_init($key, $length);
        }

        return self::ffiGenerichashInit($key, $length);
    }

    /**
     * sodium_crypto_generichash_update() — feed chunk into state (php-src ext/sodium/libsodium.c; #20062).
     */
    public static function generichashUpdate(string &$state, string $message): bool
    {
        self::validateGenerichashState($state);
        if (\function_exists('sodium_crypto_generichash_update')) {
            return \sodium_crypto_generichash_update($state, $message);
        }
        self::ffiGenerichashUpdate($state, $message);

        return true;
    }

    /**
     * sodium_crypto_generichash_final() — finish hash and wipe state (php-src ext/sodium/libsodium.c; #20062).
     */
    public static function generichashFinal(string &$state, int $length = self::CRYPTO_GENERICHASH_BYTES): string
    {
        self::validateGenerichashLength($length);
        self::validateGenerichashState($state);
        if (\function_exists('sodium_crypto_generichash_final')) {
            $hash = \sodium_crypto_generichash_final($state, $length);
            // php-src leaves the by-ref state as null after final (not "").
            $state = \is_string($state) ? $state : '';

            return $hash;
        }

        return self::ffiGenerichashFinal($state, $length);
    }

    /**
     * sodium_crypto_generichash_keygen() — random BLAKE2b key (php-src ext/sodium/libsodium.c; #20062).
     */
    public static function generichashKeygen(): string
    {
        if (\function_exists('sodium_crypto_generichash_keygen')) {
            return \sodium_crypto_generichash_keygen();
        }

        return self::randomKeyBytes(self::CRYPTO_GENERICHASH_KEYBYTES);
    }

    public static function scalarmult(string $n, string $p): string
    {
        if (\strlen($n) !== self::CRYPTO_SCALARMULT_SCALARBYTES) {
            self::throwSodium(
                'sodium_crypto_scalarmult(): Argument #1 ($n) must be SODIUM_CRYPTO_SCALARMULT_SCALARBYTES bytes long'
            );
        }
        if (\strlen($p) !== self::CRYPTO_SCALARMULT_BYTES) {
            self::throwSodium(
                'sodium_crypto_scalarmult(): Argument #2 ($p) must be SODIUM_CRYPTO_SCALARMULT_BYTES bytes long'
            );
        }
        if (\function_exists('sodium_crypto_scalarmult')) {
            return \sodium_crypto_scalarmult($n, $p);
        }

        return self::ffiScalarmult($n, $p);
    }

    public static function scalarmultBase(string $n): string
    {
        if (\strlen($n) !== self::CRYPTO_SCALARMULT_SCALARBYTES) {
            self::throwSodium(
                'sodium_crypto_scalarmult_base(): Argument #1 ($secret_key) must be SODIUM_CRYPTO_SCALARMULT_SCALARBYTES bytes long'
            );
        }
        if (\function_exists('sodium_crypto_scalarmult_base')) {
            return \sodium_crypto_scalarmult_base($n);
        }

        return self::ffiScalarmultBase($n);
    }

    public static function boxKeypair(): string
    {
        if (\function_exists('sodium_crypto_box_keypair')) {
            return \sodium_crypto_box_keypair();
        }

        return self::ffiBoxKeypair();
    }

    public static function boxPublickey(string $keypair): string
    {
        self::validateBoxKeypair($keypair, 'sodium_crypto_box_publickey');
        if (\function_exists('sodium_crypto_box_publickey')) {
            return \sodium_crypto_box_publickey($keypair);
        }

        return \substr($keypair, self::CRYPTO_BOX_SECRETKEYBYTES, self::CRYPTO_BOX_PUBLICKEYBYTES);
    }

    public static function boxSecretkey(string $keypair): string
    {
        self::validateBoxKeypair($keypair, 'sodium_crypto_box_secretkey');
        if (\function_exists('sodium_crypto_box_secretkey')) {
            return \sodium_crypto_box_secretkey($keypair);
        }

        return \substr($keypair, 0, self::CRYPTO_BOX_SECRETKEYBYTES);
    }

    public static function boxSeal(string $message, string $publickey): string
    {
        if (\strlen($publickey) !== self::CRYPTO_BOX_PUBLICKEYBYTES) {
            self::throwSodium(
                'sodium_crypto_box_seal(): Argument #2 ($public_key) must be SODIUM_CRYPTO_BOX_PUBLICKEYBYTES bytes long'
            );
        }
        if (\function_exists('sodium_crypto_box_seal')) {
            return \sodium_crypto_box_seal($message, $publickey);
        }

        return self::ffiBoxSeal($message, $publickey);
    }

    /**
     * @return string|false
     */
    public static function boxSealOpen(string $ciphertext, string $keypair): string|false
    {
        self::validateBoxKeypair($keypair, 'sodium_crypto_box_seal_open', 2);
        if (\function_exists('sodium_crypto_box_seal_open')) {
            return \sodium_crypto_box_seal_open($ciphertext, $keypair);
        }

        return self::ffiBoxSealOpen($ciphertext, $keypair);
    }

    public static function box(string $message, string $nonce, string $keypair): string
    {
        self::validateBoxNonce($nonce, 'sodium_crypto_box', 2);
        self::validateBoxKeypair($keypair, 'sodium_crypto_box', 3);
        if (\function_exists('sodium_crypto_box')) {
            return \sodium_crypto_box($message, $nonce, $keypair);
        }

        return self::ffiBox($message, $nonce, $keypair);
    }

    /**
     * @return string|false
     */
    public static function boxOpen(string $ciphertext, string $nonce, string $keypair): string|false
    {
        self::validateBoxNonce($nonce, 'sodium_crypto_box_open', 2);
        self::validateBoxKeypair($keypair, 'sodium_crypto_box_open', 3);
        if (\function_exists('sodium_crypto_box_open')) {
            return \sodium_crypto_box_open($ciphertext, $nonce, $keypair);
        }

        return self::ffiBoxOpen($ciphertext, $nonce, $keypair);
    }

    public static function boxKeypairFromSecretkeyAndPublickey(string $secretkey, string $publickey): string
    {
        self::validateBoxSecretkey($secretkey, 'sodium_crypto_box_keypair_from_secretkey_and_publickey', 1);
        self::validateBoxPublickey($publickey, 'sodium_crypto_box_keypair_from_secretkey_and_publickey', 2);
        if (\function_exists('sodium_crypto_box_keypair_from_secretkey_and_publickey')) {
            return \sodium_crypto_box_keypair_from_secretkey_and_publickey($secretkey, $publickey);
        }

        return $secretkey.$publickey;
    }

    public static function boxPublickeyFromSecretkey(string $secretkey): string
    {
        self::validateBoxSecretkey($secretkey, 'sodium_crypto_box_publickey_from_secretkey', 1);
        if (\function_exists('sodium_crypto_box_publickey_from_secretkey')) {
            return \sodium_crypto_box_publickey_from_secretkey($secretkey);
        }

        return self::ffiScalarmultBase($secretkey);
    }

    public static function stream(int $length, string $nonce, string $key): string
    {
        self::validateStreamLength($length, 'sodium_crypto_stream');
        if (\function_exists('sodium_crypto_stream')) {
            self::validateStreamKeyNonce($key, $nonce, 'sodium_crypto_stream', 2, 3);

            return \sodium_crypto_stream($length, $nonce, $key);
        }

        return self::ffiStream($length, $nonce, $key);
    }

    public static function streamXor(string $message, string $nonce, string $key): string
    {
        if (\function_exists('sodium_crypto_stream_xor')) {
            self::validateStreamKeyNonce($key, $nonce, 'sodium_crypto_stream_xor', 2, 3);

            return \sodium_crypto_stream_xor($message, $nonce, $key);
        }

        return self::ffiStreamXor($message, $nonce, $key);
    }

    public static function streamKeygen(): string
    {
        if (\function_exists('sodium_crypto_stream_keygen')) {
            return \sodium_crypto_stream_keygen();
        }

        return self::randomKeyBytes(self::CRYPTO_STREAM_KEYBYTES);
    }

    public static function streamXchacha20(int $length, string $nonce, string $key): string
    {
        self::validateStreamLength($length, 'sodium_crypto_stream_xchacha20');
        if (\function_exists('sodium_crypto_stream_xchacha20')) {
            self::validateXchacha20KeyNonce($key, $nonce, 'sodium_crypto_stream_xchacha20', 2, 3);

            return \sodium_crypto_stream_xchacha20($length, $nonce, $key);
        }

        return self::ffiStreamXchacha20($length, $nonce, $key);
    }

    public static function streamXchacha20Xor(string $message, string $nonce, string $key): string
    {
        if (\function_exists('sodium_crypto_stream_xchacha20_xor')) {
            self::validateXchacha20KeyNonce($key, $nonce, 'sodium_crypto_stream_xchacha20_xor', 2, 3);

            return \sodium_crypto_stream_xchacha20_xor($message, $nonce, $key);
        }

        return self::ffiStreamXchacha20Xor($message, $nonce, $key);
    }

    public static function streamXchacha20XorIc(string $message, string $nonce, int $counter, string $key): string
    {
        if (\function_exists('sodium_crypto_stream_xchacha20_xor_ic')) {
            self::validateXchacha20KeyNonce($key, $nonce, 'sodium_crypto_stream_xchacha20_xor_ic', 2, 4);

            return \sodium_crypto_stream_xchacha20_xor_ic($message, $nonce, $counter, $key);
        }

        return self::ffiStreamXchacha20XorIc($message, $nonce, $counter, $key);
    }

    public static function streamXchacha20Keygen(): string
    {
        if (\function_exists('sodium_crypto_stream_xchacha20_keygen')) {
            return \sodium_crypto_stream_xchacha20_keygen();
        }

        return self::randomKeyBytes(self::CRYPTO_STREAM_XCHACHA20_KEYBYTES);
    }

    public static function aeadXchacha20poly1305IetfEncrypt(
        string $message,
        string $additionalData,
        string $nonce,
        string $key
    ): string {
        if (\function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')) {
            self::validateAeadKeyNonce($key, $nonce, 'sodium_crypto_aead_xchacha20poly1305_ietf_encrypt', 3, 4);

            return \sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($message, $additionalData, $nonce, $key);
        }

        return self::ffiAeadXchacha20poly1305IetfEncrypt($message, $additionalData, $nonce, $key);
    }

    /**
     * @return string|false plaintext or false when authentication fails (php-src-strict)
     */
    public static function aeadXchacha20poly1305IetfDecrypt(
        string $ciphertext,
        string $additionalData,
        string $nonce,
        string $key
    ): string|false {
        if (\function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_decrypt')) {
            self::validateAeadKeyNonce($key, $nonce, 'sodium_crypto_aead_xchacha20poly1305_ietf_decrypt', 3, 4);

            return \sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($ciphertext, $additionalData, $nonce, $key);
        }

        return self::ffiAeadXchacha20poly1305IetfDecrypt($ciphertext, $additionalData, $nonce, $key);
    }

    /**
     * sodium_crypto_aead_chacha20poly1305_keygen() — random classic AEAD key (php-src #20031).
     */
    public static function aeadChacha20poly1305Keygen(): string
    {
        if (\function_exists('sodium_crypto_aead_chacha20poly1305_keygen')) {
            return \sodium_crypto_aead_chacha20poly1305_keygen();
        }

        return self::randomKeyBytes(self::CRYPTO_AEAD_CHACHA20POLY1305_KEYBYTES);
    }

    public static function aeadChacha20poly1305Encrypt(
        string $message,
        string $additionalData,
        string $nonce,
        string $key
    ): string {
        if (\function_exists('sodium_crypto_aead_chacha20poly1305_encrypt')) {
            self::validateAeadChacha20poly1305KeyNonce($key, $nonce, 'sodium_crypto_aead_chacha20poly1305_encrypt', 3, 4);

            return \sodium_crypto_aead_chacha20poly1305_encrypt($message, $additionalData, $nonce, $key);
        }

        return self::ffiAeadChacha20poly1305Encrypt($message, $additionalData, $nonce, $key);
    }

    /**
     * @return string|false
     */
    public static function aeadChacha20poly1305Decrypt(
        string $ciphertext,
        string $additionalData,
        string $nonce,
        string $key
    ): string|false {
        if (\function_exists('sodium_crypto_aead_chacha20poly1305_decrypt')) {
            self::validateAeadChacha20poly1305KeyNonce($key, $nonce, 'sodium_crypto_aead_chacha20poly1305_decrypt', 3, 4);

            return \sodium_crypto_aead_chacha20poly1305_decrypt($ciphertext, $additionalData, $nonce, $key);
        }

        return self::ffiAeadChacha20poly1305Decrypt($ciphertext, $additionalData, $nonce, $key);
    }

    /**
     * sodium_crypto_aead_chacha20poly1305_ietf_keygen() — random IETF AEAD key (php-src #20031).
     */
    public static function aeadChacha20poly1305IetfKeygen(): string
    {
        if (\function_exists('sodium_crypto_aead_chacha20poly1305_ietf_keygen')) {
            return \sodium_crypto_aead_chacha20poly1305_ietf_keygen();
        }

        return self::randomKeyBytes(self::CRYPTO_AEAD_CHACHA20POLY1305_IETF_KEYBYTES);
    }

    public static function aeadChacha20poly1305IetfEncrypt(
        string $message,
        string $additionalData,
        string $nonce,
        string $key
    ): string {
        if (\function_exists('sodium_crypto_aead_chacha20poly1305_ietf_encrypt')) {
            self::validateAeadChacha20poly1305IetfKeyNonce($key, $nonce, 'sodium_crypto_aead_chacha20poly1305_ietf_encrypt', 3, 4);

            return \sodium_crypto_aead_chacha20poly1305_ietf_encrypt($message, $additionalData, $nonce, $key);
        }

        return self::ffiAeadChacha20poly1305IetfEncrypt($message, $additionalData, $nonce, $key);
    }

    /**
     * @return string|false
     */
    public static function aeadChacha20poly1305IetfDecrypt(
        string $ciphertext,
        string $additionalData,
        string $nonce,
        string $key
    ): string|false {
        if (\function_exists('sodium_crypto_aead_chacha20poly1305_ietf_decrypt')) {
            self::validateAeadChacha20poly1305IetfKeyNonce($key, $nonce, 'sodium_crypto_aead_chacha20poly1305_ietf_decrypt', 3, 4);

            return \sodium_crypto_aead_chacha20poly1305_ietf_decrypt($ciphertext, $additionalData, $nonce, $key);
        }

        return self::ffiAeadChacha20poly1305IetfDecrypt($ciphertext, $additionalData, $nonce, $key);
    }

    public static function aeadAes256gcmEncrypt(
        string $message,
        string $additionalData,
        string $nonce,
        string $key
    ): string {
        if (!self::aeadAes256gcmIsAvailable()) {
            self::throwSodium('AES-256-GCM is not available');
        }
        if (\function_exists('sodium_crypto_aead_aes256gcm_encrypt')) {
            self::validateAeadAes256gcmKeyNonce($key, $nonce, 'sodium_crypto_aead_aes256gcm_encrypt', 3, 4);

            return \sodium_crypto_aead_aes256gcm_encrypt($message, $additionalData, $nonce, $key);
        }

        return self::ffiAeadAes256gcmEncrypt($message, $additionalData, $nonce, $key);
    }

    /**
     * @return string|false
     */
    public static function aeadAes256gcmDecrypt(
        string $ciphertext,
        string $additionalData,
        string $nonce,
        string $key
    ): string|false {
        if (!self::aeadAes256gcmIsAvailable()) {
            self::throwSodium('AES-256-GCM is not available');
        }
        if (\function_exists('sodium_crypto_aead_aes256gcm_decrypt')) {
            self::validateAeadAes256gcmKeyNonce($key, $nonce, 'sodium_crypto_aead_aes256gcm_decrypt', 3, 4);

            return \sodium_crypto_aead_aes256gcm_decrypt($ciphertext, $additionalData, $nonce, $key);
        }

        return self::ffiAeadAes256gcmDecrypt($ciphertext, $additionalData, $nonce, $key);
    }

    public static function signKeypair(): string
    {
        if (\function_exists('sodium_crypto_sign_keypair')) {
            return \sodium_crypto_sign_keypair();
        }

        return self::ffiSignKeypair();
    }

    public static function signPublickey(string $keypair): string
    {
        self::validateSignKeypair($keypair, 'sodium_crypto_sign_publickey');
        if (\function_exists('sodium_crypto_sign_publickey')) {
            return \sodium_crypto_sign_publickey($keypair);
        }

        return \substr($keypair, self::CRYPTO_SIGN_SECRETKEYBYTES, self::CRYPTO_SIGN_PUBLICKEYBYTES);
    }

    public static function signSecretkey(string $keypair): string
    {
        self::validateSignKeypair($keypair, 'sodium_crypto_sign_secretkey');
        if (\function_exists('sodium_crypto_sign_secretkey')) {
            return \sodium_crypto_sign_secretkey($keypair);
        }

        return \substr($keypair, 0, self::CRYPTO_SIGN_SECRETKEYBYTES);
    }

    public static function signPublickeyFromSecretkey(string $secretkey): string
    {
        self::validateSignSecretkey($secretkey, 'sodium_crypto_sign_publickey_from_secretkey');
        if (\function_exists('sodium_crypto_sign_publickey_from_secretkey')) {
            return \sodium_crypto_sign_publickey_from_secretkey($secretkey);
        }

        return self::ffiSignPublickeyFromSecretkey($secretkey);
    }

    public static function sign(string $message, string $secretkey): string
    {
        self::validateSignSecretkey($secretkey, 'sodium_crypto_sign', 2);
        if (\function_exists('sodium_crypto_sign')) {
            return \sodium_crypto_sign($message, $secretkey);
        }

        return self::ffiSign($message, $secretkey);
    }

    /**
     * @return string|false
     */
    public static function signOpen(string $signedMessage, string $publickey): string|false
    {
        if (\strlen($publickey) !== self::CRYPTO_SIGN_PUBLICKEYBYTES) {
            self::throwSodium(
                'sodium_crypto_sign_open(): Argument #2 ($public_key) must be SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES bytes long'
            );
        }
        if (\function_exists('sodium_crypto_sign_open')) {
            return \sodium_crypto_sign_open($signedMessage, $publickey);
        }

        return self::ffiSignOpen($signedMessage, $publickey);
    }

    public static function signDetached(string $message, string $secretkey): string
    {
        self::validateSignSecretkey($secretkey, 'sodium_crypto_sign_detached', 2);
        if (\function_exists('sodium_crypto_sign_detached')) {
            return \sodium_crypto_sign_detached($message, $secretkey);
        }

        return self::ffiSignDetached($message, $secretkey);
    }

    public static function signVerifyDetached(string $signature, string $message, string $publickey): bool
    {
        if (\strlen($signature) !== self::CRYPTO_SIGN_BYTES) {
            self::throwSodium(
                'sodium_crypto_sign_verify_detached(): Argument #1 ($signature) must be SODIUM_CRYPTO_SIGN_BYTES bytes long'
            );
        }
        if (\strlen($publickey) !== self::CRYPTO_SIGN_PUBLICKEYBYTES) {
            self::throwSodium(
                'sodium_crypto_sign_verify_detached(): Argument #3 ($public_key) must be SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES bytes long'
            );
        }
        if (\function_exists('sodium_crypto_sign_verify_detached')) {
            return \sodium_crypto_sign_verify_detached($signature, $message, $publickey);
        }

        return self::ffiSignVerifyDetached($signature, $message, $publickey);
    }

    public static function secretstreamKeygen(): string
    {
        if (\function_exists('sodium_crypto_secretstream_xchacha20poly1305_keygen')) {
            return \sodium_crypto_secretstream_xchacha20poly1305_keygen();
        }

        return self::ffiSecretstreamKeygen();
    }

    /**
     * @return array{0: string, 1: string} state and header
     */
    public static function secretstreamInitPush(string $key): array
    {
        self::validateSecretstreamKey($key, 'sodium_crypto_secretstream_xchacha20poly1305_init_push');
        if (\function_exists('sodium_crypto_secretstream_xchacha20poly1305_init_push')) {
            return \sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
        }

        return self::ffiSecretstreamInitPush($key);
    }

    public static function secretstreamInitPull(string $header, string $key): string
    {
        self::validateSecretstreamKey($key, 'sodium_crypto_secretstream_xchacha20poly1305_init_pull', 2);
        if (\strlen($header) !== self::CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES) {
            self::throwSodium(
                'sodium_crypto_secretstream_xchacha20poly1305_init_pull(): Argument #1 ($header) must be SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES bytes long'
            );
        }
        if (\function_exists('sodium_crypto_secretstream_xchacha20poly1305_init_pull')) {
            return \sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $key);
        }

        return self::ffiSecretstreamInitPull($header, $key);
    }

    public static function secretstreamPush(
        string &$state,
        string $message,
        string $additionalData = '',
        int $tag = self::CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE
    ): string {
        self::validateSecretstreamState($state, 'sodium_crypto_secretstream_xchacha20poly1305_push');
        if (\function_exists('sodium_crypto_secretstream_xchacha20poly1305_push')) {
            return \sodium_crypto_secretstream_xchacha20poly1305_push($state, $message, $additionalData, $tag);
        }

        return self::ffiSecretstreamPush($state, $message, $additionalData, $tag);
    }

    /**
     * @return array{0: string, 1: int}|false
     */
    public static function secretstreamPull(
        string &$state,
        string $ciphertext,
        string $additionalData = ''
    ): array|false {
        self::validateSecretstreamState($state, 'sodium_crypto_secretstream_xchacha20poly1305_pull');
        if (\function_exists('sodium_crypto_secretstream_xchacha20poly1305_pull')) {
            return \sodium_crypto_secretstream_xchacha20poly1305_pull($state, $ciphertext, $additionalData);
        }

        return self::ffiSecretstreamPull($state, $ciphertext, $additionalData);
    }

    public static function secretstreamRekey(string &$state): void
    {
        self::validateSecretstreamState($state, 'sodium_crypto_secretstream_xchacha20poly1305_rekey');
        if (\function_exists('sodium_crypto_secretstream_xchacha20poly1305_rekey')) {
            \sodium_crypto_secretstream_xchacha20poly1305_rekey($state);

            return;
        }
        self::ffiSecretstreamRekey($state);
    }

    public static function auth(string $message, string $key): string
    {
        if (\function_exists('sodium_crypto_auth')) {
            return \sodium_crypto_auth($message, $key);
        }

        return self::ffiAuth($message, $key);
    }

    /**
     * sodium_crypto_auth_keygen() — random auth key (php-src ext/sodium/libsodium.c; #20082).
     */
    public static function authKeygen(): string
    {
        if (\function_exists('sodium_crypto_auth_keygen')) {
            return \sodium_crypto_auth_keygen();
        }

        return self::randomKeyBytes(self::CRYPTO_AUTH_KEYBYTES);
    }

    /**
     * sodium_crypto_shorthash() — SipHash-2-4 (php-src ext/sodium/libsodium.c; #20063).
     */
    public static function shorthash(string $message, string $key): string
    {
        if (\strlen($key) !== self::CRYPTO_SHORTHASH_KEYBYTES) {
            self::throwSodium(
                'sodium_crypto_shorthash(): Argument #2 ($key) must be SODIUM_CRYPTO_SHORTHASH_KEYBYTES bytes long'
            );
        }
        if (\function_exists('sodium_crypto_shorthash')) {
            return \sodium_crypto_shorthash($message, $key);
        }

        return self::ffiShorthash($message, $key);
    }

    public static function shorthashKeygen(): string
    {
        if (\function_exists('sodium_crypto_shorthash_keygen')) {
            return \sodium_crypto_shorthash_keygen();
        }

        return self::randomKeyBytes(self::CRYPTO_SHORTHASH_KEYBYTES);
    }

    public static function kdfKeygen(): string
    {
        if (\function_exists('sodium_crypto_kdf_keygen')) {
            return \sodium_crypto_kdf_keygen();
        }

        return self::randomKeyBytes(self::CRYPTO_KDF_KEYBYTES);
    }

    /**
     * sodium_crypto_kdf_derive_from_key() (php-src ext/sodium/libsodium.c; #20063).
     */
    public static function kdfDeriveFromKey(int $subkeyLength, int $subkeyId, string $context, string $key): string
    {
        if ($subkeyLength < self::CRYPTO_KDF_BYTES_MIN) {
            self::throwSodium(
                'sodium_crypto_kdf_derive_from_key(): Argument #1 ($subkey_length) must be greater than or equal to SODIUM_CRYPTO_KDF_BYTES_MIN'
            );
        }
        if ($subkeyLength > self::CRYPTO_KDF_BYTES_MAX) {
            self::throwSodium(
                'sodium_crypto_kdf_derive_from_key(): Argument #1 ($subkey_length) must be less than or equal to SODIUM_CRYPTO_KDF_BYTES_MAX'
            );
        }
        if ($subkeyId < 0) {
            self::throwSodium(
                'sodium_crypto_kdf_derive_from_key(): Argument #2 ($subkey_id) must be greater than or equal to 0'
            );
        }
        if (\strlen($context) !== self::CRYPTO_KDF_CONTEXTBYTES) {
            self::throwSodium(
                'sodium_crypto_kdf_derive_from_key(): Argument #3 ($context) must be SODIUM_CRYPTO_KDF_CONTEXTBYTES bytes long'
            );
        }
        if (\strlen($key) !== self::CRYPTO_KDF_KEYBYTES) {
            // PHP 8.2 wording uses BYTES_MIN (value equals KEYBYTES check); php-src master says KEYBYTES.
            self::throwSodium(
                'sodium_crypto_kdf_derive_from_key(): Argument #4 ($key) must be SODIUM_CRYPTO_KDF_BYTES_MIN bytes long'
            );
        }
        if (\function_exists('sodium_crypto_kdf_derive_from_key')) {
            return \sodium_crypto_kdf_derive_from_key($subkeyLength, $subkeyId, $context, $key);
        }

        return self::ffiKdfDeriveFromKey($subkeyLength, $subkeyId, $context, $key);
    }

    public static function authVerify(string $mac, string $message, string $key): bool
    {
        if (\function_exists('sodium_crypto_auth_verify')) {
            return \sodium_crypto_auth_verify($mac, $message, $key);
        }

        return self::ffiAuthVerify($mac, $message, $key);
    }

    public static function memcmp(string $string1, string $string2): int
    {
        if (\strlen($string1) !== \strlen($string2)) {
            self::throwSodium(
                'sodium_memcmp(): Argument #1 ($string1) and argument #2 ($string_2) must have the same length'
            );
        }
        if (\function_exists('sodium_memcmp')) {
            return \sodium_memcmp($string1, $string2);
        }

        return self::ffiMemcmp($string1, $string2);
    }

    /**
     * JIT/AOT helper path — pure only (never function_exists → PHP sodium_* recursion).
     */
    public static function memcmpStandalone(string $string1, string $string2): int
    {
        if (\strlen($string1) !== \strlen($string2)) {
            self::throwSodium(
                'sodium_memcmp(): Argument #1 ($string1) and argument #2 ($string_2) must have the same length'
            );
        }

        return self::pureMemcmp($string1, $string2);
    }

    /**
     * sodium_increment() — little-endian constant-time increment in place (php-src ext/sodium/libsodium.c; #20081).
     */
    public static function increment(Variable $var): void
    {
        $target = $var->resolveIndirect();
        if (Variable::TYPE_STRING !== $target->type) {
            self::throwSodium('a PHP string is required');
        }
        $buf = $target->toString();
        if (\function_exists('sodium_increment')) {
            \sodium_increment($buf);
        } elseif (null !== self::ffi()) {
            $buf = self::ffiIncrement($buf);
        } else {
            $buf = self::pureIncrement($buf);
        }
        $target->string($buf);
    }

    /**
     * sodium_add() — little-endian constant-time add into &$string1 (php-src ext/sodium/libsodium.c; #20081).
     */
    public static function add(Variable $var, string $string2): void
    {
        $target = $var->resolveIndirect();
        if (Variable::TYPE_STRING !== $target->type) {
            self::throwSodium('PHP strings are required');
        }
        $buf = $target->toString();
        if (\strlen($buf) !== \strlen($string2)) {
            self::throwSodium(
                'sodium_add(): Argument #1 ($string1) and argument #2 ($string_2) must have the same length'
            );
        }
        if (\function_exists('sodium_add')) {
            \sodium_add($buf, $string2);
        } elseif (null !== self::ffi()) {
            $buf = self::ffiAdd($buf, $string2);
        } else {
            $buf = self::pureAdd($buf, $string2);
        }
        $target->string($buf);
    }

    /**
     * sodium_compare() — constant-time lexicographic compare (-1/0/1) (php-src ext/sodium/libsodium.c; #20081).
     */
    public static function compare(string $string1, string $string2): int
    {
        if (\strlen($string1) !== \strlen($string2)) {
            self::throwSodium(
                'sodium_compare(): Argument #1 ($string1) and argument #2 ($string_2) must have the same length'
            );
        }
        if (\function_exists('sodium_compare')) {
            return \sodium_compare($string1, $string2);
        }
        if (null !== self::ffi()) {
            return self::ffiCompare($string1, $string2);
        }

        return self::pureCompare($string1, $string2);
    }

    /** JIT/AOT helper path — never call back into PHP sodium_compare(). */
    public static function compareStandalone(string $string1, string $string2): int
    {
        if (\strlen($string1) !== \strlen($string2)) {
            self::throwSodium(
                'sodium_compare(): Argument #1 ($string1) and argument #2 ($string_2) must have the same length'
            );
        }

        return self::pureCompare($string1, $string2);
    }

    /** Pure/FFI path used by optional helpers — returns mutated copy. */
    public static function incrementCopy(string $string): string
    {
        if (\function_exists('sodium_increment')) {
            $buf = $string;
            \sodium_increment($buf);

            return $buf;
        }
        if (null !== self::ffi()) {
            return self::ffiIncrement($string);
        }

        return self::pureIncrement($string);
    }

    /** Pure/FFI path used by optional helpers — returns mutated copy of $string1. */
    public static function addCopy(string $string1, string $string2): string
    {
        if (\strlen($string1) !== \strlen($string2)) {
            self::throwSodium(
                'sodium_add(): Argument #1 ($string1) and argument #2 ($string_2) must have the same length'
            );
        }
        if (\function_exists('sodium_add')) {
            $buf = $string1;
            \sodium_add($buf, $string2);

            return $buf;
        }
        if (null !== self::ffi()) {
            return self::ffiAdd($string1, $string2);
        }

        return self::pureAdd($string1, $string2);
    }

    /**
     * sodium_bin2hex() — constant-time binary→hex (php-src ext/sodium/libsodium.c; #3438).
     */
    public static function bin2hex(string $string): string
    {
        if (\function_exists('sodium_bin2hex')) {
            return \sodium_bin2hex($string);
        }
        if (null !== self::ffi()) {
            return self::ffiBin2hex($string);
        }

        return \bin2hex($string);
    }

    /**
     * sodium_hex2bin() — hex→binary with optional ignore set (php-src ext/sodium/libsodium.c; #3438).
     */
    public static function hex2bin(string $string, string $ignore = ''): string
    {
        if (\function_exists('sodium_hex2bin')) {
            return \sodium_hex2bin($string, $ignore);
        }

        return self::pureHex2bin($string, $ignore);
    }

    /**
     * sodium_memzero() — wipe buffer then null the PHP string (php-src ext/sodium/libsodium.c; #3438).
     */
    public static function memzero(Variable $var): void
    {
        $target = $var->resolveIndirect();
        if (Variable::TYPE_STRING !== $target->type) {
            self::throwSodium('a PHP string is required');
        }
        $buf = $target->toString();
        $len = \strlen($buf);
        if ($len > 0) {
            if (\function_exists('sodium_memzero')) {
                // Host zeros a copy; observable result is still NULL (php-src convert_to_null).
                $tmp = $buf;
                \sodium_memzero($tmp);
            } elseif (null !== self::ffi()) {
                self::ffiMemzero($buf);
            }
        }
        $target->null();
    }

    public static function secretbox(string $message, string $nonce, string $key): string
    {
        if (\function_exists('sodium_crypto_secretbox')) {
            return \sodium_crypto_secretbox($message, $nonce, $key);
        }

        return self::ffiSecretbox($message, $nonce, $key);
    }

    public static function secretboxOpen(string $ciphertext, string $nonce, string $key): string
    {
        if (\function_exists('sodium_crypto_secretbox_open')) {
            return \sodium_crypto_secretbox_open($ciphertext, $nonce, $key);
        }

        return self::ffiSecretboxOpen($ciphertext, $nonce, $key);
    }

    public static function secretboxKeygen(): string
    {
        if (\function_exists('sodium_crypto_secretbox_keygen')) {
            return \sodium_crypto_secretbox_keygen();
        }

        return self::randomKeyBytes(self::CRYPTO_SECRETBOX_KEYBYTES);
    }

    private static function ffiSecretbox(string $message, string $nonce, string $key): string
    {
        $ffi = self::requireFfi();
        self::validateKeyNonce($key, $nonce);
        $mlen = \strlen($message);
        $clen = $mlen + self::CRYPTO_SECRETBOX_MACBYTES;
        $cBuf = $ffi->new('unsigned char['.$clen.']');
        $mBuf = self::stringToUnsignedCharArray($ffi, $message);
        $nBuf = self::stringToUnsignedCharArray($ffi, $nonce);
        $kBuf = self::stringToUnsignedCharArray($ffi, $key);
        $rc = $ffi->crypto_secretbox($cBuf, $mBuf, $mlen, $nBuf, $kBuf);
        if (0 !== $rc) {
            throw new \Exception('sodium_crypto_secretbox(): internal error');
        }

        return self::unsignedCharArrayToString($cBuf, $clen);
    }

    private static function ffiSecretboxOpen(string $ciphertext, string $nonce, string $key): string
    {
        $ffi = self::requireFfi();
        self::validateKeyNonce($key, $nonce);
        $clen = \strlen($ciphertext);
        if ($clen < self::CRYPTO_SECRETBOX_MACBYTES) {
            throw new \Exception('sodium_crypto_secretbox_open(): ciphertext is too short');
        }
        $mlen = $clen - self::CRYPTO_SECRETBOX_MACBYTES;
        $mBuf = $ffi->new('unsigned char['.$mlen.']');
        $cBuf = self::stringToUnsignedCharArray($ffi, $ciphertext);
        $nBuf = self::stringToUnsignedCharArray($ffi, $nonce);
        $kBuf = self::stringToUnsignedCharArray($ffi, $key);
        $rc = $ffi->crypto_secretbox_open($mBuf, $cBuf, $clen, $nBuf, $kBuf);
        if (0 !== $rc) {
            throw new \Exception('sodium_crypto_secretbox_open(): internal error');
        }

        return self::unsignedCharArrayToString($mBuf, $mlen);
    }

    private static function ffiAuth(string $message, string $key): string
    {
        $ffi = self::requireFfi();
        self::validateAuthKey($key, 'sodium_crypto_auth');
        $mlen = \strlen($message);
        $outBuf = $ffi->new('unsigned char['.self::CRYPTO_AUTH_BYTES.']');
        $mBuf = self::stringToUnsignedCharArray($ffi, $message);
        $kBuf = self::stringToUnsignedCharArray($ffi, $key);
        $rc = $ffi->crypto_auth($outBuf, $mBuf, $mlen, $kBuf);
        if (0 !== $rc) {
            throw new \Exception('sodium_crypto_auth(): internal error');
        }

        return self::unsignedCharArrayToString($outBuf, self::CRYPTO_AUTH_BYTES);
    }

    private static function ffiShorthash(string $message, string $key): string
    {
        $ffi = self::requireFfi();
        $mlen = \strlen($message);
        $outBuf = $ffi->new('unsigned char['.self::CRYPTO_SHORTHASH_BYTES.']');
        $mBuf = self::stringToUnsignedCharArray($ffi, $message);
        $kBuf = self::stringToUnsignedCharArray($ffi, $key);
        $rc = $ffi->crypto_shorthash($outBuf, $mBuf, $mlen, $kBuf);
        if (0 !== $rc) {
            self::throwSodium('internal error');
        }

        return self::unsignedCharArrayToString($outBuf, self::CRYPTO_SHORTHASH_BYTES);
    }

    private static function ffiKdfDeriveFromKey(int $subkeyLength, int $subkeyId, string $context, string $key): string
    {
        $ffi = self::requireFfi();
        $outBuf = $ffi->new('unsigned char['.$subkeyLength.']');
        $ctxBuf = self::stringToUnsignedCharArray($ffi, $context);
        $kBuf = self::stringToUnsignedCharArray($ffi, $key);
        $rc = $ffi->crypto_kdf_derive_from_key($outBuf, $subkeyLength, $subkeyId, $ctxBuf, $kBuf);
        if (0 !== $rc) {
            self::throwSodium('internal error');
        }

        return self::unsignedCharArrayToString($outBuf, $subkeyLength);
    }

    private static function ffiAuthVerify(string $mac, string $message, string $key): bool
    {
        $ffi = self::requireFfi();
        self::validateAuthKey($key, 'sodium_crypto_auth_verify', 3);
        if (\strlen($mac) !== self::CRYPTO_AUTH_BYTES) {
            self::throwSodium(
                'sodium_crypto_auth_verify(): Argument #1 ($mac) must be SODIUM_CRYPTO_AUTH_BYTES bytes long'
            );
        }
        $mlen = \strlen($message);
        $hBuf = self::stringToUnsignedCharArray($ffi, $mac);
        $mBuf = self::stringToUnsignedCharArray($ffi, $message);
        $kBuf = self::stringToUnsignedCharArray($ffi, $key);

        return 0 === $ffi->crypto_auth_verify($hBuf, $mBuf, $mlen, $kBuf);
    }

    private static function ffiMemcmp(string $string1, string $string2): int
    {
        $ffi = self::requireFfi();
        $len = \strlen($string1);
        $s1 = self::stringToUnsignedCharArray($ffi, $string1);
        $s2 = self::stringToUnsignedCharArray($ffi, $string2);

        return $ffi->sodium_memcmp($s1, $s2, $len);
    }

    /** Constant-time equality → 0 / -1 (libsodium sodium_memcmp). */
    private static function pureMemcmp(string $string1, string $string2): int
    {
        $len = \strlen($string1);
        $d = 0;
        for ($i = 0; $i < $len; ++$i) {
            $d |= \ord($string1[$i]) ^ \ord($string2[$i]);
        }

        return (1 & (($d - 1) >> 8)) - 1;
    }

    private static function ffiIncrement(string $string): string
    {
        $ffi = self::requireFfi();
        $len = \strlen($string);
        if ($len <= 0) {
            return $string;
        }
        $buf = self::stringToUnsignedCharArray($ffi, $string);
        $ffi->sodium_increment($buf, $len);

        return self::unsignedCharArrayToString($buf, $len);
    }

    private static function ffiAdd(string $string1, string $string2): string
    {
        $ffi = self::requireFfi();
        $len = \strlen($string1);
        $a = self::stringToUnsignedCharArray($ffi, $string1);
        $b = self::stringToUnsignedCharArray($ffi, $string2);
        $ffi->sodium_add($a, $b, $len);

        return self::unsignedCharArrayToString($a, $len);
    }

    private static function ffiCompare(string $string1, string $string2): int
    {
        $ffi = self::requireFfi();
        $len = \strlen($string1);
        $s1 = self::stringToUnsignedCharArray($ffi, $string1);
        $s2 = self::stringToUnsignedCharArray($ffi, $string2);

        return (int) $ffi->sodium_compare($s1, $s2, $len);
    }

    /** libsodium sodium_increment — little-endian with carry. */
    private static function pureIncrement(string $string): string
    {
        $len = \strlen($string);
        if ($len <= 0) {
            return $string;
        }
        $bytes = \array_values(\unpack('C*', $string) ?: []);
        $c = 1;
        for ($i = 0; $i < $len; ++$i) {
            $c += $bytes[$i];
            $bytes[$i] = $c & 0xff;
            $c >>= 8;
        }
        $out = '';
        foreach ($bytes as $b) {
            $out .= \chr($b);
        }

        return $out;
    }

    /** libsodium sodium_add — little-endian with carry. */
    private static function pureAdd(string $string1, string $string2): string
    {
        $len = \strlen($string1);
        $a = \array_values(\unpack('C*', $string1) ?: []);
        $b = \array_values(\unpack('C*', $string2) ?: []);
        $c = 0;
        for ($i = 0; $i < $len; ++$i) {
            $c += $a[$i] + $b[$i];
            $a[$i] = $c & 0xff;
            $c >>= 8;
        }
        $out = '';
        foreach ($a as $byte) {
            $out .= \chr($byte);
        }

        return $out;
    }

    /**
     * libsodium sodium_compare — constant-time from MSB end; returns -1/0/1.
     *
     * @see https://doc.libsodium.org/helpers#constant-time-comparison
     */
    private static function pureCompare(string $string1, string $string2): int
    {
        $len = \strlen($string1);
        $gt = 0;
        $eq = 1;
        for ($i = $len; $i !== 0; ) {
            --$i;
            $b1 = \ord($string1[$i]);
            $b2 = \ord($string2[$i]);
            $gt |= (($b2 - $b1) >> 8) & $eq;
            $eq &= (($b2 ^ $b1) - 1) >> 8;
        }

        return ($gt + $gt + $eq) - 1;
    }

    private static function ffiBin2hex(string $string): string
    {
        $ffi = self::requireFfi();
        $binLen = \strlen($string);
        $hexLen = $binLen * 2;
        $hexBuf = $ffi->new('char['.($hexLen + 1).']');
        $binBuf = self::stringToUnsignedCharArray($ffi, $string);
        $ffi->sodium_bin2hex($hexBuf, $hexLen + 1, $binBuf, $binLen);
        $out = '';
        for ($i = 0; $i < $hexLen; ++$i) {
            $out .= $hexBuf[$i];
        }

        return $out;
    }

    private static function ffiMemzero(string $buf): void
    {
        $ffi = self::requireFfi();
        $len = \strlen($buf);
        if ($len <= 0) {
            return;
        }
        $cBuf = self::stringToUnsignedCharArray($ffi, $buf);
        $ffi->sodium_memzero($cBuf, $len);
    }

    private static function pureHex2bin(string $hex, string $ignore): string
    {
        if ('' !== $ignore) {
            $ignoreSet = [];
            $ignoreLen = \strlen($ignore);
            for ($i = 0; $i < $ignoreLen; ++$i) {
                $ignoreSet[$ignore[$i]] = true;
            }
            $filtered = '';
            $hexLen = \strlen($hex);
            for ($i = 0; $i < $hexLen; ++$i) {
                $ch = $hex[$i];
                if (!isset($ignoreSet[$ch])) {
                    $filtered .= $ch;
                }
            }
            $hex = $filtered;
        }
        if ('' === $hex) {
            return '';
        }
        if (0 !== (\strlen($hex) % 2) || 1 !== \preg_match('/^[0-9a-fA-F]+$/', $hex)) {
            self::throwSodium('sodium_hex2bin(): Argument #1 ($string) must be a valid hexadecimal string');
        }
        $bin = \hex2bin($hex);
        if (false === $bin) {
            self::throwSodium('sodium_hex2bin(): Argument #1 ($string) must be a valid hexadecimal string');
        }

        return $bin;
    }

    private static function ffiPad(string $string, int $blockSize): string
    {
        $ffi = self::requireFfi();
        $unpaddedLen = \strlen($string);
        $bufLen = $unpaddedLen + $blockSize;
        $buf = $ffi->new('unsigned char['.$bufLen.']');
        for ($i = 0; $i < $unpaddedLen; ++$i) {
            $buf[$i] = \ord($string[$i]);
        }
        $paddedLenOut = $ffi->new('size_t');
        $paddedLenOut->cdata = $unpaddedLen;
        $rc = $ffi->sodium_pad($paddedLenOut, $buf, $unpaddedLen, $blockSize);
        if (0 !== $rc) {
            self::throwSodium('input is too large');
        }

        return self::unsignedCharArrayToString($buf, (int) $paddedLenOut->cdata);
    }

    private static function ffiUnpad(string $string, int $blockSize): string
    {
        $ffi = self::requireFfi();
        $paddedLen = \strlen($string);
        $buf = self::stringToUnsignedCharArray($ffi, $string);
        $unpaddedLenOut = $ffi->new('size_t');
        $rc = $ffi->sodium_unpad($unpaddedLenOut, $buf, $paddedLen, $blockSize);
        if (0 !== $rc) {
            self::throwSodium('sodium_unpad(): padding is invalid');
        }

        return self::unsignedCharArrayToString($buf, (int) $unpaddedLenOut->cdata);
    }

    private static function ffiGenerichash(string $message, string $key, int $length): string
    {
        $ffi = self::requireFfi();
        $mlen = \strlen($message);
        $keyLen = \strlen($key);
        $outBuf = $ffi->new('unsigned char['.$length.']');
        $mBuf = self::stringToUnsignedCharArray($ffi, $message);
        $kBuf = 0 === $keyLen ? $ffi->new('unsigned char[1]') : self::stringToUnsignedCharArray($ffi, $key);
        $rc = $ffi->crypto_generichash($outBuf, $length, $mBuf, $mlen, $kBuf, $keyLen);
        if (0 !== $rc) {
            self::throwSodium('internal error');
        }

        return self::unsignedCharArrayToString($outBuf, $length);
    }

    private static function ffiGenerichashInit(string $key, int $length): string
    {
        $ffi = self::requireFfi();
        $keyLen = \strlen($key);
        $stateBuf = $ffi->new('unsigned char['.self::CRYPTO_GENERICHASH_STATEBYTES.']');
        $kBuf = 0 === $keyLen ? $ffi->new('unsigned char[1]') : self::stringToUnsignedCharArray($ffi, $key);
        $rc = $ffi->crypto_generichash_init($stateBuf, $kBuf, $keyLen, $length);
        if (0 !== $rc) {
            self::throwSodium('internal error');
        }

        return self::unsignedCharArrayToString($stateBuf, self::CRYPTO_GENERICHASH_STATEBYTES);
    }

    private static function ffiGenerichashUpdate(string &$state, string $message): void
    {
        $ffi = self::requireFfi();
        $mlen = \strlen($message);
        $stateBuf = self::stringToUnsignedCharArray($ffi, $state);
        $mBuf = self::stringToUnsignedCharArray($ffi, $message);
        $rc = $ffi->crypto_generichash_update($stateBuf, $mBuf, $mlen);
        if (0 !== $rc) {
            self::throwSodium('internal error');
        }
        $state = self::unsignedCharArrayToString($stateBuf, self::CRYPTO_GENERICHASH_STATEBYTES);
    }

    private static function ffiGenerichashFinal(string &$state, int $length): string
    {
        $ffi = self::requireFfi();
        $outBuf = $ffi->new('unsigned char['.$length.']');
        $stateBuf = self::stringToUnsignedCharArray($ffi, $state);
        $rc = $ffi->crypto_generichash_final($stateBuf, $outBuf, $length);
        if (0 !== $rc) {
            self::throwSodium('internal error');
        }
        $state = '';

        return self::unsignedCharArrayToString($outBuf, $length);
    }

    private static function ffiScalarmult(string $n, string $p): string
    {
        $ffi = self::requireFfi();
        $qBuf = $ffi->new('unsigned char['.self::CRYPTO_SCALARMULT_BYTES.']');
        $nBuf = self::stringToUnsignedCharArray($ffi, $n);
        $pBuf = self::stringToUnsignedCharArray($ffi, $p);
        $rc = $ffi->crypto_scalarmult($qBuf, $nBuf, $pBuf);
        if (0 !== $rc) {
            self::throwSodium('internal error');
        }

        return self::unsignedCharArrayToString($qBuf, self::CRYPTO_SCALARMULT_BYTES);
    }

    private static function ffiScalarmultBase(string $n): string
    {
        $ffi = self::requireFfi();
        $qBuf = $ffi->new('unsigned char['.self::CRYPTO_SCALARMULT_BYTES.']');
        $nBuf = self::stringToUnsignedCharArray($ffi, $n);
        $rc = $ffi->crypto_scalarmult_base($qBuf, $nBuf);
        if (0 !== $rc) {
            self::throwSodium('internal error');
        }

        return self::unsignedCharArrayToString($qBuf, self::CRYPTO_SCALARMULT_BYTES);
    }

    private static function ffiBoxKeypair(): string
    {
        $ffi = self::requireFfi();
        $pkBuf = $ffi->new('unsigned char['.self::CRYPTO_BOX_PUBLICKEYBYTES.']');
        $skBuf = $ffi->new('unsigned char['.self::CRYPTO_BOX_SECRETKEYBYTES.']');
        $rc = $ffi->crypto_box_keypair($pkBuf, $skBuf);
        if (0 !== $rc) {
            self::throwSodium('internal error');
        }

        return self::unsignedCharArrayToString($skBuf, self::CRYPTO_BOX_SECRETKEYBYTES)
            .self::unsignedCharArrayToString($pkBuf, self::CRYPTO_BOX_PUBLICKEYBYTES);
    }

    private static function ffiBoxSeal(string $message, string $publickey): string
    {
        $ffi = self::requireFfi();
        $mlen = \strlen($message);
        $clen = $mlen + self::CRYPTO_BOX_SEALBYTES;
        $cBuf = $ffi->new('unsigned char['.$clen.']');
        $mBuf = self::stringToUnsignedCharArray($ffi, $message);
        $pkBuf = self::stringToUnsignedCharArray($ffi, $publickey);
        $rc = $ffi->crypto_box_seal($cBuf, $mBuf, $mlen, $pkBuf);
        if (0 !== $rc) {
            self::throwSodium('internal error');
        }

        return self::unsignedCharArrayToString($cBuf, $clen);
    }

    /**
     * @return string|false
     */
    private static function ffiBoxSealOpen(string $ciphertext, string $keypair): string|false
    {
        $ffi = self::requireFfi();
        $clen = \strlen($ciphertext);
        if ($clen < self::CRYPTO_BOX_SEALBYTES) {
            return false;
        }
        $mlen = $clen - self::CRYPTO_BOX_SEALBYTES;
        $mBuf = $ffi->new('unsigned char['.$mlen.']');
        $cBuf = self::stringToUnsignedCharArray($ffi, $ciphertext);
        $pkBuf = self::stringToUnsignedCharArray(
            $ffi,
            \substr($keypair, self::CRYPTO_BOX_SECRETKEYBYTES, self::CRYPTO_BOX_PUBLICKEYBYTES)
        );
        $skBuf = self::stringToUnsignedCharArray($ffi, \substr($keypair, 0, self::CRYPTO_BOX_SECRETKEYBYTES));
        $rc = $ffi->crypto_box_seal_open($mBuf, $cBuf, $clen, $pkBuf, $skBuf);
        if (0 !== $rc) {
            return false;
        }

        return self::unsignedCharArrayToString($mBuf, $mlen);
    }

    private static function ffiBox(string $message, string $nonce, string $keypair): string
    {
        $ffi = self::requireFfi();
        $mlen = \strlen($message);
        $clen = $mlen + self::CRYPTO_BOX_MACBYTES;
        $cBuf = $ffi->new('unsigned char['.$clen.']');
        $mBuf = self::stringToUnsignedCharArray($ffi, $message);
        $nBuf = self::stringToUnsignedCharArray($ffi, $nonce);
        $skBuf = self::stringToUnsignedCharArray($ffi, \substr($keypair, 0, self::CRYPTO_BOX_SECRETKEYBYTES));
        $pkBuf = self::stringToUnsignedCharArray(
            $ffi,
            \substr($keypair, self::CRYPTO_BOX_SECRETKEYBYTES, self::CRYPTO_BOX_PUBLICKEYBYTES)
        );
        $rc = $ffi->crypto_box_easy($cBuf, $mBuf, $mlen, $nBuf, $pkBuf, $skBuf);
        if (0 !== $rc) {
            self::throwSodium('internal error');
        }

        return self::unsignedCharArrayToString($cBuf, $clen);
    }

    /**
     * @return string|false
     */
    private static function ffiBoxOpen(string $ciphertext, string $nonce, string $keypair): string|false
    {
        $ffi = self::requireFfi();
        $clen = \strlen($ciphertext);
        if ($clen < self::CRYPTO_BOX_MACBYTES) {
            return false;
        }
        $mlen = $clen - self::CRYPTO_BOX_MACBYTES;
        $mBuf = $ffi->new('unsigned char['.$mlen.']');
        $cBuf = self::stringToUnsignedCharArray($ffi, $ciphertext);
        $nBuf = self::stringToUnsignedCharArray($ffi, $nonce);
        $skBuf = self::stringToUnsignedCharArray($ffi, \substr($keypair, 0, self::CRYPTO_BOX_SECRETKEYBYTES));
        $pkBuf = self::stringToUnsignedCharArray(
            $ffi,
            \substr($keypair, self::CRYPTO_BOX_SECRETKEYBYTES, self::CRYPTO_BOX_PUBLICKEYBYTES)
        );
        $rc = $ffi->crypto_box_open_easy($mBuf, $cBuf, $clen, $nBuf, $pkBuf, $skBuf);
        if (0 !== $rc) {
            return false;
        }

        return self::unsignedCharArrayToString($mBuf, $mlen);
    }

    private static function ffiStream(int $length, string $nonce, string $key): string
    {
        $ffi = self::requireFfi();
        self::validateStreamKeyNonce($key, $nonce, 'sodium_crypto_stream', 2, 3);
        $cBuf = $ffi->new('unsigned char['.$length.']');
        $nBuf = self::stringToUnsignedCharArray($ffi, $nonce);
        $kBuf = self::stringToUnsignedCharArray($ffi, $key);
        $rc = $ffi->crypto_stream($cBuf, $length, $nBuf, $kBuf);
        if (0 !== $rc) {
            throw new \Exception('sodium_crypto_stream(): internal error');
        }

        return self::unsignedCharArrayToString($cBuf, $length);
    }

    private static function ffiStreamXor(string $message, string $nonce, string $key): string
    {
        $ffi = self::requireFfi();
        self::validateStreamKeyNonce($key, $nonce, 'sodium_crypto_stream_xor', 2, 3);
        $mlen = \strlen($message);
        $cBuf = $ffi->new('unsigned char['.$mlen.']');
        $mBuf = self::stringToUnsignedCharArray($ffi, $message);
        $nBuf = self::stringToUnsignedCharArray($ffi, $nonce);
        $kBuf = self::stringToUnsignedCharArray($ffi, $key);
        $rc = $ffi->crypto_stream_xor($cBuf, $mBuf, $mlen, $nBuf, $kBuf);
        if (0 !== $rc) {
            throw new \Exception('sodium_crypto_stream_xor(): internal error');
        }

        return self::unsignedCharArrayToString($cBuf, $mlen);
    }

    private static function ffiStreamXchacha20(int $length, string $nonce, string $key): string
    {
        $ffi = self::requireFfi();
        self::validateXchacha20KeyNonce($key, $nonce, 'sodium_crypto_stream_xchacha20', 2, 3);
        $cBuf = $ffi->new('unsigned char['.$length.']');
        $nBuf = self::stringToUnsignedCharArray($ffi, $nonce);
        $kBuf = self::stringToUnsignedCharArray($ffi, $key);
        $rc = $ffi->crypto_stream_xchacha20($cBuf, $length, $nBuf, $kBuf);
        if (0 !== $rc) {
            throw new \Exception('sodium_crypto_stream_xchacha20(): internal error');
        }

        return self::unsignedCharArrayToString($cBuf, $length);
    }

    private static function ffiStreamXchacha20Xor(string $message, string $nonce, string $key): string
    {
        $ffi = self::requireFfi();
        self::validateXchacha20KeyNonce($key, $nonce, 'sodium_crypto_stream_xchacha20_xor', 2, 3);
        $mlen = \strlen($message);
        $cBuf = $ffi->new('unsigned char['.$mlen.']');
        $mBuf = self::stringToUnsignedCharArray($ffi, $message);
        $nBuf = self::stringToUnsignedCharArray($ffi, $nonce);
        $kBuf = self::stringToUnsignedCharArray($ffi, $key);
        $rc = $ffi->crypto_stream_xchacha20_xor($cBuf, $mBuf, $mlen, $nBuf, $kBuf);
        if (0 !== $rc) {
            throw new \Exception('sodium_crypto_stream_xchacha20_xor(): internal error');
        }

        return self::unsignedCharArrayToString($cBuf, $mlen);
    }

    private static function ffiStreamXchacha20XorIc(string $message, string $nonce, int $counter, string $key): string
    {
        $ffi = self::requireFfi();
        self::validateXchacha20KeyNonce($key, $nonce, 'sodium_crypto_stream_xchacha20_xor_ic', 2, 4);
        $mlen = \strlen($message);
        $cBuf = $ffi->new('unsigned char['.$mlen.']');
        $mBuf = self::stringToUnsignedCharArray($ffi, $message);
        $nBuf = self::stringToUnsignedCharArray($ffi, $nonce);
        $kBuf = self::stringToUnsignedCharArray($ffi, $key);
        $rc = $ffi->crypto_stream_xchacha20_xor_ic($cBuf, $mBuf, $mlen, $nBuf, $kBuf, $counter);
        if (0 !== $rc) {
            throw new \Exception('sodium_crypto_stream_xchacha20_xor_ic(): internal error');
        }

        return self::unsignedCharArrayToString($cBuf, $mlen);
    }

    private static function ffiAeadXchacha20poly1305IetfEncrypt(
        string $message,
        string $additionalData,
        string $nonce,
        string $key
    ): string {
        $ffi = self::requireFfi();
        self::validateAeadKeyNonce($key, $nonce, 'sodium_crypto_aead_xchacha20poly1305_ietf_encrypt', 3, 4);
        $mlen = \strlen($message);
        $adlen = \strlen($additionalData);
        $clen = $mlen + self::CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES;
        $cBuf = $ffi->new('unsigned char['.$clen.']');
        $clenOut = $ffi->new('unsigned long long');
        $mBuf = self::stringToUnsignedCharArray($ffi, $message);
        $adBuf = self::stringToUnsignedCharArray($ffi, $additionalData);
        $nsecBuf = $ffi->new('unsigned char[0]');
        $npubBuf = self::stringToUnsignedCharArray($ffi, $nonce);
        $kBuf = self::stringToUnsignedCharArray($ffi, $key);
        $rc = $ffi->crypto_aead_xchacha20poly1305_ietf_encrypt(
            $cBuf,
            $clenOut,
            $mBuf,
            $mlen,
            $adBuf,
            $adlen,
            $nsecBuf,
            $npubBuf,
            $kBuf
        );
        if (0 !== $rc) {
            throw new \Exception('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(): internal error');
        }

        return self::unsignedCharArrayToString($cBuf, $clen);
    }

    /**
     * @return string|false
     */
    private static function ffiAeadXchacha20poly1305IetfDecrypt(
        string $ciphertext,
        string $additionalData,
        string $nonce,
        string $key
    ): string|false {
        $ffi = self::requireFfi();
        self::validateAeadKeyNonce($key, $nonce, 'sodium_crypto_aead_xchacha20poly1305_ietf_decrypt', 3, 4);
        $clen = \strlen($ciphertext);
        if ($clen < self::CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES) {
            return false;
        }
        $mlen = $clen - self::CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES;
        $mBuf = $ffi->new('unsigned char['.$mlen.']');
        $mlenOut = $ffi->new('unsigned long long');
        $nsecBuf = $ffi->new('unsigned char[0]');
        $cBuf = self::stringToUnsignedCharArray($ffi, $ciphertext);
        $adBuf = self::stringToUnsignedCharArray($ffi, $additionalData);
        $adlen = \strlen($additionalData);
        $npubBuf = self::stringToUnsignedCharArray($ffi, $nonce);
        $kBuf = self::stringToUnsignedCharArray($ffi, $key);
        $rc = $ffi->crypto_aead_xchacha20poly1305_ietf_decrypt(
            $mBuf,
            $mlenOut,
            $nsecBuf,
            $cBuf,
            $clen,
            $adBuf,
            $adlen,
            $npubBuf,
            $kBuf
        );
        if (0 !== $rc) {
            return false;
        }

        return self::unsignedCharArrayToString($mBuf, $mlen);
    }

    private static function ffiAeadChacha20poly1305Encrypt(
        string $message,
        string $additionalData,
        string $nonce,
        string $key
    ): string {
        $ffi = self::requireFfi();
        self::validateAeadChacha20poly1305KeyNonce($key, $nonce, 'sodium_crypto_aead_chacha20poly1305_encrypt', 3, 4);
        $mlen = \strlen($message);
        $adlen = \strlen($additionalData);
        $clen = $mlen + self::CRYPTO_AEAD_CHACHA20POLY1305_ABYTES;
        $cBuf = $ffi->new('unsigned char['.$clen.']');
        $clenOut = $ffi->new('unsigned long long');
        $mBuf = self::stringToUnsignedCharArray($ffi, $message);
        $adBuf = self::stringToUnsignedCharArray($ffi, $additionalData);
        $nsecBuf = $ffi->new('unsigned char[0]');
        $npubBuf = self::stringToUnsignedCharArray($ffi, $nonce);
        $kBuf = self::stringToUnsignedCharArray($ffi, $key);
        $rc = $ffi->crypto_aead_chacha20poly1305_encrypt(
            $cBuf,
            $clenOut,
            $mBuf,
            $mlen,
            $adBuf,
            $adlen,
            $nsecBuf,
            $npubBuf,
            $kBuf
        );
        if (0 !== $rc) {
            throw new \Exception('sodium_crypto_aead_chacha20poly1305_encrypt(): internal error');
        }

        return self::unsignedCharArrayToString($cBuf, $clen);
    }

    /**
     * @return string|false
     */
    private static function ffiAeadChacha20poly1305Decrypt(
        string $ciphertext,
        string $additionalData,
        string $nonce,
        string $key
    ): string|false {
        $ffi = self::requireFfi();
        self::validateAeadChacha20poly1305KeyNonce($key, $nonce, 'sodium_crypto_aead_chacha20poly1305_decrypt', 3, 4);
        $clen = \strlen($ciphertext);
        if ($clen < self::CRYPTO_AEAD_CHACHA20POLY1305_ABYTES) {
            return false;
        }
        $mlen = $clen - self::CRYPTO_AEAD_CHACHA20POLY1305_ABYTES;
        $mBuf = $ffi->new('unsigned char['.$mlen.']');
        $mlenOut = $ffi->new('unsigned long long');
        $nsecBuf = $ffi->new('unsigned char[0]');
        $cBuf = self::stringToUnsignedCharArray($ffi, $ciphertext);
        $adBuf = self::stringToUnsignedCharArray($ffi, $additionalData);
        $adlen = \strlen($additionalData);
        $npubBuf = self::stringToUnsignedCharArray($ffi, $nonce);
        $kBuf = self::stringToUnsignedCharArray($ffi, $key);
        $rc = $ffi->crypto_aead_chacha20poly1305_decrypt(
            $mBuf,
            $mlenOut,
            $nsecBuf,
            $cBuf,
            $clen,
            $adBuf,
            $adlen,
            $npubBuf,
            $kBuf
        );
        if (0 !== $rc) {
            return false;
        }

        return self::unsignedCharArrayToString($mBuf, $mlen);
    }

    private static function ffiAeadChacha20poly1305IetfEncrypt(
        string $message,
        string $additionalData,
        string $nonce,
        string $key
    ): string {
        $ffi = self::requireFfi();
        self::validateAeadChacha20poly1305IetfKeyNonce($key, $nonce, 'sodium_crypto_aead_chacha20poly1305_ietf_encrypt', 3, 4);
        $mlen = \strlen($message);
        $adlen = \strlen($additionalData);
        $clen = $mlen + self::CRYPTO_AEAD_CHACHA20POLY1305_IETF_ABYTES;
        $cBuf = $ffi->new('unsigned char['.$clen.']');
        $clenOut = $ffi->new('unsigned long long');
        $mBuf = self::stringToUnsignedCharArray($ffi, $message);
        $adBuf = self::stringToUnsignedCharArray($ffi, $additionalData);
        $nsecBuf = $ffi->new('unsigned char[0]');
        $npubBuf = self::stringToUnsignedCharArray($ffi, $nonce);
        $kBuf = self::stringToUnsignedCharArray($ffi, $key);
        $rc = $ffi->crypto_aead_chacha20poly1305_ietf_encrypt(
            $cBuf,
            $clenOut,
            $mBuf,
            $mlen,
            $adBuf,
            $adlen,
            $nsecBuf,
            $npubBuf,
            $kBuf
        );
        if (0 !== $rc) {
            throw new \Exception('sodium_crypto_aead_chacha20poly1305_ietf_encrypt(): internal error');
        }

        return self::unsignedCharArrayToString($cBuf, $clen);
    }

    /**
     * @return string|false
     */
    private static function ffiAeadChacha20poly1305IetfDecrypt(
        string $ciphertext,
        string $additionalData,
        string $nonce,
        string $key
    ): string|false {
        $ffi = self::requireFfi();
        self::validateAeadChacha20poly1305IetfKeyNonce($key, $nonce, 'sodium_crypto_aead_chacha20poly1305_ietf_decrypt', 3, 4);
        $clen = \strlen($ciphertext);
        if ($clen < self::CRYPTO_AEAD_CHACHA20POLY1305_IETF_ABYTES) {
            return false;
        }
        $mlen = $clen - self::CRYPTO_AEAD_CHACHA20POLY1305_IETF_ABYTES;
        $mBuf = $ffi->new('unsigned char['.$mlen.']');
        $mlenOut = $ffi->new('unsigned long long');
        $nsecBuf = $ffi->new('unsigned char[0]');
        $cBuf = self::stringToUnsignedCharArray($ffi, $ciphertext);
        $adBuf = self::stringToUnsignedCharArray($ffi, $additionalData);
        $adlen = \strlen($additionalData);
        $npubBuf = self::stringToUnsignedCharArray($ffi, $nonce);
        $kBuf = self::stringToUnsignedCharArray($ffi, $key);
        $rc = $ffi->crypto_aead_chacha20poly1305_ietf_decrypt(
            $mBuf,
            $mlenOut,
            $nsecBuf,
            $cBuf,
            $clen,
            $adBuf,
            $adlen,
            $npubBuf,
            $kBuf
        );
        if (0 !== $rc) {
            return false;
        }

        return self::unsignedCharArrayToString($mBuf, $mlen);
    }

    private static function ffiAeadAes256gcmEncrypt(
        string $message,
        string $additionalData,
        string $nonce,
        string $key
    ): string {
        $ffi = self::requireFfi();
        self::validateAeadAes256gcmKeyNonce($key, $nonce, 'sodium_crypto_aead_aes256gcm_encrypt', 3, 4);
        $mlen = \strlen($message);
        $adlen = \strlen($additionalData);
        $clen = $mlen + self::CRYPTO_AEAD_AES256GCM_ABYTES;
        $cBuf = $ffi->new('unsigned char['.$clen.']');
        $clenOut = $ffi->new('unsigned long long');
        $mBuf = self::stringToUnsignedCharArray($ffi, $message);
        $adBuf = self::stringToUnsignedCharArray($ffi, $additionalData);
        $nsecBuf = $ffi->new('unsigned char[0]');
        $npubBuf = self::stringToUnsignedCharArray($ffi, $nonce);
        $kBuf = self::stringToUnsignedCharArray($ffi, $key);
        $rc = $ffi->crypto_aead_aes256gcm_encrypt(
            $cBuf,
            $clenOut,
            $mBuf,
            $mlen,
            $adBuf,
            $adlen,
            $nsecBuf,
            $npubBuf,
            $kBuf
        );
        if (0 !== $rc) {
            throw new \Exception('sodium_crypto_aead_aes256gcm_encrypt(): internal error');
        }

        return self::unsignedCharArrayToString($cBuf, $clen);
    }

    /**
     * @return string|false
     */
    private static function ffiAeadAes256gcmDecrypt(
        string $ciphertext,
        string $additionalData,
        string $nonce,
        string $key
    ): string|false {
        $ffi = self::requireFfi();
        self::validateAeadAes256gcmKeyNonce($key, $nonce, 'sodium_crypto_aead_aes256gcm_decrypt', 3, 4);
        $clen = \strlen($ciphertext);
        if ($clen < self::CRYPTO_AEAD_AES256GCM_ABYTES) {
            return false;
        }
        $mlen = $clen - self::CRYPTO_AEAD_AES256GCM_ABYTES;
        $mBuf = $ffi->new('unsigned char['.$mlen.']');
        $mlenOut = $ffi->new('unsigned long long');
        $nsecBuf = $ffi->new('unsigned char[0]');
        $cBuf = self::stringToUnsignedCharArray($ffi, $ciphertext);
        $adBuf = self::stringToUnsignedCharArray($ffi, $additionalData);
        $adlen = \strlen($additionalData);
        $npubBuf = self::stringToUnsignedCharArray($ffi, $nonce);
        $kBuf = self::stringToUnsignedCharArray($ffi, $key);
        $rc = $ffi->crypto_aead_aes256gcm_decrypt(
            $mBuf,
            $mlenOut,
            $nsecBuf,
            $cBuf,
            $clen,
            $adBuf,
            $adlen,
            $npubBuf,
            $kBuf
        );
        if (0 !== $rc) {
            return false;
        }

        return self::unsignedCharArrayToString($mBuf, $mlen);
    }

    private static function ffiSignKeypair(): string
    {
        $ffi = self::requireFfi();
        $pkBuf = $ffi->new('unsigned char['.self::CRYPTO_SIGN_PUBLICKEYBYTES.']');
        $skBuf = $ffi->new('unsigned char['.self::CRYPTO_SIGN_SECRETKEYBYTES.']');
        $rc = $ffi->crypto_sign_keypair($pkBuf, $skBuf);
        if (0 !== $rc) {
            self::throwSodium('internal error');
        }

        return self::unsignedCharArrayToString($skBuf, self::CRYPTO_SIGN_SECRETKEYBYTES)
            .self::unsignedCharArrayToString($pkBuf, self::CRYPTO_SIGN_PUBLICKEYBYTES);
    }

    private static function ffiSignPublickeyFromSecretkey(string $secretkey): string
    {
        $ffi = self::requireFfi();
        $pkBuf = $ffi->new('unsigned char['.self::CRYPTO_SIGN_PUBLICKEYBYTES.']');
        $skBuf = self::stringToUnsignedCharArray($ffi, $secretkey);
        $rc = $ffi->crypto_sign_ed25519_sk_to_pk($pkBuf, $skBuf);
        if (0 !== $rc) {
            self::throwSodium('internal error');
        }

        return self::unsignedCharArrayToString($pkBuf, self::CRYPTO_SIGN_PUBLICKEYBYTES);
    }

    private static function ffiSign(string $message, string $secretkey): string
    {
        $ffi = self::requireFfi();
        $mlen = \strlen($message);
        $smlen = $mlen + self::CRYPTO_SIGN_BYTES;
        $smBuf = $ffi->new('unsigned char['.$smlen.']');
        $smlenOut = $ffi->new('unsigned long long');
        $mBuf = self::stringToUnsignedCharArray($ffi, $message);
        $skBuf = self::stringToUnsignedCharArray($ffi, $secretkey);
        $rc = $ffi->crypto_sign($smBuf, $smlenOut, $mBuf, $mlen, $skBuf);
        if (0 !== $rc) {
            self::throwSodium('internal error');
        }

        return self::unsignedCharArrayToString($smBuf, $smlen);
    }

    /**
     * @return string|false
     */
    private static function ffiSignOpen(string $signedMessage, string $publickey): string|false
    {
        $ffi = self::requireFfi();
        $smlen = \strlen($signedMessage);
        if ($smlen < self::CRYPTO_SIGN_BYTES) {
            return false;
        }
        $mlen = $smlen - self::CRYPTO_SIGN_BYTES;
        $mBuf = $ffi->new('unsigned char['.$mlen.']');
        $mlenOut = $ffi->new('unsigned long long');
        $smBuf = self::stringToUnsignedCharArray($ffi, $signedMessage);
        $pkBuf = self::stringToUnsignedCharArray($ffi, $publickey);
        $rc = $ffi->crypto_sign_open($mBuf, $mlenOut, $smBuf, $smlen, $pkBuf);
        if (0 !== $rc) {
            return false;
        }

        return self::unsignedCharArrayToString($mBuf, $mlen);
    }

    private static function ffiSignDetached(string $message, string $secretkey): string
    {
        $ffi = self::requireFfi();
        $mlen = \strlen($message);
        $sigBuf = $ffi->new('unsigned char['.self::CRYPTO_SIGN_BYTES.']');
        $siglenOut = $ffi->new('unsigned long long');
        $mBuf = self::stringToUnsignedCharArray($ffi, $message);
        $skBuf = self::stringToUnsignedCharArray($ffi, $secretkey);
        $rc = $ffi->crypto_sign_detached($sigBuf, $siglenOut, $mBuf, $mlen, $skBuf);
        if (0 !== $rc) {
            self::throwSodium('internal error');
        }

        return self::unsignedCharArrayToString($sigBuf, self::CRYPTO_SIGN_BYTES);
    }

    private static function ffiSignVerifyDetached(string $signature, string $message, string $publickey): bool
    {
        $ffi = self::requireFfi();
        $mlen = \strlen($message);
        $sigBuf = self::stringToUnsignedCharArray($ffi, $signature);
        $mBuf = self::stringToUnsignedCharArray($ffi, $message);
        $pkBuf = self::stringToUnsignedCharArray($ffi, $publickey);
        $rc = $ffi->crypto_sign_verify_detached($sigBuf, $mBuf, $mlen, $pkBuf);

        return 0 === $rc;
    }

    private static function ffiSecretstreamKeygen(): string
    {
        $ffi = self::requireFfi();
        $kBuf = $ffi->new('unsigned char['.self::CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES.']');
        $ffi->crypto_secretstream_xchacha20poly1305_keygen($kBuf);

        return self::unsignedCharArrayToString($kBuf, self::CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function ffiSecretstreamInitPush(string $key): array
    {
        $ffi = self::requireFfi();
        $stateBuf = $ffi->new('unsigned char['.self::CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_STATEBYTES.']');
        $headerBuf = $ffi->new('unsigned char['.self::CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES.']');
        $kBuf = self::stringToUnsignedCharArray($ffi, $key);
        $rc = $ffi->crypto_secretstream_xchacha20poly1305_init_push($stateBuf, $headerBuf, $kBuf);
        if (0 !== $rc) {
            self::throwSodium('internal error');
        }

        return [
            self::unsignedCharArrayToString($stateBuf, self::CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_STATEBYTES),
            self::unsignedCharArrayToString($headerBuf, self::CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES),
        ];
    }

    private static function ffiSecretstreamInitPull(string $header, string $key): string
    {
        $ffi = self::requireFfi();
        $stateBuf = $ffi->new('unsigned char['.self::CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_STATEBYTES.']');
        $headerBuf = self::stringToUnsignedCharArray($ffi, $header);
        $kBuf = self::stringToUnsignedCharArray($ffi, $key);
        $rc = $ffi->crypto_secretstream_xchacha20poly1305_init_pull($stateBuf, $headerBuf, $kBuf);
        if (0 !== $rc) {
            self::throwSodium('internal error');
        }

        return self::unsignedCharArrayToString($stateBuf, self::CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_STATEBYTES);
    }

    private static function ffiSecretstreamPush(
        string &$state,
        string $message,
        string $additionalData,
        int $tag
    ): string {
        $ffi = self::requireFfi();
        $mlen = \strlen($message);
        $adlen = \strlen($additionalData);
        $clen = $mlen + self::CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES;
        $cBuf = $ffi->new('unsigned char['.$clen.']');
        $clenOut = $ffi->new('unsigned long long');
        $stateBuf = self::stringToUnsignedCharArray($ffi, $state);
        $mBuf = self::stringToUnsignedCharArray($ffi, $message);
        $adBuf = self::stringToUnsignedCharArray($ffi, $additionalData);
        $rc = $ffi->crypto_secretstream_xchacha20poly1305_push(
            $cBuf,
            $clenOut,
            $stateBuf,
            $mBuf,
            $mlen,
            $adBuf,
            $adlen,
            $tag
        );
        if (0 !== $rc) {
            self::throwSodium('internal error');
        }
        $state = self::unsignedCharArrayToString($stateBuf, self::CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_STATEBYTES);

        return self::unsignedCharArrayToString($cBuf, $clen);
    }

    /**
     * @return array{0: string, 1: int}|false
     */
    private static function ffiSecretstreamPull(
        string &$state,
        string $ciphertext,
        string $additionalData
    ): array|false {
        $ffi = self::requireFfi();
        $clen = \strlen($ciphertext);
        if ($clen < self::CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES) {
            return false;
        }
        $mlen = $clen - self::CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES;
        $mBuf = $ffi->new('unsigned char['.$mlen.']');
        $mlenOut = $ffi->new('unsigned long long');
        $tagOut = $ffi->new('unsigned char');
        $stateBuf = self::stringToUnsignedCharArray($ffi, $state);
        $cBuf = self::stringToUnsignedCharArray($ffi, $ciphertext);
        $adBuf = self::stringToUnsignedCharArray($ffi, $additionalData);
        $adlen = \strlen($additionalData);
        $rc = $ffi->crypto_secretstream_xchacha20poly1305_pull(
            $mBuf,
            $mlenOut,
            $tagOut,
            $stateBuf,
            $cBuf,
            $clen,
            $adBuf,
            $adlen
        );
        if (0 !== $rc) {
            return false;
        }
        $state = self::unsignedCharArrayToString($stateBuf, self::CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_STATEBYTES);

        return [
            self::unsignedCharArrayToString($mBuf, $mlen),
            (int) $tagOut[0],
        ];
    }

    private static function ffiSecretstreamRekey(string &$state): void
    {
        $ffi = self::requireFfi();
        $stateBuf = self::stringToUnsignedCharArray($ffi, $state);
        $rc = $ffi->crypto_secretstream_xchacha20poly1305_rekey($stateBuf);
        if (0 !== $rc) {
            self::throwSodium('internal error');
        }
        $state = self::unsignedCharArrayToString($stateBuf, self::CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_STATEBYTES);
    }

    private static function validateStreamLength(int $length, string $fn): void
    {
        if ($length <= 0) {
            self::throwSodium(\sprintf('%s(): Argument #1 ($length) must be greater than 0', $fn));
        }
    }

    private static function validateStreamKeyNonce(
        string $key,
        string $nonce,
        string $fn,
        int $nonceArg,
        int $keyArg
    ): void {
        if (\strlen($nonce) !== self::CRYPTO_STREAM_NONCEBYTES) {
            self::throwSodium(\sprintf(
                '%s(): Argument #%d ($nonce) must be SODIUM_CRYPTO_STREAM_NONCEBYTES bytes long',
                $fn,
                $nonceArg
            ));
        }
        if (\strlen($key) !== self::CRYPTO_STREAM_KEYBYTES) {
            self::throwSodium(\sprintf(
                '%s(): Argument #%d ($key) must be SODIUM_CRYPTO_STREAM_KEYBYTES bytes long',
                $fn,
                $keyArg
            ));
        }
    }

    private static function validateXchacha20KeyNonce(
        string $key,
        string $nonce,
        string $fn,
        int $nonceArg,
        int $keyArg
    ): void {
        if (\strlen($nonce) !== self::CRYPTO_STREAM_XCHACHA20_NONCEBYTES) {
            self::throwSodium(\sprintf(
                '%s(): Argument #%d ($nonce) must be SODIUM_CRYPTO_STREAM_XCHACHA20_NONCEBYTES bytes long',
                $fn,
                $nonceArg
            ));
        }
        if (\strlen($key) !== self::CRYPTO_STREAM_XCHACHA20_KEYBYTES) {
            self::throwSodium(\sprintf(
                '%s(): Argument #%d ($key) must be SODIUM_CRYPTO_STREAM_XCHACHA20_KEYBYTES bytes long',
                $fn,
                $keyArg
            ));
        }
    }

    private static function validateAeadKeyNonce(
        string $key,
        string $nonce,
        string $fn,
        int $nonceArg,
        int $keyArg
    ): void {
        if (\strlen($nonce) !== self::CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES) {
            self::throwSodium(\sprintf(
                '%s(): Argument #%d ($nonce) must be SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES bytes long',
                $fn,
                $nonceArg
            ));
        }
        if (\strlen($key) !== self::CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
            self::throwSodium(\sprintf(
                '%s(): Argument #%d ($key) must be SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES bytes long',
                $fn,
                $keyArg
            ));
        }
    }

    private static function validateAeadChacha20poly1305KeyNonce(
        string $key,
        string $nonce,
        string $fn,
        int $nonceArg,
        int $keyArg
    ): void {
        if (\strlen($nonce) !== self::CRYPTO_AEAD_CHACHA20POLY1305_NPUBBYTES) {
            self::throwSodium(\sprintf(
                '%s(): Argument #%d ($nonce) must be SODIUM_CRYPTO_AEAD_CHACHA20POLY1305_NPUBBYTES bytes long',
                $fn,
                $nonceArg
            ));
        }
        if (\strlen($key) !== self::CRYPTO_AEAD_CHACHA20POLY1305_KEYBYTES) {
            self::throwSodium(\sprintf(
                '%s(): Argument #%d ($key) must be SODIUM_CRYPTO_AEAD_CHACHA20POLY1305_KEYBYTES bytes long',
                $fn,
                $keyArg
            ));
        }
    }

    private static function validateAeadChacha20poly1305IetfKeyNonce(
        string $key,
        string $nonce,
        string $fn,
        int $nonceArg,
        int $keyArg
    ): void {
        if (\strlen($nonce) !== self::CRYPTO_AEAD_CHACHA20POLY1305_IETF_NPUBBYTES) {
            self::throwSodium(\sprintf(
                '%s(): Argument #%d ($nonce) must be SODIUM_CRYPTO_AEAD_CHACHA20POLY1305_IETF_NPUBBYTES bytes long',
                $fn,
                $nonceArg
            ));
        }
        if (\strlen($key) !== self::CRYPTO_AEAD_CHACHA20POLY1305_IETF_KEYBYTES) {
            self::throwSodium(\sprintf(
                '%s(): Argument #%d ($key) must be SODIUM_CRYPTO_AEAD_CHACHA20POLY1305_IETF_KEYBYTES bytes long',
                $fn,
                $keyArg
            ));
        }
    }

    private static function validateAeadAes256gcmKeyNonce(
        string $key,
        string $nonce,
        string $fn,
        int $nonceArg,
        int $keyArg
    ): void {
        if (\strlen($nonce) !== self::CRYPTO_AEAD_AES256GCM_NPUBBYTES) {
            self::throwSodium(\sprintf(
                '%s(): Argument #%d ($nonce) must be SODIUM_CRYPTO_AEAD_AES256GCM_NPUBBYTES bytes long',
                $fn,
                $nonceArg
            ));
        }
        if (\strlen($key) !== self::CRYPTO_AEAD_AES256GCM_KEYBYTES) {
            self::throwSodium(\sprintf(
                '%s(): Argument #%d ($key) must be SODIUM_CRYPTO_AEAD_AES256GCM_KEYBYTES bytes long',
                $fn,
                $keyArg
            ));
        }
    }

    private static function validateSignKeypair(string $keypair, string $fn, int $argNum = 1): void
    {
        if (\strlen($keypair) !== self::CRYPTO_SIGN_KEYPAIRBYTES) {
            self::throwSodium(\sprintf(
                '%s(): Argument #%d ($keypair) must be SODIUM_CRYPTO_SIGN_KEYPAIRBYTES bytes long',
                $fn,
                $argNum
            ));
        }
    }

    private static function validateSignSecretkey(string $secretkey, string $fn, int $argNum = 1): void
    {
        if (\strlen($secretkey) !== self::CRYPTO_SIGN_SECRETKEYBYTES) {
            self::throwSodium(\sprintf(
                '%s(): Argument #%d ($secret_key) must be SODIUM_CRYPTO_SIGN_SECRETKEYBYTES bytes long',
                $fn,
                $argNum
            ));
        }
    }

    private static function validateSecretstreamKey(string $key, string $fn, int $argNum = 1): void
    {
        if (\strlen($key) !== self::CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES) {
            self::throwSodium(\sprintf(
                '%s(): Argument #%d ($key) must be SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES bytes long',
                $fn,
                $argNum
            ));
        }
    }

    private static function validateSecretstreamState(string $state, string $fn): void
    {
        if (\strlen($state) !== self::CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_STATEBYTES) {
            self::throwSodium(\sprintf(
                '%s(): Argument #1 ($state) must be a reference to a state',
                $fn
            ));
        }
    }

    private static function validateGenerichashLength(int $length): void
    {
        if ($length < self::CRYPTO_GENERICHASH_BYTES_MIN || $length > self::CRYPTO_GENERICHASH_BYTES_MAX) {
            self::throwSodium('unsupported output length');
        }
    }

    private static function validateGenerichashKey(string $key): void
    {
        $keyLen = \strlen($key);
        if (0 !== $keyLen
            && ($keyLen < self::CRYPTO_GENERICHASH_KEYBYTES_MIN || $keyLen > self::CRYPTO_GENERICHASH_KEYBYTES_MAX)
        ) {
            self::throwSodium('unsupported key length');
        }
    }

    private static function validateGenerichashState(string $state): void
    {
        if (\strlen($state) !== self::CRYPTO_GENERICHASH_STATEBYTES) {
            self::throwSodium('incorrect state length');
        }
    }

    private static function randomKeyBytes(int $length): string
    {
        if (\function_exists('random_bytes')) {
            return \random_bytes($length);
        }

        throw new \LogicException('libsodium is not available in this compiler build');
    }

    private static function validateKeyNonce(string $key, string $nonce): void
    {
        if (\strlen($key) !== self::CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \Exception('sodium_crypto_secretbox(): invalid key size');
        }
        if (\strlen($nonce) !== self::CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \Exception('sodium_crypto_secretbox(): invalid nonce size');
        }
    }

    private static function validateAuthKey(string $key, string $fn, int $argNum = 2): void
    {
        if (\strlen($key) !== self::CRYPTO_AUTH_KEYBYTES) {
            self::throwSodium(\sprintf(
                '%s(): Argument #%d ($key) must be SODIUM_CRYPTO_AUTH_KEYBYTES bytes long',
                $fn,
                $argNum
            ));
        }
    }

    private static function validateBoxKeypair(string $keypair, string $fn, int $argNum = 1): void
    {
        if (\strlen($keypair) !== self::CRYPTO_BOX_KEYPAIRBYTES) {
            self::throwSodium(\sprintf(
                '%s(): Argument #%d ($key_pair) must be SODIUM_CRYPTO_BOX_KEYPAIRBYTES bytes long',
                $fn,
                $argNum
            ));
        }
    }

    private static function validateBoxNonce(string $nonce, string $fn, int $argNum): void
    {
        if (\strlen($nonce) !== self::CRYPTO_BOX_NONCEBYTES) {
            self::throwSodium(\sprintf(
                '%s(): Argument #%d ($nonce) must be SODIUM_CRYPTO_BOX_NONCEBYTES bytes long',
                $fn,
                $argNum
            ));
        }
    }

    private static function validateBoxSecretkey(string $secretkey, string $fn, int $argNum): void
    {
        if (\strlen($secretkey) !== self::CRYPTO_BOX_SECRETKEYBYTES) {
            self::throwSodium(\sprintf(
                '%s(): Argument #%d ($secret_key) must be SODIUM_CRYPTO_BOX_SECRETKEYBYTES bytes long',
                $fn,
                $argNum
            ));
        }
    }

    private static function validateBoxPublickey(string $publickey, string $fn, int $argNum): void
    {
        if (\strlen($publickey) !== self::CRYPTO_BOX_PUBLICKEYBYTES) {
            self::throwSodium(\sprintf(
                '%s(): Argument #%d ($public_key) must be SODIUM_CRYPTO_BOX_PUBLICKEYBYTES bytes long',
                $fn,
                $argNum
            ));
        }
    }

    private static function throwSodium(string $message): void
    {
        if (\class_exists(\SodiumException::class, false)) {
            throw new \SodiumException($message);
        }
        throw new \Exception($message);
    }

    private static function requireFfi(): \FFI
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            throw new \LogicException('libsodium is not available in this compiler build');
        }

        return $ffi;
    }

    private static function ffi(): ?\FFI
    {
        if (self::$ffiUnavailable) {
            return null;
        }
        if (null !== self::$ffi) {
            return self::$ffi;
        }
        if (!\extension_loaded('ffi')) {
            self::$ffiUnavailable = true;

            return null;
        }
        foreach (['libsodium.so.23', 'libsodium.so'] as $lib) {
            try {
                $ffi = \FFI::cdef(
                    'int sodium_init(void);
                    int crypto_secretbox(unsigned char *c, const unsigned char *m, unsigned long long mlen, const unsigned char *n, const unsigned char *k);
                    int crypto_secretbox_open(unsigned char *m, const unsigned char *c, unsigned long long clen, const unsigned char *n, const unsigned char *k);
                    int crypto_auth(unsigned char *out, const unsigned char *in, unsigned long long inlen, const unsigned char *k);
                    int crypto_auth_verify(const unsigned char *h, const unsigned char *in, unsigned long long inlen, const unsigned char *k);
                    int crypto_shorthash(unsigned char *out, const unsigned char *in, unsigned long long inlen, const unsigned char *k);
                    int crypto_kdf_derive_from_key(unsigned char *subkey, size_t subkey_len, uint64_t subkey_id, const char *ctx, const unsigned char *key);
                    int crypto_stream(unsigned char *c, unsigned long long clen, const unsigned char *n, const unsigned char *k);
                    int crypto_stream_xor(unsigned char *c, const unsigned char *m, unsigned long long mlen, const unsigned char *n, const unsigned char *k);
                    int crypto_stream_xchacha20(unsigned char *c, unsigned long long clen, const unsigned char *n, const unsigned char *k);
                    int crypto_stream_xchacha20_xor(unsigned char *c, const unsigned char *m, unsigned long long mlen, const unsigned char *n, const unsigned char *k);
                    int crypto_stream_xchacha20_xor_ic(unsigned char *c, const unsigned char *m, unsigned long long mlen, const unsigned char *n, const unsigned char k[32], unsigned long long ic);
                    int crypto_aead_xchacha20poly1305_ietf_encrypt(unsigned char *c, unsigned long long *clen_p, const unsigned char *m, unsigned long long mlen, const unsigned char *ad, unsigned long long adlen, const unsigned char *nsec, const unsigned char *npub, const unsigned char *k);
                    int crypto_aead_xchacha20poly1305_ietf_decrypt(unsigned char *m, unsigned long long *mlen_p, unsigned char *nsec, const unsigned char *c, unsigned long long clen, const unsigned char *ad, unsigned long long adlen, const unsigned char *npub, const unsigned char *k);
                    int crypto_aead_chacha20poly1305_encrypt(unsigned char *c, unsigned long long *clen_p, const unsigned char *m, unsigned long long mlen, const unsigned char *ad, unsigned long long adlen, const unsigned char *nsec, const unsigned char *npub, const unsigned char *k);
                    int crypto_aead_chacha20poly1305_decrypt(unsigned char *m, unsigned long long *mlen_p, unsigned char *nsec, const unsigned char *c, unsigned long long clen, const unsigned char *ad, unsigned long long adlen, const unsigned char *npub, const unsigned char *k);
                    int crypto_aead_chacha20poly1305_ietf_encrypt(unsigned char *c, unsigned long long *clen_p, const unsigned char *m, unsigned long long mlen, const unsigned char *ad, unsigned long long adlen, const unsigned char *nsec, const unsigned char *npub, const unsigned char *k);
                    int crypto_aead_chacha20poly1305_ietf_decrypt(unsigned char *m, unsigned long long *mlen_p, unsigned char *nsec, const unsigned char *c, unsigned long long clen, const unsigned char *ad, unsigned long long adlen, const unsigned char *npub, const unsigned char *k);
                    int sodium_memcmp(const unsigned char *s1, const unsigned char *s2, size_t len);
                    void sodium_increment(unsigned char *n, size_t nlen);
                    void sodium_add(unsigned char *a, const unsigned char *b, size_t len);
                    int sodium_compare(const unsigned char *b1, const unsigned char *b2, size_t len);
                    char *sodium_bin2hex(char *hex, size_t hex_maxlen, const unsigned char *bin, size_t bin_len);
                    void sodium_memzero(void *pnt, size_t len);
                    int sodium_pad(size_t *unpadded_buf_len_p, unsigned char *buf, size_t unpadded_buf_len, size_t blocksize);
                    int sodium_unpad(size_t *unpadded_buf_len_p, const unsigned char *buf, size_t padded_buf_len, size_t blocksize);
                    int crypto_generichash(unsigned char *out, size_t outlen, const unsigned char *in, unsigned long long inlen, const unsigned char *key, size_t keylen);
                    int crypto_generichash_init(unsigned char *state, const unsigned char *key, const size_t keylen, const size_t outlen);
                    int crypto_generichash_update(unsigned char *state, const unsigned char *in, unsigned long long inlen);
                    int crypto_generichash_final(unsigned char *state, unsigned char *out, const size_t outlen);
                    void crypto_generichash_keygen(unsigned char k[32]);
                    int crypto_scalarmult(unsigned char *q, const unsigned char *n, const unsigned char *p);
                    int crypto_scalarmult_base(unsigned char *q, const unsigned char *n);
                    int crypto_box_keypair(unsigned char *pk, unsigned char *sk);
                    int crypto_box_easy(unsigned char *c, const unsigned char *m, unsigned long long mlen, const unsigned char *n, const unsigned char *pk, const unsigned char *sk);
                    int crypto_box_open_easy(unsigned char *m, const unsigned char *c, unsigned long long clen, const unsigned char *n, const unsigned char *pk, const unsigned char *sk);
                    int crypto_box_seal(unsigned char *c, const unsigned char *m, unsigned long long mlen, const unsigned char *pk);
                    int crypto_box_seal_open(unsigned char *m, const unsigned char *c, unsigned long long clen, const unsigned char *pk, const unsigned char *sk);
                    int crypto_aead_aes256gcm_is_available(void);
                    int crypto_aead_aes256gcm_encrypt(unsigned char *c, unsigned long long *clen_p, const unsigned char *m, unsigned long long mlen, const unsigned char *ad, unsigned long long adlen, const unsigned char *nsec, const unsigned char *npub, const unsigned char *k);
                    int crypto_aead_aes256gcm_decrypt(unsigned char *m, unsigned long long *mlen_p, unsigned char *nsec, const unsigned char *c, unsigned long long clen, const unsigned char *ad, unsigned long long adlen, const unsigned char *npub, const unsigned char *k);
                    int crypto_sign_keypair(unsigned char *pk, unsigned char *sk);
                    int crypto_sign_ed25519_sk_to_pk(unsigned char *pk, const unsigned char *sk);
                    int crypto_sign(unsigned char *sm, unsigned long long *smlen_p, const unsigned char *m, unsigned long long mlen, const unsigned char *sk);
                    int crypto_sign_open(unsigned char *m, unsigned long long *mlen_p, const unsigned char *sm, unsigned long long smlen, const unsigned char *pk);
                    int crypto_sign_detached(unsigned char *sig, unsigned long long *siglen_p, const unsigned char *m, unsigned long long mlen, const unsigned char *sk);
                    int crypto_sign_verify_detached(const unsigned char *sig, const unsigned char *m, unsigned long long mlen, const unsigned char *pk);
                    void crypto_secretstream_xchacha20poly1305_keygen(unsigned char k[32]);
                    int crypto_secretstream_xchacha20poly1305_init_push(unsigned char *state, unsigned char *header, const unsigned char k[32]);
                    int crypto_secretstream_xchacha20poly1305_init_pull(unsigned char *state, const unsigned char *header, const unsigned char k[32]);
                    int crypto_secretstream_xchacha20poly1305_push(unsigned char *c, unsigned long long *clen_p, unsigned char *state, const unsigned char *m, unsigned long long mlen, const unsigned char *ad, unsigned long long adlen, unsigned char tag);
                    int crypto_secretstream_xchacha20poly1305_pull(unsigned char *m, unsigned long long *mlen_p, unsigned char *tag, unsigned char *state, const unsigned char *c, unsigned long long clen, const unsigned char *ad, unsigned long long adlen);
                    int crypto_secretstream_xchacha20poly1305_rekey(unsigned char *state);',
                    $lib
                );
                $ffi->sodium_init();
                self::$ffi = $ffi;

                return self::$ffi;
            } catch (\Throwable) {
                continue;
            }
        }
        self::$ffiUnavailable = true;

        return null;
    }

    /**
     * @param \FFI\CData $buf
     */
    private static function stringToUnsignedCharArray(\FFI $ffi, string $value): \FFI\CData
    {
        $len = \strlen($value);
        $buf = $ffi->new('unsigned char['.$len.']');
        for ($i = 0; $i < $len; ++$i) {
            $buf[$i] = \ord($value[$i]);
        }

        return $buf;
    }

    /**
     * @param \FFI\CData $buf
     */
    private static function unsignedCharArrayToString(\FFI\CData $buf, int $len): string
    {
        $out = '';
        for ($i = 0; $i < $len; ++$i) {
            $out .= \chr((int) $buf[$i]);
        }

        return $out;
    }
}
