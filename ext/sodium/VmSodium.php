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

    /** libsodium crypto_generichash_KEYBYTES_MAX (blake2b key up to 64; #24110). */
    public const CRYPTO_GENERICHASH_KEYBYTES_MAX = 64;

    /** Opaque BLAKE2b state size (libsodium crypto_generichash_statebytes(); #20062). */
    public const CRYPTO_GENERICHASH_STATEBYTES = 384;

    public const CRYPTO_SCALARMULT_BYTES = 32;

    public const CRYPTO_SCALARMULT_SCALARBYTES = 32;

    /** Ristretto255 core + scalarmult (php-src ext/sodium; #20084). */
    public const CRYPTO_CORE_RISTRETTO255_BYTES = 32;

    public const CRYPTO_CORE_RISTRETTO255_HASHBYTES = 64;

    public const CRYPTO_CORE_RISTRETTO255_SCALARBYTES = 32;

    public const CRYPTO_CORE_RISTRETTO255_NONREDUCEDSCALARBYTES = 64;

    public const CRYPTO_SCALARMULT_RISTRETTO255_BYTES = 32;

    public const CRYPTO_SCALARMULT_RISTRETTO255_SCALARBYTES = 32;

    public const CRYPTO_BOX_SECRETKEYBYTES = 32;

    public const CRYPTO_BOX_PUBLICKEYBYTES = 32;

    public const CRYPTO_BOX_KEYPAIRBYTES = 64;

    public const CRYPTO_BOX_SEEDBYTES = 32;

    public const CRYPTO_BOX_MACBYTES = 16;

    public const CRYPTO_BOX_NONCEBYTES = 24;

    public const CRYPTO_BOX_SEALBYTES = 48;

    public const CRYPTO_KX_PUBLICKEYBYTES = 32;

    public const CRYPTO_KX_SECRETKEYBYTES = 32;

    public const CRYPTO_KX_KEYPAIRBYTES = 64;

    public const CRYPTO_KX_SEEDBYTES = 32;

    public const CRYPTO_KX_SESSIONKEYBYTES = 32;

    public const CRYPTO_AEAD_AES256GCM_KEYBYTES = 32;

    public const CRYPTO_AEAD_AES256GCM_NPUBBYTES = 12;

    public const CRYPTO_AEAD_AES256GCM_NSECRETBYTES = 0;

    public const CRYPTO_AEAD_AES256GCM_ABYTES = 16;

    /** AEGIS-128L (libsodium ≥ 1.0.19; php-src PHP 8.4 #ifdef crypto_aead_aegis128l_KEYBYTES; #20518). */
    public const CRYPTO_AEAD_AEGIS128L_KEYBYTES = 16;

    public const CRYPTO_AEAD_AEGIS128L_NPUBBYTES = 16;

    public const CRYPTO_AEAD_AEGIS128L_NSECRETBYTES = 0;

    public const CRYPTO_AEAD_AEGIS128L_ABYTES = 32;

    /** AEGIS-256 (libsodium ≥ 1.0.19; php-src PHP 8.4 #ifdef crypto_aead_aegis256_KEYBYTES; #20518). */
    public const CRYPTO_AEAD_AEGIS256_KEYBYTES = 32;

    public const CRYPTO_AEAD_AEGIS256_NPUBBYTES = 32;

    public const CRYPTO_AEAD_AEGIS256_NSECRETBYTES = 0;

    public const CRYPTO_AEAD_AEGIS256_ABYTES = 32;

    public const CRYPTO_SIGN_PUBLICKEYBYTES = 32;

    public const CRYPTO_SIGN_SECRETKEYBYTES = 64;

    public const CRYPTO_SIGN_KEYPAIRBYTES = 96;

    public const CRYPTO_SIGN_BYTES = 64;

    public const CRYPTO_SIGN_SEEDBYTES = 32;

    public const CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES = 32;

    public const CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES = 24;

    public const CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES = 17;

    public const CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_STATEBYTES = 52;

    /** libsodium crypto_secretstream_xchacha20poly1305_MESSAGEBYTES_MAX (#24110). */
    public const CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_MESSAGEBYTES_MAX = 274877906816;

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

    /** Argon2 password hashing (php-src crypto_pwhash_*; #20048). */
    public const CRYPTO_PWHASH_ALG_ARGON2I13 = 1;

    public const CRYPTO_PWHASH_ALG_ARGON2ID13 = 2;

    public const CRYPTO_PWHASH_ALG_DEFAULT = 2;

    public const CRYPTO_PWHASH_SALTBYTES = 16;

    public const CRYPTO_PWHASH_STRPREFIX = '$argon2id$';

    public const CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE = 2;

    public const CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE = 67108864;

    public const CRYPTO_PWHASH_OPSLIMIT_MODERATE = 3;

    public const CRYPTO_PWHASH_MEMLIMIT_MODERATE = 268435456;

    public const CRYPTO_PWHASH_OPSLIMIT_SENSITIVE = 4;

    public const CRYPTO_PWHASH_MEMLIMIT_SENSITIVE = 1073741824;

    /** scrypt password hashing (php-src crypto_pwhash_scryptsalsa208sha256_*; #21460). */
    public const CRYPTO_PWHASH_SCRYPTSALSA208SHA256_SALTBYTES = 32;

    public const CRYPTO_PWHASH_SCRYPTSALSA208SHA256_STRPREFIX = '$7$';

    public const CRYPTO_PWHASH_SCRYPTSALSA208SHA256_OPSLIMIT_INTERACTIVE = 524288;

    public const CRYPTO_PWHASH_SCRYPTSALSA208SHA256_MEMLIMIT_INTERACTIVE = 16777216;

    public const CRYPTO_PWHASH_SCRYPTSALSA208SHA256_OPSLIMIT_SENSITIVE = 33554432;

    public const CRYPTO_PWHASH_SCRYPTSALSA208SHA256_MEMLIMIT_SENSITIVE = 1073741824;

    /** sodium_base64_VARIANT_* (libsodium helpers.h; #20675). */
    public const BASE64_VARIANT_ORIGINAL = 1;

    public const BASE64_VARIANT_ORIGINAL_NO_PADDING = 3;

    public const BASE64_VARIANT_URLSAFE = 5;

    public const BASE64_VARIANT_URLSAFE_NO_PADDING = 7;

    /** Internal: crypto_pwhash_STRBYTES / OPSLIMIT_MIN / MEMLIMIT_MIN (not advertised as SODIUM_*). */
    private const CRYPTO_PWHASH_STRBYTES = 128;

    private const CRYPTO_PWHASH_OPSLIMIT_MIN = 1;

    private const CRYPTO_PWHASH_MEMLIMIT_MIN = 8192;

    /** Internal: crypto_pwhash_scryptsalsa208sha256_STRBYTES (libsodium; not advertised). */
    private const CRYPTO_PWHASH_SCRYPTSALSA208SHA256_STRBYTES = 102;

    private static ?\FFI $ffi = null;

    private static bool $ffiUnavailable = false;

    /** Separate FFI for sodium_version_* — keep core cdef free of version symbols (#24069). */
    private static ?\FFI $ffiVersion = null;

    private static bool $ffiVersionUnavailable = false;

    /** Separate FFI for AEGIS — must not share the core cdef (missing symbols would break load; #20518). */
    private static ?\FFI $ffiAegis = null;

    private static bool $ffiAegisUnavailable = false;

    private static ?bool $aegis128lAvailable = null;

    private static ?bool $aegis256Available = null;

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
            || \function_exists('sodium_crypto_pwhash')
            || \function_exists('sodium_crypto_pwhash_scryptsalsa208sha256')
            || null !== self::ffi();
    }

    /**
     * SODIUM_LIBRARY_VERSION / MAJOR / MINOR from the linked libsodium (php-src #24069).
     *
     * Prefer host ext/sodium constants when present; otherwise probe via FFI.
     *
     * @return array{version: string, major: int, minor: int}
     */
    public static function libraryIdentity(): array
    {
        if (\defined('SODIUM_LIBRARY_VERSION')
            && \defined('SODIUM_LIBRARY_MAJOR_VERSION')
            && \defined('SODIUM_LIBRARY_MINOR_VERSION')
        ) {
            return [
                'version' => (string) \constant('SODIUM_LIBRARY_VERSION'),
                'major' => (int) \constant('SODIUM_LIBRARY_MAJOR_VERSION'),
                'minor' => (int) \constant('SODIUM_LIBRARY_MINOR_VERSION'),
            ];
        }

        $ffi = self::ffiVersion();
        if (null === $ffi) {
            throw new \LogicException('libsodium library identity is not available in this compiler build');
        }

        $version = $ffi->sodium_version_string();
        if ($version instanceof \FFI\CData) {
            $version = \FFI::string($version);
        }

        return [
            'version' => (string) $version,
            'major' => (int) $ffi->sodium_library_version_major(),
            'minor' => (int) $ffi->sodium_library_version_minor(),
        ];
    }

    /**
     * AEGIS-128L available — host PHP wrappers or libsodium FFI (php-src #ifdef crypto_aead_aegis128l_KEYBYTES; #20518).
     */
    public static function aeadAegis128lAvailable(): bool
    {
        if (null !== self::$aegis128lAvailable) {
            return self::$aegis128lAvailable;
        }
        if (\function_exists('sodium_crypto_aead_aegis128l_encrypt')) {
            return self::$aegis128lAvailable = true;
        }

        return self::$aegis128lAvailable = null !== self::ffiAegis();
    }

    /**
     * AEGIS-256 available — host PHP wrappers or libsodium FFI (php-src #ifdef crypto_aead_aegis256_KEYBYTES; #20518).
     */
    public static function aeadAegis256Available(): bool
    {
        if (null !== self::$aegis256Available) {
            return self::$aegis256Available;
        }
        if (\function_exists('sodium_crypto_aead_aegis256_encrypt')) {
            return self::$aegis256Available = true;
        }

        return self::$aegis256Available = null !== self::ffiAegis();
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

    public static function ristretto255IsValidPoint(string $s): bool
    {
        if (\strlen($s) !== self::CRYPTO_CORE_RISTRETTO255_BYTES) {
            self::throwSodium(
                'sodium_crypto_core_ristretto255_is_valid_point(): Argument #1 ($s) must be SODIUM_CRYPTO_CORE_RISTRETTO255_BYTES bytes long'
            );
        }
        if (\function_exists('sodium_crypto_core_ristretto255_is_valid_point')) {
            return \sodium_crypto_core_ristretto255_is_valid_point($s);
        }

        return self::ffiRistretto255IsValidPoint($s);
    }

    public static function ristretto255Random(): string
    {
        if (\function_exists('sodium_crypto_core_ristretto255_random')) {
            return \sodium_crypto_core_ristretto255_random();
        }

        return self::ffiRistretto255Random();
    }

    public static function ristretto255FromHash(string $s): string
    {
        if (\strlen($s) !== self::CRYPTO_CORE_RISTRETTO255_HASHBYTES) {
            self::throwSodium(
                'sodium_crypto_core_ristretto255_from_hash(): Argument #1 ($s) must be SODIUM_CRYPTO_CORE_RISTRETTO255_HASHBYTES bytes long'
            );
        }
        if (\function_exists('sodium_crypto_core_ristretto255_from_hash')) {
            return \sodium_crypto_core_ristretto255_from_hash($s);
        }

        return self::ffiRistretto255FromHash($s);
    }

    public static function ristretto255Add(string $p, string $q): string
    {
        self::requireRistretto255Point($p, 'sodium_crypto_core_ristretto255_add', 1, 'p');
        self::requireRistretto255Point($q, 'sodium_crypto_core_ristretto255_add', 2, 'q');
        if (\function_exists('sodium_crypto_core_ristretto255_add')) {
            return \sodium_crypto_core_ristretto255_add($p, $q);
        }

        return self::ffiRistretto255Add($p, $q);
    }

    public static function ristretto255Sub(string $p, string $q): string
    {
        self::requireRistretto255Point($p, 'sodium_crypto_core_ristretto255_sub', 1, 'p');
        self::requireRistretto255Point($q, 'sodium_crypto_core_ristretto255_sub', 2, 'q');
        if (\function_exists('sodium_crypto_core_ristretto255_sub')) {
            return \sodium_crypto_core_ristretto255_sub($p, $q);
        }

        return self::ffiRistretto255Sub($p, $q);
    }

    public static function ristretto255ScalarRandom(): string
    {
        if (\function_exists('sodium_crypto_core_ristretto255_scalar_random')) {
            return \sodium_crypto_core_ristretto255_scalar_random();
        }

        return self::ffiRistretto255ScalarRandom();
    }

    public static function ristretto255ScalarInvert(string $s): string
    {
        self::requireRistretto255Scalar($s, 'sodium_crypto_core_ristretto255_scalar_invert', 1, 's');
        if (\function_exists('sodium_crypto_core_ristretto255_scalar_invert')) {
            return \sodium_crypto_core_ristretto255_scalar_invert($s);
        }

        return self::ffiRistretto255ScalarInvert($s);
    }

    public static function ristretto255ScalarNegate(string $s): string
    {
        self::requireRistretto255Scalar($s, 'sodium_crypto_core_ristretto255_scalar_negate', 1, 's');
        if (\function_exists('sodium_crypto_core_ristretto255_scalar_negate')) {
            return \sodium_crypto_core_ristretto255_scalar_negate($s);
        }

        return self::ffiRistretto255ScalarNegate($s);
    }

    public static function ristretto255ScalarComplement(string $s): string
    {
        self::requireRistretto255Scalar($s, 'sodium_crypto_core_ristretto255_scalar_complement', 1, 's');
        if (\function_exists('sodium_crypto_core_ristretto255_scalar_complement')) {
            return \sodium_crypto_core_ristretto255_scalar_complement($s);
        }

        return self::ffiRistretto255ScalarComplement($s);
    }

    public static function ristretto255ScalarAdd(string $x, string $y): string
    {
        self::requireRistretto255Scalar($x, 'sodium_crypto_core_ristretto255_scalar_add', 1, 'x');
        self::requireRistretto255Scalar($y, 'sodium_crypto_core_ristretto255_scalar_add', 2, 'y');
        if (\function_exists('sodium_crypto_core_ristretto255_scalar_add')) {
            return \sodium_crypto_core_ristretto255_scalar_add($x, $y);
        }

        return self::ffiRistretto255ScalarAdd($x, $y);
    }

    public static function ristretto255ScalarSub(string $x, string $y): string
    {
        self::requireRistretto255Scalar($x, 'sodium_crypto_core_ristretto255_scalar_sub', 1, 'x');
        self::requireRistretto255Scalar($y, 'sodium_crypto_core_ristretto255_scalar_sub', 2, 'y');
        if (\function_exists('sodium_crypto_core_ristretto255_scalar_sub')) {
            return \sodium_crypto_core_ristretto255_scalar_sub($x, $y);
        }

        return self::ffiRistretto255ScalarSub($x, $y);
    }

    public static function ristretto255ScalarMul(string $x, string $y): string
    {
        self::requireRistretto255Scalar($x, 'sodium_crypto_core_ristretto255_scalar_mul', 1, 'x');
        self::requireRistretto255Scalar($y, 'sodium_crypto_core_ristretto255_scalar_mul', 2, 'y');
        if (\function_exists('sodium_crypto_core_ristretto255_scalar_mul')) {
            return \sodium_crypto_core_ristretto255_scalar_mul($x, $y);
        }

        return self::ffiRistretto255ScalarMul($x, $y);
    }

    public static function ristretto255ScalarReduce(string $s): string
    {
        if (\strlen($s) !== self::CRYPTO_CORE_RISTRETTO255_NONREDUCEDSCALARBYTES) {
            self::throwSodium(
                'sodium_crypto_core_ristretto255_scalar_reduce(): Argument #1 ($s) must be SODIUM_CRYPTO_CORE_RISTRETTO255_NONREDUCEDSCALARBYTES bytes long'
            );
        }
        if (\function_exists('sodium_crypto_core_ristretto255_scalar_reduce')) {
            return \sodium_crypto_core_ristretto255_scalar_reduce($s);
        }

        return self::ffiRistretto255ScalarReduce($s);
    }

    public static function scalarmultRistretto255(string $n, string $p): string
    {
        if (\strlen($n) !== self::CRYPTO_SCALARMULT_RISTRETTO255_SCALARBYTES) {
            self::throwSodium(
                'sodium_crypto_scalarmult_ristretto255(): Argument #1 ($n) must be SODIUM_CRYPTO_SCALARMULT_RISTRETTO255_SCALARBYTES bytes long'
            );
        }
        if (\strlen($p) !== self::CRYPTO_SCALARMULT_RISTRETTO255_BYTES) {
            self::throwSodium(
                'sodium_crypto_scalarmult_ristretto255(): Argument #2 ($p) must be SODIUM_CRYPTO_SCALARMULT_RISTRETTO255_BYTES bytes long'
            );
        }
        if (\function_exists('sodium_crypto_scalarmult_ristretto255')) {
            return \sodium_crypto_scalarmult_ristretto255($n, $p);
        }

        return self::ffiScalarmultRistretto255($n, $p);
    }

    public static function scalarmultRistretto255Base(string $n): string
    {
        if (\strlen($n) !== self::CRYPTO_SCALARMULT_RISTRETTO255_SCALARBYTES) {
            self::throwSodium(
                'sodium_crypto_scalarmult_ristretto255_base(): Argument #1 ($n) must be SODIUM_CRYPTO_SCALARMULT_RISTRETTO255_SCALARBYTES bytes long'
            );
        }
        if (self::isAllZeroBytes($n)) {
            self::throwSodium(
                'sodium_crypto_scalarmult_ristretto255_base(): Argument #1 ($n) must not be zero'
            );
        }
        if (\function_exists('sodium_crypto_scalarmult_ristretto255_base')) {
            return \sodium_crypto_scalarmult_ristretto255_base($n);
        }

        return self::ffiScalarmultRistretto255Base($n);
    }

    public static function boxKeypair(): string
    {
        if (\function_exists('sodium_crypto_box_keypair')) {
            return \sodium_crypto_box_keypair();
        }

        return self::ffiBoxKeypair();
    }

    /**
     * sodium_crypto_box_seed_keypair() — deterministic box keypair from seed (#21019).
     */
    public static function boxSeedKeypair(string $seed): string
    {
        if (\strlen($seed) !== self::CRYPTO_BOX_SEEDBYTES) {
            self::throwSodium(
                'sodium_crypto_box_seed_keypair(): Argument #1 ($seed) must be SODIUM_CRYPTO_BOX_SEEDBYTES bytes long'
            );
        }
        if (\function_exists('sodium_crypto_box_seed_keypair')) {
            return \sodium_crypto_box_seed_keypair($seed);
        }

        return self::ffiBoxSeedKeypair($seed);
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

    /**
     * sodium_crypto_kx_keypair() — X25519 key-exchange keypair (php-src ext/sodium/libsodium.c; #20047).
     */
    public static function kxKeypair(): string
    {
        if (\function_exists('sodium_crypto_kx_keypair')) {
            return \sodium_crypto_kx_keypair();
        }
        $sk = self::randomKeyBytes(self::CRYPTO_KX_SECRETKEYBYTES);

        return $sk.self::scalarmultBase($sk);
    }

    /**
     * sodium_crypto_kx_seed_keypair() — deterministic kx keypair from seed (#20047).
     */
    public static function kxSeedKeypair(string $seed): string
    {
        if (\strlen($seed) !== self::CRYPTO_KX_SEEDBYTES) {
            self::throwSodium(
                'sodium_crypto_kx_seed_keypair(): Argument #1 ($seed) must be SODIUM_CRYPTO_KX_SEEDBYTES bytes long'
            );
        }
        if (\function_exists('sodium_crypto_kx_seed_keypair')) {
            return \sodium_crypto_kx_seed_keypair($seed);
        }
        $sk = self::generichash($seed, '', self::CRYPTO_KX_SECRETKEYBYTES);

        return $sk.self::scalarmultBase($sk);
    }

    /**
     * sodium_crypto_kx_publickey() — extract public key from kx keypair (#20047).
     */
    public static function kxPublickey(string $keypair): string
    {
        self::validateKxKeypair($keypair, 'sodium_crypto_kx_publickey');
        if (\function_exists('sodium_crypto_kx_publickey')) {
            return \sodium_crypto_kx_publickey($keypair);
        }

        return \substr($keypair, self::CRYPTO_KX_SECRETKEYBYTES, self::CRYPTO_KX_PUBLICKEYBYTES);
    }

    /**
     * sodium_crypto_kx_secretkey() — extract secret key from kx keypair (#20047).
     */
    public static function kxSecretkey(string $keypair): string
    {
        self::validateKxKeypair($keypair, 'sodium_crypto_kx_secretkey');
        if (\function_exists('sodium_crypto_kx_secretkey')) {
            return \sodium_crypto_kx_secretkey($keypair);
        }

        return \substr($keypair, 0, self::CRYPTO_KX_SECRETKEYBYTES);
    }

    /**
     * sodium_crypto_kx_client_session_keys() — client rx/tx session keys (#20047).
     *
     * @return array{0: string, 1: string}
     */
    public static function kxClientSessionKeys(string $clientKeypair, string $serverKey): array
    {
        self::validateKxKeypair($clientKeypair, 'sodium_crypto_kx_client_session_keys', 1, 'client_key_pair');
        self::validateKxPublickey($serverKey, 'sodium_crypto_kx_client_session_keys', 2, 'server_key');
        if (\function_exists('sodium_crypto_kx_client_session_keys')) {
            /** @var array{0: string, 1: string} $keys */
            $keys = \sodium_crypto_kx_client_session_keys($clientKeypair, $serverKey);

            return $keys;
        }
        $clientSk = \substr($clientKeypair, 0, self::CRYPTO_KX_SECRETKEYBYTES);
        $clientPk = \substr($clientKeypair, self::CRYPTO_KX_SECRETKEYBYTES, self::CRYPTO_KX_PUBLICKEYBYTES);
        $session = self::kxSessionKeyMaterial($clientSk, $serverKey, $clientPk, $serverKey);

        return [
            \substr($session, 0, self::CRYPTO_KX_SESSIONKEYBYTES),
            \substr($session, self::CRYPTO_KX_SESSIONKEYBYTES, self::CRYPTO_KX_SESSIONKEYBYTES),
        ];
    }

    /**
     * sodium_crypto_kx_server_session_keys() — server rx/tx session keys (#20047).
     *
     * @return array{0: string, 1: string}
     */
    public static function kxServerSessionKeys(string $serverKeypair, string $clientKey): array
    {
        self::validateKxKeypair($serverKeypair, 'sodium_crypto_kx_server_session_keys', 1, 'server_key_pair');
        self::validateKxPublickey($clientKey, 'sodium_crypto_kx_server_session_keys', 2, 'client_key');
        if (\function_exists('sodium_crypto_kx_server_session_keys')) {
            /** @var array{0: string, 1: string} $keys */
            $keys = \sodium_crypto_kx_server_session_keys($serverKeypair, $clientKey);

            return $keys;
        }
        $serverSk = \substr($serverKeypair, 0, self::CRYPTO_KX_SECRETKEYBYTES);
        $serverPk = \substr($serverKeypair, self::CRYPTO_KX_SECRETKEYBYTES, self::CRYPTO_KX_PUBLICKEYBYTES);
        $session = self::kxSessionKeyMaterial($serverSk, $clientKey, $clientKey, $serverPk);

        // php-src swaps halves vs client so rx/tx cross-agree.
        return [
            \substr($session, self::CRYPTO_KX_SESSIONKEYBYTES, self::CRYPTO_KX_SESSIONKEYBYTES),
            \substr($session, 0, self::CRYPTO_KX_SESSIONKEYBYTES),
        ];
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
     * sodium_crypto_aead_xchacha20poly1305_ietf_keygen() — random XChaCha20-Poly1305 key (#21019).
     */
    public static function aeadXchacha20poly1305IetfKeygen(): string
    {
        if (\function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_keygen')) {
            return \sodium_crypto_aead_xchacha20poly1305_ietf_keygen();
        }

        return self::randomKeyBytes(self::CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
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

    /**
     * sodium_crypto_aead_aes256gcm_keygen() — random AES-256-GCM key (php-src #21019).
     *
     * Registered whenever AES-GCM symbols are advertised (same as encrypt/decrypt); keygen
     * itself only needs randombytes and does not require HW AES (#ifdef HAVE_AESGCM).
     */
    public static function aeadAes256gcmKeygen(): string
    {
        if (\function_exists('sodium_crypto_aead_aes256gcm_keygen')) {
            return \sodium_crypto_aead_aes256gcm_keygen();
        }

        return self::randomKeyBytes(self::CRYPTO_AEAD_AES256GCM_KEYBYTES);
    }

    /**
     * sodium_crypto_aead_aegis128l_keygen() — random AEGIS-128L key (php-src #20518).
     */
    public static function aeadAegis128lKeygen(): string
    {
        if (!self::aeadAegis128lAvailable()) {
            self::throwSodium('AEGIS-128L is not available');
        }
        if (\function_exists('sodium_crypto_aead_aegis128l_keygen')) {
            return \sodium_crypto_aead_aegis128l_keygen();
        }

        return self::randomKeyBytes(self::CRYPTO_AEAD_AEGIS128L_KEYBYTES);
    }

    public static function aeadAegis128lEncrypt(
        string $message,
        string $additionalData,
        string $nonce,
        string $key
    ): string {
        if (!self::aeadAegis128lAvailable()) {
            self::throwSodium('AEGIS-128L is not available');
        }
        if (\function_exists('sodium_crypto_aead_aegis128l_encrypt')) {
            self::validateAeadAegis128lKeyNonce($key, $nonce, 'sodium_crypto_aead_aegis128l_encrypt', 3, 4);

            return \sodium_crypto_aead_aegis128l_encrypt($message, $additionalData, $nonce, $key);
        }

        return self::ffiAeadAegis128lEncrypt($message, $additionalData, $nonce, $key);
    }

    /**
     * @return string|false
     */
    public static function aeadAegis128lDecrypt(
        string $ciphertext,
        string $additionalData,
        string $nonce,
        string $key
    ): string|false {
        if (!self::aeadAegis128lAvailable()) {
            self::throwSodium('AEGIS-128L is not available');
        }
        if (\function_exists('sodium_crypto_aead_aegis128l_decrypt')) {
            self::validateAeadAegis128lKeyNonce($key, $nonce, 'sodium_crypto_aead_aegis128l_decrypt', 3, 4);

            return \sodium_crypto_aead_aegis128l_decrypt($ciphertext, $additionalData, $nonce, $key);
        }

        return self::ffiAeadAegis128lDecrypt($ciphertext, $additionalData, $nonce, $key);
    }

    /**
     * sodium_crypto_aead_aegis256_keygen() — random AEGIS-256 key (php-src #20518).
     */
    public static function aeadAegis256Keygen(): string
    {
        if (!self::aeadAegis256Available()) {
            self::throwSodium('AEGIS-256 is not available');
        }
        if (\function_exists('sodium_crypto_aead_aegis256_keygen')) {
            return \sodium_crypto_aead_aegis256_keygen();
        }

        return self::randomKeyBytes(self::CRYPTO_AEAD_AEGIS256_KEYBYTES);
    }

    public static function aeadAegis256Encrypt(
        string $message,
        string $additionalData,
        string $nonce,
        string $key
    ): string {
        if (!self::aeadAegis256Available()) {
            self::throwSodium('AEGIS-256 is not available');
        }
        if (\function_exists('sodium_crypto_aead_aegis256_encrypt')) {
            self::validateAeadAegis256KeyNonce($key, $nonce, 'sodium_crypto_aead_aegis256_encrypt', 3, 4);

            return \sodium_crypto_aead_aegis256_encrypt($message, $additionalData, $nonce, $key);
        }

        return self::ffiAeadAegis256Encrypt($message, $additionalData, $nonce, $key);
    }

    /**
     * @return string|false
     */
    public static function aeadAegis256Decrypt(
        string $ciphertext,
        string $additionalData,
        string $nonce,
        string $key
    ): string|false {
        if (!self::aeadAegis256Available()) {
            self::throwSodium('AEGIS-256 is not available');
        }
        if (\function_exists('sodium_crypto_aead_aegis256_decrypt')) {
            self::validateAeadAegis256KeyNonce($key, $nonce, 'sodium_crypto_aead_aegis256_decrypt', 3, 4);

            return \sodium_crypto_aead_aegis256_decrypt($ciphertext, $additionalData, $nonce, $key);
        }

        return self::ffiAeadAegis256Decrypt($ciphertext, $additionalData, $nonce, $key);
    }

    public static function signKeypair(): string
    {
        if (\function_exists('sodium_crypto_sign_keypair')) {
            return \sodium_crypto_sign_keypair();
        }

        return self::ffiSignKeypair();
    }

    /**
     * sodium_crypto_sign_seed_keypair() — deterministic Ed25519 keypair from seed (#21019).
     */
    public static function signSeedKeypair(string $seed): string
    {
        if (\strlen($seed) !== self::CRYPTO_SIGN_SEEDBYTES) {
            self::throwSodium(
                'sodium_crypto_sign_seed_keypair(): Argument #1 ($seed) must be SODIUM_CRYPTO_SIGN_SEEDBYTES bytes long'
            );
        }
        if (\function_exists('sodium_crypto_sign_seed_keypair')) {
            return \sodium_crypto_sign_seed_keypair($seed);
        }

        return self::ffiSignSeedKeypair($seed);
    }

    /**
     * sodium_crypto_sign_keypair_from_secretkey_and_publickey() — assemble sign keypair (#21019).
     */
    public static function signKeypairFromSecretkeyAndPublickey(string $secretkey, string $publickey): string
    {
        self::validateSignSecretkey($secretkey, 'sodium_crypto_sign_keypair_from_secretkey_and_publickey', 1);
        self::validateSignPublickey($publickey, 'sodium_crypto_sign_keypair_from_secretkey_and_publickey', 2);
        if (\function_exists('sodium_crypto_sign_keypair_from_secretkey_and_publickey')) {
            return \sodium_crypto_sign_keypair_from_secretkey_and_publickey($secretkey, $publickey);
        }

        return $secretkey.$publickey;
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

    /**
     * php-src: ext/sodium/libsodium.c — PHP_FUNCTION(sodium_crypto_sign_ed25519_sk_to_curve25519) (#20573).
     */
    public static function signEd25519SkToCurve25519(string $secretkey): string
    {
        self::validateSignSecretkey($secretkey, 'sodium_crypto_sign_ed25519_sk_to_curve25519');
        if (\function_exists('sodium_crypto_sign_ed25519_sk_to_curve25519')) {
            return \sodium_crypto_sign_ed25519_sk_to_curve25519($secretkey);
        }

        return self::ffiSignEd25519SkToCurve25519($secretkey);
    }

    /**
     * php-src: ext/sodium/libsodium.c — PHP_FUNCTION(sodium_crypto_sign_ed25519_pk_to_curve25519) (#20573).
     */
    public static function signEd25519PkToCurve25519(string $publickey): string
    {
        if (\strlen($publickey) !== self::CRYPTO_SIGN_PUBLICKEYBYTES) {
            self::throwSodium(
                'sodium_crypto_sign_ed25519_pk_to_curve25519(): Argument #1 ($public_key) must be SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES bytes long'
            );
        }
        if (\function_exists('sodium_crypto_sign_ed25519_pk_to_curve25519')) {
            return \sodium_crypto_sign_ed25519_pk_to_curve25519($publickey);
        }

        return self::ffiSignEd25519PkToCurve25519($publickey);
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

    /**
     * sodium_crypto_pwhash() (php-src ext/sodium/libsodium.c; #20048).
     */
    public static function pwhash(
        int $length,
        string $password,
        string $salt,
        int $opslimit,
        int $memlimit,
        int $algo
    ): string {
        self::validatePwhash($length, $password, $salt, $opslimit, $memlimit, $algo);
        if (\function_exists('sodium_crypto_pwhash')) {
            return \sodium_crypto_pwhash($length, $password, $salt, $opslimit, $memlimit, $algo);
        }

        return self::ffiPwhash($length, $password, $salt, $opslimit, $memlimit, $algo);
    }

    /**
     * sodium_crypto_pwhash_str() (php-src ext/sodium/libsodium.c; #20048).
     */
    public static function pwhashStr(string $password, int $opslimit, int $memlimit): string
    {
        self::validatePwhashStr($password, $opslimit, $memlimit);
        if (\function_exists('sodium_crypto_pwhash_str')) {
            return \sodium_crypto_pwhash_str($password, $opslimit, $memlimit);
        }

        return self::ffiPwhashStr($password, $opslimit, $memlimit);
    }

    /**
     * sodium_crypto_pwhash_str_verify() (php-src ext/sodium/libsodium.c; #20048).
     */
    public static function pwhashStrVerify(string $hash, string $password): bool
    {
        if (\strlen($password) >= 0xffffffff) {
            self::throwSodium(
                'sodium_crypto_pwhash_str_verify(): Argument #2 ($password) is too long'
            );
        }
        if (\function_exists('sodium_crypto_pwhash_str_verify')) {
            return \sodium_crypto_pwhash_str_verify($hash, $password);
        }

        return self::ffiPwhashStrVerify($hash, $password);
    }

    /**
     * sodium_crypto_pwhash_str_needs_rehash() (php-src ext/sodium/libsodium.c; #20048).
     */
    public static function pwhashStrNeedsRehash(string $hash, int $opslimit, int $memlimit): bool
    {
        if (\function_exists('sodium_crypto_pwhash_str_needs_rehash')) {
            return \sodium_crypto_pwhash_str_needs_rehash($hash, $opslimit, $memlimit);
        }

        return self::ffiPwhashStrNeedsRehash($hash, $opslimit, $memlimit);
    }

    /**
     * sodium_crypto_pwhash_scryptsalsa208sha256() (php-src ext/sodium/libsodium.c; #21460).
     */
    public static function pwhashScrypt(
        int $length,
        string $password,
        string $salt,
        int $opslimit,
        int $memlimit
    ): string {
        self::validatePwhashScrypt($length, $password, $salt, $opslimit, $memlimit);
        if (\function_exists('sodium_crypto_pwhash_scryptsalsa208sha256')) {
            return \sodium_crypto_pwhash_scryptsalsa208sha256($length, $password, $salt, $opslimit, $memlimit);
        }

        return self::ffiPwhashScrypt($length, $password, $salt, $opslimit, $memlimit);
    }

    /**
     * sodium_crypto_pwhash_scryptsalsa208sha256_str() (php-src ext/sodium/libsodium.c; #21460).
     */
    public static function pwhashScryptStr(string $password, int $opslimit, int $memlimit): string
    {
        self::validatePwhashScryptStr($password, $opslimit, $memlimit);
        if (\function_exists('sodium_crypto_pwhash_scryptsalsa208sha256_str')) {
            return \sodium_crypto_pwhash_scryptsalsa208sha256_str($password, $opslimit, $memlimit);
        }

        return self::ffiPwhashScryptStr($password, $opslimit, $memlimit);
    }

    /**
     * sodium_crypto_pwhash_scryptsalsa208sha256_str_verify() (php-src ext/sodium/libsodium.c; #21460).
     */
    public static function pwhashScryptStrVerify(string $hash, string $password): bool
    {
        if (\function_exists('sodium_crypto_pwhash_scryptsalsa208sha256_str_verify')) {
            return \sodium_crypto_pwhash_scryptsalsa208sha256_str_verify($hash, $password);
        }
        if (\strlen($hash) !== self::CRYPTO_PWHASH_SCRYPTSALSA208SHA256_STRBYTES - 1) {
            return false;
        }

        return self::ffiPwhashScryptStrVerify($hash, $password);
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
     * sodium_bin2base64() — binary→base64 variant (php-src ext/sodium/libsodium.c; #20675).
     */
    public static function bin2base64(string $string, int $id): string
    {
        self::assertValidBase64Variant($id, 'sodium_bin2base64', 2, 'id');
        if (\function_exists('sodium_bin2base64')) {
            return \sodium_bin2base64($string, $id);
        }

        return self::pureBin2base64($string, $id);
    }

    /**
     * sodium_base642bin() — base64 variant→binary (php-src ext/sodium/libsodium.c; #20675).
     */
    public static function base642bin(string $string, int $id, string $ignore = ''): string
    {
        self::assertValidBase64Variant($id, 'sodium_base642bin', 2, 'id');
        if (\function_exists('sodium_base642bin')) {
            return \sodium_base642bin($string, $id, $ignore);
        }

        return self::pureBase642bin($string, $id, $ignore);
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

    private static function validatePwhash(
        int $length,
        string $password,
        string $salt,
        int $opslimit,
        int $memlimit,
        int $algo
    ): void {
        if ($length <= 0) {
            self::throwSodium(
                'sodium_crypto_pwhash(): Argument #1 ($length) must be greater than 0'
            );
        }
        if ($length >= 0xffffffff) {
            self::throwSodium(
                'sodium_crypto_pwhash(): Argument #1 ($length) is too large'
            );
        }
        if (\strlen($password) >= 0xffffffff) {
            self::throwSodium(
                'sodium_crypto_pwhash(): Argument #2 ($password) is too long'
            );
        }
        if ($opslimit <= 0) {
            self::throwSodium(
                'sodium_crypto_pwhash(): Argument #4 ($opslimit) must be greater than 0'
            );
        }
        if ($memlimit <= 0) {
            self::throwSodium(
                'sodium_crypto_pwhash(): Argument #5 ($memlimit) must be greater than 0'
            );
        }
        if (
            $algo !== self::CRYPTO_PWHASH_ALG_ARGON2I13
            && $algo !== self::CRYPTO_PWHASH_ALG_ARGON2ID13
            && $algo !== self::CRYPTO_PWHASH_ALG_DEFAULT
        ) {
            self::throwSodium('unsupported password hashing algorithm');
        }
        if (\strlen($salt) !== self::CRYPTO_PWHASH_SALTBYTES) {
            self::throwSodium(
                'sodium_crypto_pwhash(): Argument #3 ($salt) must be SODIUM_CRYPTO_PWHASH_SALTBYTES bytes long'
            );
        }
        if ($opslimit < self::CRYPTO_PWHASH_OPSLIMIT_MIN) {
            self::throwSodium(\sprintf(
                'sodium_crypto_pwhash(): Argument #4 ($opslimit) must be greater than or equal to %d',
                self::CRYPTO_PWHASH_OPSLIMIT_MIN
            ));
        }
        if ($memlimit < self::CRYPTO_PWHASH_MEMLIMIT_MIN) {
            self::throwSodium(\sprintf(
                'sodium_crypto_pwhash(): Argument #5 ($memlimit) must be greater than or equal to %d',
                self::CRYPTO_PWHASH_MEMLIMIT_MIN
            ));
        }
    }

    private static function validatePwhashStr(string $password, int $opslimit, int $memlimit): void
    {
        if ($opslimit <= 0) {
            self::throwSodium(
                'sodium_crypto_pwhash_str(): Argument #2 ($opslimit) must be greater than 0'
            );
        }
        if ($memlimit <= 0) {
            self::throwSodium(
                'sodium_crypto_pwhash_str(): Argument #3 ($memlimit) must be greater than 0'
            );
        }
        if (\strlen($password) >= 0xffffffff) {
            self::throwSodium(
                'sodium_crypto_pwhash_str(): Argument #1 ($password) is too long'
            );
        }
        if ($opslimit < self::CRYPTO_PWHASH_OPSLIMIT_MIN) {
            self::throwSodium(\sprintf(
                'sodium_crypto_pwhash_str(): Argument #2 ($opslimit) must be greater than or equal to %d',
                self::CRYPTO_PWHASH_OPSLIMIT_MIN
            ));
        }
        if ($memlimit < self::CRYPTO_PWHASH_MEMLIMIT_MIN) {
            self::throwSodium(\sprintf(
                'sodium_crypto_pwhash_str(): Argument #3 ($memlimit) must be greater than or equal to %d',
                self::CRYPTO_PWHASH_MEMLIMIT_MIN
            ));
        }
    }

    private static function validatePwhashScrypt(
        int $length,
        string $password,
        string $salt,
        int $opslimit,
        int $memlimit
    ): void {
        // php-src: hash_len <= 0 || >= SIZE_MAX || > 0x1fffffffe0 → "must be greater than 0"
        if ($length <= 0 || $length > 0x1fffffffe0) {
            self::throwSodium(
                'sodium_crypto_pwhash_scryptsalsa208sha256(): Argument #1 ($length) must be greater than 0'
            );
        }
        if ($opslimit <= 0) {
            self::throwSodium(
                'sodium_crypto_pwhash_scryptsalsa208sha256(): Argument #4 ($opslimit) must be greater than 0'
            );
        }
        if ($memlimit <= 0) {
            self::throwSodium(
                'sodium_crypto_pwhash_scryptsalsa208sha256(): Argument #5 ($memlimit) must be greater than 0'
            );
        }
        if (\strlen($salt) !== self::CRYPTO_PWHASH_SCRYPTSALSA208SHA256_SALTBYTES) {
            self::throwSodium(
                'sodium_crypto_pwhash_scryptsalsa208sha256(): Argument #3 ($salt) must be SODIUM_CRYPTO_PWHASH_SCRYPTSALSA208SHA256_SALTBYTES bytes long'
            );
        }
        if ($opslimit < self::CRYPTO_PWHASH_SCRYPTSALSA208SHA256_OPSLIMIT_INTERACTIVE) {
            self::throwSodium(\sprintf(
                'sodium_crypto_pwhash_scryptsalsa208sha256(): Argument #4 ($opslimit) must be greater than or equal to %d',
                self::CRYPTO_PWHASH_SCRYPTSALSA208SHA256_OPSLIMIT_INTERACTIVE
            ));
        }
        if ($memlimit < self::CRYPTO_PWHASH_SCRYPTSALSA208SHA256_MEMLIMIT_INTERACTIVE) {
            self::throwSodium(\sprintf(
                'sodium_crypto_pwhash_scryptsalsa208sha256(): Argument #5 ($memlimit) must be greater than or equal to %d',
                self::CRYPTO_PWHASH_SCRYPTSALSA208SHA256_MEMLIMIT_INTERACTIVE
            ));
        }
    }

    private static function validatePwhashScryptStr(string $password, int $opslimit, int $memlimit): void
    {
        if ($opslimit <= 0) {
            self::throwSodium(
                'sodium_crypto_pwhash_scryptsalsa208sha256_str(): Argument #2 ($opslimit) must be greater than 0'
            );
        }
        if ($memlimit <= 0) {
            self::throwSodium(
                'sodium_crypto_pwhash_scryptsalsa208sha256_str(): Argument #3 ($memlimit) must be greater than 0'
            );
        }
        if ($opslimit < self::CRYPTO_PWHASH_SCRYPTSALSA208SHA256_OPSLIMIT_INTERACTIVE) {
            self::throwSodium(\sprintf(
                'sodium_crypto_pwhash_scryptsalsa208sha256_str(): Argument #2 ($opslimit) must be greater than or equal to %d',
                self::CRYPTO_PWHASH_SCRYPTSALSA208SHA256_OPSLIMIT_INTERACTIVE
            ));
        }
        if ($memlimit < self::CRYPTO_PWHASH_SCRYPTSALSA208SHA256_MEMLIMIT_INTERACTIVE) {
            self::throwSodium(\sprintf(
                'sodium_crypto_pwhash_scryptsalsa208sha256_str(): Argument #3 ($memlimit) must be greater than or equal to %d',
                self::CRYPTO_PWHASH_SCRYPTSALSA208SHA256_MEMLIMIT_INTERACTIVE
            ));
        }
    }

    private static function ffiPwhash(
        int $length,
        string $password,
        string $salt,
        int $opslimit,
        int $memlimit,
        int $algo
    ): string {
        $ffi = self::requireFfi();
        $outBuf = $ffi->new('unsigned char['.$length.']');
        $passBuf = self::stringToUnsignedCharArray($ffi, $password);
        $saltBuf = self::stringToUnsignedCharArray($ffi, $salt);
        $rc = $ffi->crypto_pwhash(
            $outBuf,
            $length,
            $passBuf,
            \strlen($password),
            $saltBuf,
            $opslimit,
            $memlimit,
            $algo
        );
        if (0 !== $rc) {
            self::throwSodium('internal error');
        }

        return self::unsignedCharArrayToString($outBuf, $length);
    }

    private static function ffiPwhashStr(string $password, int $opslimit, int $memlimit): string
    {
        $ffi = self::requireFfi();
        $outBuf = $ffi->new('char['.self::CRYPTO_PWHASH_STRBYTES.']');
        $passBuf = self::stringToUnsignedCharArray($ffi, $password);
        $rc = $ffi->crypto_pwhash_str($outBuf, $passBuf, \strlen($password), $opslimit, $memlimit);
        if (0 !== $rc) {
            self::throwSodium('internal error');
        }
        $out = '';
        for ($i = 0; $i < self::CRYPTO_PWHASH_STRBYTES; ++$i) {
            $ch = (int) $outBuf[$i];
            if (0 === $ch) {
                break;
            }
            $out .= \chr($ch);
        }

        return $out;
    }

    private static function ffiPwhashStrVerify(string $hash, string $password): bool
    {
        $ffi = self::requireFfi();
        $hashBuf = self::stringToUnsignedCharArray($ffi, $hash."\0");
        $passBuf = self::stringToUnsignedCharArray($ffi, $password);

        return 0 === $ffi->crypto_pwhash_str_verify($hashBuf, $passBuf, \strlen($password));
    }

    private static function ffiPwhashStrNeedsRehash(string $hash, int $opslimit, int $memlimit): bool
    {
        $ffi = self::requireFfi();
        $hashBuf = self::stringToUnsignedCharArray($ffi, $hash."\0");

        return 0 !== $ffi->crypto_pwhash_str_needs_rehash($hashBuf, $opslimit, $memlimit);
    }

    private static function ffiPwhashScrypt(
        int $length,
        string $password,
        string $salt,
        int $opslimit,
        int $memlimit
    ): string {
        $ffi = self::requireFfi();
        $outBuf = $ffi->new('unsigned char['.$length.']');
        $passBuf = self::stringToUnsignedCharArray($ffi, $password);
        $saltBuf = self::stringToUnsignedCharArray($ffi, $salt);
        $rc = $ffi->crypto_pwhash_scryptsalsa208sha256(
            $outBuf,
            $length,
            $passBuf,
            \strlen($password),
            $saltBuf,
            $opslimit,
            $memlimit
        );
        if (0 !== $rc) {
            self::throwSodium('internal error');
        }

        return self::unsignedCharArrayToString($outBuf, $length);
    }

    private static function ffiPwhashScryptStr(string $password, int $opslimit, int $memlimit): string
    {
        $ffi = self::requireFfi();
        $outBuf = $ffi->new('char['.self::CRYPTO_PWHASH_SCRYPTSALSA208SHA256_STRBYTES.']');
        $passBuf = self::stringToUnsignedCharArray($ffi, $password);
        $rc = $ffi->crypto_pwhash_scryptsalsa208sha256_str(
            $outBuf,
            $passBuf,
            \strlen($password),
            $opslimit,
            $memlimit
        );
        if (0 !== $rc) {
            self::throwSodium('internal error');
        }
        $out = '';
        for ($i = 0; $i < self::CRYPTO_PWHASH_SCRYPTSALSA208SHA256_STRBYTES; ++$i) {
            $ch = (int) $outBuf[$i];
            if (0 === $ch) {
                break;
            }
            $out .= \chr($ch);
        }

        return $out;
    }

    private static function ffiPwhashScryptStrVerify(string $hash, string $password): bool
    {
        $ffi = self::requireFfi();
        $hashBuf = self::stringToUnsignedCharArray($ffi, $hash."\0");
        $passBuf = self::stringToUnsignedCharArray($ffi, $password);

        return 0 === $ffi->crypto_pwhash_scryptsalsa208sha256_str_verify($hashBuf, $passBuf, \strlen($password));
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

    /**
     * libsodium: ((((unsigned int) variant) & ~0x6U) != 0x1U) → invalid.
     */
    private static function assertValidBase64Variant(int $id, string $fn, int $argNum, string $param): void
    {
        if (0x1 !== ($id & ~0x6)) {
            self::throwSodium(\sprintf(
                '%s(): Argument #%d ($%s) must be a valid base64 variant identifier',
                $fn,
                $argNum,
                $param
            ));
        }
    }

    private static function pureBin2base64(string $string, int $id): string
    {
        $b64 = \base64_encode($string);
        if (0 !== ($id & 0x4)) {
            $b64 = \strtr($b64, '+/', '-_');
        }
        if (0 !== ($id & 0x2)) {
            $b64 = \rtrim($b64, '=');
        }

        return $b64;
    }

    private static function pureBase642bin(string $string, int $id, string $ignore): string
    {
        if ('' !== $ignore) {
            $ignoreSet = [];
            $ignoreLen = \strlen($ignore);
            for ($i = 0; $i < $ignoreLen; ++$i) {
                $ignoreSet[$ignore[$i]] = true;
            }
            $filtered = '';
            $len = \strlen($string);
            for ($i = 0; $i < $len; ++$i) {
                $ch = $string[$i];
                if (!isset($ignoreSet[$ch])) {
                    $filtered .= $ch;
                }
            }
            $string = $filtered;
        }
        if ('' === $string) {
            return '';
        }

        $urlSafe = 0 !== ($id & 0x4);
        $noPad = 0 !== ($id & 0x2);
        if ($noPad) {
            if (false !== \strpos($string, '=')) {
                self::throwSodium('sodium_base642bin(): Argument #1 ($string) must be a valid base64 string');
            }
        } elseif (0 !== (\strlen($string) % 4)) {
            self::throwSodium('sodium_base642bin(): Argument #1 ($string) must be a valid base64 string');
        }

        if ($urlSafe) {
            if (1 === \preg_match('/[\/+]/', $string)) {
                self::throwSodium('sodium_base642bin(): Argument #1 ($string) must be a valid base64 string');
            }
            if (1 !== \preg_match('/^[A-Za-z0-9\-_]*={0,2}$/', $string)) {
                self::throwSodium('sodium_base642bin(): Argument #1 ($string) must be a valid base64 string');
            }
            $canonical = \strtr($string, '-_', '+/');
        } else {
            if (1 === \preg_match('/[\-_]/', $string)) {
                self::throwSodium('sodium_base642bin(): Argument #1 ($string) must be a valid base64 string');
            }
            if (1 !== \preg_match('/^[A-Za-z0-9\/+]*={0,2}$/', $string)) {
                self::throwSodium('sodium_base642bin(): Argument #1 ($string) must be a valid base64 string');
            }
            $canonical = $string;
        }

        if ($noPad) {
            $pad = (4 - (\strlen($canonical) % 4)) % 4;
            $canonical .= \str_repeat('=', $pad);
        }

        $bin = \base64_decode($canonical, true);
        if (false === $bin) {
            self::throwSodium('sodium_base642bin(): Argument #1 ($string) must be a valid base64 string');
        }
        // Reject non-canonical padding / leftover bits (php-src requires full consume).
        $reencoded = self::pureBin2base64($bin, $id);
        if ($reencoded !== $string) {
            self::throwSodium('sodium_base642bin(): Argument #1 ($string) must be a valid base64 string');
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

    private static function requireRistretto255Point(string $value, string $fn, int $argNum, string $argName): void
    {
        if (\strlen($value) !== self::CRYPTO_CORE_RISTRETTO255_BYTES) {
            self::throwSodium(\sprintf(
                '%s(): Argument #%d ($%s) must be SODIUM_CRYPTO_CORE_RISTRETTO255_BYTES bytes long',
                $fn,
                $argNum,
                $argName
            ));
        }
    }

    private static function requireRistretto255Scalar(string $value, string $fn, int $argNum, string $argName): void
    {
        if (\strlen($value) !== self::CRYPTO_CORE_RISTRETTO255_SCALARBYTES) {
            self::throwSodium(\sprintf(
                '%s(): Argument #%d ($%s) must be SODIUM_CRYPTO_CORE_RISTRETTO255_SCALARBYTES bytes long',
                $fn,
                $argNum,
                $argName
            ));
        }
    }

    private static function isAllZeroBytes(string $value): bool
    {
        $len = \strlen($value);
        for ($i = 0; $i < $len; ++$i) {
            if ("\0" !== $value[$i]) {
                return false;
            }
        }

        return true;
    }

    private static function ffiRistretto255IsValidPoint(string $s): bool
    {
        $ffi = self::requireFfi();
        $pBuf = self::stringToUnsignedCharArray($ffi, $s);

        return 1 === (int) $ffi->crypto_core_ristretto255_is_valid_point($pBuf);
    }

    private static function ffiRistretto255Random(): string
    {
        $ffi = self::requireFfi();
        $pBuf = $ffi->new('unsigned char['.self::CRYPTO_CORE_RISTRETTO255_BYTES.']');
        $ffi->crypto_core_ristretto255_random($pBuf);

        return self::unsignedCharArrayToString($pBuf, self::CRYPTO_CORE_RISTRETTO255_BYTES);
    }

    private static function ffiRistretto255FromHash(string $s): string
    {
        $ffi = self::requireFfi();
        $pBuf = $ffi->new('unsigned char['.self::CRYPTO_CORE_RISTRETTO255_BYTES.']');
        $rBuf = self::stringToUnsignedCharArray($ffi, $s);
        $rc = $ffi->crypto_core_ristretto255_from_hash($pBuf, $rBuf);
        if (0 !== $rc) {
            self::throwSodium('internal error');
        }

        return self::unsignedCharArrayToString($pBuf, self::CRYPTO_CORE_RISTRETTO255_BYTES);
    }

    private static function ffiRistretto255Add(string $p, string $q): string
    {
        $ffi = self::requireFfi();
        $rBuf = $ffi->new('unsigned char['.self::CRYPTO_CORE_RISTRETTO255_BYTES.']');
        $pBuf = self::stringToUnsignedCharArray($ffi, $p);
        $qBuf = self::stringToUnsignedCharArray($ffi, $q);
        $rc = $ffi->crypto_core_ristretto255_add($rBuf, $pBuf, $qBuf);
        if (0 !== $rc) {
            self::throwSodium('internal error');
        }

        return self::unsignedCharArrayToString($rBuf, self::CRYPTO_CORE_RISTRETTO255_BYTES);
    }

    private static function ffiRistretto255Sub(string $p, string $q): string
    {
        $ffi = self::requireFfi();
        $rBuf = $ffi->new('unsigned char['.self::CRYPTO_CORE_RISTRETTO255_BYTES.']');
        $pBuf = self::stringToUnsignedCharArray($ffi, $p);
        $qBuf = self::stringToUnsignedCharArray($ffi, $q);
        $rc = $ffi->crypto_core_ristretto255_sub($rBuf, $pBuf, $qBuf);
        if (0 !== $rc) {
            self::throwSodium('internal error');
        }

        return self::unsignedCharArrayToString($rBuf, self::CRYPTO_CORE_RISTRETTO255_BYTES);
    }

    private static function ffiRistretto255ScalarRandom(): string
    {
        $ffi = self::requireFfi();
        $rBuf = $ffi->new('unsigned char['.self::CRYPTO_CORE_RISTRETTO255_SCALARBYTES.']');
        $ffi->crypto_core_ristretto255_scalar_random($rBuf);

        return self::unsignedCharArrayToString($rBuf, self::CRYPTO_CORE_RISTRETTO255_SCALARBYTES);
    }

    private static function ffiRistretto255ScalarInvert(string $s): string
    {
        $ffi = self::requireFfi();
        $out = $ffi->new('unsigned char['.self::CRYPTO_CORE_RISTRETTO255_SCALARBYTES.']');
        $in = self::stringToUnsignedCharArray($ffi, $s);
        $rc = $ffi->crypto_core_ristretto255_scalar_invert($out, $in);
        if (0 !== $rc) {
            self::throwSodium('internal error');
        }

        return self::unsignedCharArrayToString($out, self::CRYPTO_CORE_RISTRETTO255_SCALARBYTES);
    }

    private static function ffiRistretto255ScalarNegate(string $s): string
    {
        $ffi = self::requireFfi();
        $out = $ffi->new('unsigned char['.self::CRYPTO_CORE_RISTRETTO255_SCALARBYTES.']');
        $in = self::stringToUnsignedCharArray($ffi, $s);
        $ffi->crypto_core_ristretto255_scalar_negate($out, $in);

        return self::unsignedCharArrayToString($out, self::CRYPTO_CORE_RISTRETTO255_SCALARBYTES);
    }

    private static function ffiRistretto255ScalarComplement(string $s): string
    {
        $ffi = self::requireFfi();
        $out = $ffi->new('unsigned char['.self::CRYPTO_CORE_RISTRETTO255_SCALARBYTES.']');
        $in = self::stringToUnsignedCharArray($ffi, $s);
        $ffi->crypto_core_ristretto255_scalar_complement($out, $in);

        return self::unsignedCharArrayToString($out, self::CRYPTO_CORE_RISTRETTO255_SCALARBYTES);
    }

    private static function ffiRistretto255ScalarAdd(string $x, string $y): string
    {
        $ffi = self::requireFfi();
        $zBuf = $ffi->new('unsigned char['.self::CRYPTO_CORE_RISTRETTO255_SCALARBYTES.']');
        $xBuf = self::stringToUnsignedCharArray($ffi, $x);
        $yBuf = self::stringToUnsignedCharArray($ffi, $y);
        $ffi->crypto_core_ristretto255_scalar_add($zBuf, $xBuf, $yBuf);

        return self::unsignedCharArrayToString($zBuf, self::CRYPTO_CORE_RISTRETTO255_SCALARBYTES);
    }

    private static function ffiRistretto255ScalarSub(string $x, string $y): string
    {
        $ffi = self::requireFfi();
        $zBuf = $ffi->new('unsigned char['.self::CRYPTO_CORE_RISTRETTO255_SCALARBYTES.']');
        $xBuf = self::stringToUnsignedCharArray($ffi, $x);
        $yBuf = self::stringToUnsignedCharArray($ffi, $y);
        $ffi->crypto_core_ristretto255_scalar_sub($zBuf, $xBuf, $yBuf);

        return self::unsignedCharArrayToString($zBuf, self::CRYPTO_CORE_RISTRETTO255_SCALARBYTES);
    }

    private static function ffiRistretto255ScalarMul(string $x, string $y): string
    {
        $ffi = self::requireFfi();
        $zBuf = $ffi->new('unsigned char['.self::CRYPTO_CORE_RISTRETTO255_SCALARBYTES.']');
        $xBuf = self::stringToUnsignedCharArray($ffi, $x);
        $yBuf = self::stringToUnsignedCharArray($ffi, $y);
        $ffi->crypto_core_ristretto255_scalar_mul($zBuf, $xBuf, $yBuf);

        return self::unsignedCharArrayToString($zBuf, self::CRYPTO_CORE_RISTRETTO255_SCALARBYTES);
    }

    private static function ffiRistretto255ScalarReduce(string $s): string
    {
        $ffi = self::requireFfi();
        $rBuf = $ffi->new('unsigned char['.self::CRYPTO_CORE_RISTRETTO255_SCALARBYTES.']');
        $sBuf = self::stringToUnsignedCharArray($ffi, $s);
        $ffi->crypto_core_ristretto255_scalar_reduce($rBuf, $sBuf);

        return self::unsignedCharArrayToString($rBuf, self::CRYPTO_CORE_RISTRETTO255_SCALARBYTES);
    }

    private static function ffiScalarmultRistretto255(string $n, string $p): string
    {
        $ffi = self::requireFfi();
        $qBuf = $ffi->new('unsigned char['.self::CRYPTO_SCALARMULT_RISTRETTO255_BYTES.']');
        $nBuf = self::stringToUnsignedCharArray($ffi, $n);
        $pBuf = self::stringToUnsignedCharArray($ffi, $p);
        $rc = $ffi->crypto_scalarmult_ristretto255($qBuf, $nBuf, $pBuf);
        if (0 !== $rc) {
            self::throwSodium('Result is identity element');
        }

        return self::unsignedCharArrayToString($qBuf, self::CRYPTO_SCALARMULT_RISTRETTO255_BYTES);
    }

    private static function ffiScalarmultRistretto255Base(string $n): string
    {
        $ffi = self::requireFfi();
        $qBuf = $ffi->new('unsigned char['.self::CRYPTO_SCALARMULT_RISTRETTO255_BYTES.']');
        $nBuf = self::stringToUnsignedCharArray($ffi, $n);
        $rc = $ffi->crypto_scalarmult_ristretto255_base($qBuf, $nBuf);
        if (0 !== $rc) {
            self::throwSodium('Result is identity element');
        }

        return self::unsignedCharArrayToString($qBuf, self::CRYPTO_SCALARMULT_RISTRETTO255_BYTES);
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

    private static function ffiBoxSeedKeypair(string $seed): string
    {
        $ffi = self::requireFfi();
        $pkBuf = $ffi->new('unsigned char['.self::CRYPTO_BOX_PUBLICKEYBYTES.']');
        $skBuf = $ffi->new('unsigned char['.self::CRYPTO_BOX_SECRETKEYBYTES.']');
        $seedBuf = self::stringToUnsignedCharArray($ffi, $seed);
        $rc = $ffi->crypto_box_seed_keypair($pkBuf, $skBuf, $seedBuf);
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

    private static function ffiAeadAegis128lEncrypt(
        string $message,
        string $additionalData,
        string $nonce,
        string $key
    ): string {
        $ffi = self::requireFfiAegis();
        self::validateAeadAegis128lKeyNonce($key, $nonce, 'sodium_crypto_aead_aegis128l_encrypt', 3, 4);
        $mlen = \strlen($message);
        $adlen = \strlen($additionalData);
        $clen = $mlen + self::CRYPTO_AEAD_AEGIS128L_ABYTES;
        $cBuf = $ffi->new('unsigned char['.$clen.']');
        $clenOut = $ffi->new('unsigned long long');
        $mBuf = $mlen > 0 ? self::stringToUnsignedCharArray($ffi, $message) : null;
        $adBuf = $adlen > 0 ? self::stringToUnsignedCharArray($ffi, $additionalData) : null;
        $npubBuf = self::stringToUnsignedCharArray($ffi, $nonce);
        $kBuf = self::stringToUnsignedCharArray($ffi, $key);
        $rc = $ffi->crypto_aead_aegis128l_encrypt(
            $cBuf,
            \FFI::addr($clenOut),
            $mBuf,
            $mlen,
            $adBuf,
            $adlen,
            null,
            $npubBuf,
            $kBuf
        );
        if (0 !== $rc) {
            throw new \Exception('sodium_crypto_aead_aegis128l_encrypt(): internal error');
        }

        return self::unsignedCharArrayToString($cBuf, $clen);
    }

    /**
     * @return string|false
     */
    private static function ffiAeadAegis128lDecrypt(
        string $ciphertext,
        string $additionalData,
        string $nonce,
        string $key
    ): string|false {
        $ffi = self::requireFfiAegis();
        self::validateAeadAegis128lKeyNonce($key, $nonce, 'sodium_crypto_aead_aegis128l_decrypt', 3, 4);
        $clen = \strlen($ciphertext);
        if ($clen < self::CRYPTO_AEAD_AEGIS128L_ABYTES) {
            return false;
        }
        $mlen = $clen - self::CRYPTO_AEAD_AEGIS128L_ABYTES;
        $mBuf = $mlen > 0 ? $ffi->new('unsigned char['.$mlen.']') : null;
        $mlenOut = $ffi->new('unsigned long long');
        $cBuf = self::stringToUnsignedCharArray($ffi, $ciphertext);
        $adlen = \strlen($additionalData);
        $adBuf = $adlen > 0 ? self::stringToUnsignedCharArray($ffi, $additionalData) : null;
        $npubBuf = self::stringToUnsignedCharArray($ffi, $nonce);
        $kBuf = self::stringToUnsignedCharArray($ffi, $key);
        $rc = $ffi->crypto_aead_aegis128l_decrypt(
            $mBuf,
            \FFI::addr($mlenOut),
            null,
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

        return $mlen > 0 ? self::unsignedCharArrayToString($mBuf, $mlen) : '';
    }

    private static function ffiAeadAegis256Encrypt(
        string $message,
        string $additionalData,
        string $nonce,
        string $key
    ): string {
        $ffi = self::requireFfiAegis();
        self::validateAeadAegis256KeyNonce($key, $nonce, 'sodium_crypto_aead_aegis256_encrypt', 3, 4);
        $mlen = \strlen($message);
        $adlen = \strlen($additionalData);
        $clen = $mlen + self::CRYPTO_AEAD_AEGIS256_ABYTES;
        $cBuf = $ffi->new('unsigned char['.$clen.']');
        $clenOut = $ffi->new('unsigned long long');
        $mBuf = $mlen > 0 ? self::stringToUnsignedCharArray($ffi, $message) : null;
        $adBuf = $adlen > 0 ? self::stringToUnsignedCharArray($ffi, $additionalData) : null;
        $npubBuf = self::stringToUnsignedCharArray($ffi, $nonce);
        $kBuf = self::stringToUnsignedCharArray($ffi, $key);
        $rc = $ffi->crypto_aead_aegis256_encrypt(
            $cBuf,
            \FFI::addr($clenOut),
            $mBuf,
            $mlen,
            $adBuf,
            $adlen,
            null,
            $npubBuf,
            $kBuf
        );
        if (0 !== $rc) {
            throw new \Exception('sodium_crypto_aead_aegis256_encrypt(): internal error');
        }

        return self::unsignedCharArrayToString($cBuf, $clen);
    }

    /**
     * @return string|false
     */
    private static function ffiAeadAegis256Decrypt(
        string $ciphertext,
        string $additionalData,
        string $nonce,
        string $key
    ): string|false {
        $ffi = self::requireFfiAegis();
        self::validateAeadAegis256KeyNonce($key, $nonce, 'sodium_crypto_aead_aegis256_decrypt', 3, 4);
        $clen = \strlen($ciphertext);
        if ($clen < self::CRYPTO_AEAD_AEGIS256_ABYTES) {
            return false;
        }
        $mlen = $clen - self::CRYPTO_AEAD_AEGIS256_ABYTES;
        $mBuf = $mlen > 0 ? $ffi->new('unsigned char['.$mlen.']') : null;
        $mlenOut = $ffi->new('unsigned long long');
        $cBuf = self::stringToUnsignedCharArray($ffi, $ciphertext);
        $adlen = \strlen($additionalData);
        $adBuf = $adlen > 0 ? self::stringToUnsignedCharArray($ffi, $additionalData) : null;
        $npubBuf = self::stringToUnsignedCharArray($ffi, $nonce);
        $kBuf = self::stringToUnsignedCharArray($ffi, $key);
        $rc = $ffi->crypto_aead_aegis256_decrypt(
            $mBuf,
            \FFI::addr($mlenOut),
            null,
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

        return $mlen > 0 ? self::unsignedCharArrayToString($mBuf, $mlen) : '';
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

    private static function ffiSignSeedKeypair(string $seed): string
    {
        $ffi = self::requireFfi();
        $pkBuf = $ffi->new('unsigned char['.self::CRYPTO_SIGN_PUBLICKEYBYTES.']');
        $skBuf = $ffi->new('unsigned char['.self::CRYPTO_SIGN_SECRETKEYBYTES.']');
        $seedBuf = self::stringToUnsignedCharArray($ffi, $seed);
        $rc = $ffi->crypto_sign_seed_keypair($pkBuf, $skBuf, $seedBuf);
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

    private static function ffiSignEd25519SkToCurve25519(string $secretkey): string
    {
        $ffi = self::requireFfi();
        $out = $ffi->new('unsigned char['.self::CRYPTO_BOX_SECRETKEYBYTES.']');
        $skBuf = self::stringToUnsignedCharArray($ffi, $secretkey);
        $rc = $ffi->crypto_sign_ed25519_sk_to_curve25519($out, $skBuf);
        if (0 !== $rc) {
            self::throwSodium('conversion failed');
        }

        return self::unsignedCharArrayToString($out, self::CRYPTO_BOX_SECRETKEYBYTES);
    }

    private static function ffiSignEd25519PkToCurve25519(string $publickey): string
    {
        $ffi = self::requireFfi();
        $out = $ffi->new('unsigned char['.self::CRYPTO_BOX_PUBLICKEYBYTES.']');
        $pkBuf = self::stringToUnsignedCharArray($ffi, $publickey);
        $rc = $ffi->crypto_sign_ed25519_pk_to_curve25519($out, $pkBuf);
        if (0 !== $rc) {
            self::throwSodium('conversion failed');
        }

        return self::unsignedCharArrayToString($out, self::CRYPTO_BOX_PUBLICKEYBYTES);
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

    private static function validateAeadAegis128lKeyNonce(
        string $key,
        string $nonce,
        string $fn,
        int $nonceArg,
        int $keyArg
    ): void {
        if (\strlen($nonce) !== self::CRYPTO_AEAD_AEGIS128L_NPUBBYTES) {
            self::throwSodium(\sprintf(
                '%s(): Argument #%d ($nonce) must be SODIUM_CRYPTO_AEAD_AEGIS128L_NPUBBYTES bytes long',
                $fn,
                $nonceArg
            ));
        }
        if (\strlen($key) !== self::CRYPTO_AEAD_AEGIS128L_KEYBYTES) {
            self::throwSodium(\sprintf(
                '%s(): Argument #%d ($key) must be SODIUM_CRYPTO_AEAD_AEGIS128L_KEYBYTES bytes long',
                $fn,
                $keyArg
            ));
        }
    }

    private static function validateAeadAegis256KeyNonce(
        string $key,
        string $nonce,
        string $fn,
        int $nonceArg,
        int $keyArg
    ): void {
        if (\strlen($nonce) !== self::CRYPTO_AEAD_AEGIS256_NPUBBYTES) {
            self::throwSodium(\sprintf(
                '%s(): Argument #%d ($nonce) must be SODIUM_CRYPTO_AEAD_AEGIS256_NPUBBYTES bytes long',
                $fn,
                $nonceArg
            ));
        }
        if (\strlen($key) !== self::CRYPTO_AEAD_AEGIS256_KEYBYTES) {
            self::throwSodium(\sprintf(
                '%s(): Argument #%d ($key) must be SODIUM_CRYPTO_AEAD_AEGIS256_KEYBYTES bytes long',
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

    private static function validateSignPublickey(string $publickey, string $fn, int $argNum = 1): void
    {
        if (\strlen($publickey) !== self::CRYPTO_SIGN_PUBLICKEYBYTES) {
            self::throwSodium(\sprintf(
                '%s(): Argument #%d ($public_key) must be SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES bytes long',
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

    private static function validateKxKeypair(
        string $keypair,
        string $fn,
        int $argNum = 1,
        string $paramName = 'key_pair'
    ): void {
        if (\strlen($keypair) !== self::CRYPTO_KX_KEYPAIRBYTES) {
            self::throwSodium(\sprintf(
                '%s(): Argument #%d ($%s) must be SODIUM_CRYPTO_KX_KEYPAIRBYTES bytes long',
                $fn,
                $argNum,
                $paramName
            ));
        }
    }

    private static function validateKxPublickey(
        string $publickey,
        string $fn,
        int $argNum,
        string $paramName
    ): void {
        if (\strlen($publickey) !== self::CRYPTO_KX_PUBLICKEYBYTES) {
            self::throwSodium(\sprintf(
                '%s(): Argument #%d ($%s) must be SODIUM_CRYPTO_KX_PUBLICKEYBYTES bytes long',
                $fn,
                $argNum,
                $paramName
            ));
        }
    }

    /**
     * Shared BLAKE2b session-key material (php-src sodium_crypto_kx_*_session_keys).
     *
     * Hash input order is always q || client_pk || server_pk.
     */
    private static function kxSessionKeyMaterial(
        string $sk,
        string $peerPk,
        string $clientPk,
        string $serverPk
    ): string {
        $q = self::scalarmult($sk, $peerPk);

        return self::generichash($q.$clientPk.$serverPk, '', 2 * self::CRYPTO_KX_SESSIONKEYBYTES);
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
                    int crypto_pwhash(unsigned char *out, unsigned long long outlen, const char *passwd, unsigned long long passwdlen, const unsigned char *salt, unsigned long long opslimit, size_t memlimit, int alg);
                    int crypto_pwhash_str(char out[128], const char *passwd, unsigned long long passwdlen, unsigned long long opslimit, size_t memlimit);
                    int crypto_pwhash_str_verify(const char *str, const char *passwd, unsigned long long passwdlen);
                    int crypto_pwhash_str_needs_rehash(const char *str, unsigned long long opslimit, size_t memlimit);
                    int crypto_pwhash_scryptsalsa208sha256(unsigned char *out, unsigned long long outlen, const char *passwd, unsigned long long passwdlen, const unsigned char *salt, unsigned long long opslimit, size_t memlimit);
                    int crypto_pwhash_scryptsalsa208sha256_str(char out[102], const char *passwd, unsigned long long passwdlen, unsigned long long opslimit, size_t memlimit);
                    int crypto_pwhash_scryptsalsa208sha256_str_verify(const char *str, const char *passwd, unsigned long long passwdlen);
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
                    int crypto_core_ristretto255_is_valid_point(const unsigned char *p);
                    void crypto_core_ristretto255_random(unsigned char *p);
                    int crypto_core_ristretto255_from_hash(unsigned char *p, const unsigned char *r);
                    int crypto_core_ristretto255_add(unsigned char *r, const unsigned char *p, const unsigned char *q);
                    int crypto_core_ristretto255_sub(unsigned char *r, const unsigned char *p, const unsigned char *q);
                    void crypto_core_ristretto255_scalar_random(unsigned char *r);
                    int crypto_core_ristretto255_scalar_invert(unsigned char *recip, const unsigned char *s);
                    void crypto_core_ristretto255_scalar_negate(unsigned char *neg, const unsigned char *s);
                    void crypto_core_ristretto255_scalar_complement(unsigned char *comp, const unsigned char *s);
                    void crypto_core_ristretto255_scalar_add(unsigned char *z, const unsigned char *x, const unsigned char *y);
                    void crypto_core_ristretto255_scalar_sub(unsigned char *z, const unsigned char *x, const unsigned char *y);
                    void crypto_core_ristretto255_scalar_mul(unsigned char *z, const unsigned char *x, const unsigned char *y);
                    void crypto_core_ristretto255_scalar_reduce(unsigned char *r, const unsigned char *s);
                    int crypto_scalarmult_ristretto255(unsigned char *q, const unsigned char *n, const unsigned char *p);
                    int crypto_scalarmult_ristretto255_base(unsigned char *q, const unsigned char *n);
                    int crypto_box_keypair(unsigned char *pk, unsigned char *sk);
                    int crypto_box_seed_keypair(unsigned char *pk, unsigned char *sk, const unsigned char *seed);
                    int crypto_box_easy(unsigned char *c, const unsigned char *m, unsigned long long mlen, const unsigned char *n, const unsigned char *pk, const unsigned char *sk);
                    int crypto_box_open_easy(unsigned char *m, const unsigned char *c, unsigned long long clen, const unsigned char *n, const unsigned char *pk, const unsigned char *sk);
                    int crypto_box_seal(unsigned char *c, const unsigned char *m, unsigned long long mlen, const unsigned char *pk);
                    int crypto_box_seal_open(unsigned char *m, const unsigned char *c, unsigned long long clen, const unsigned char *pk, const unsigned char *sk);
                    int crypto_aead_aes256gcm_is_available(void);
                    int crypto_aead_aes256gcm_encrypt(unsigned char *c, unsigned long long *clen_p, const unsigned char *m, unsigned long long mlen, const unsigned char *ad, unsigned long long adlen, const unsigned char *nsec, const unsigned char *npub, const unsigned char *k);
                    int crypto_aead_aes256gcm_decrypt(unsigned char *m, unsigned long long *mlen_p, unsigned char *nsec, const unsigned char *c, unsigned long long clen, const unsigned char *ad, unsigned long long adlen, const unsigned char *npub, const unsigned char *k);
                    int crypto_sign_keypair(unsigned char *pk, unsigned char *sk);
                    int crypto_sign_seed_keypair(unsigned char *pk, unsigned char *sk, const unsigned char *seed);
                    int crypto_sign_ed25519_sk_to_pk(unsigned char *pk, const unsigned char *sk);
                    int crypto_sign_ed25519_sk_to_curve25519(unsigned char *curve25519_sk, const unsigned char *ed25519_sk);
                    int crypto_sign_ed25519_pk_to_curve25519(unsigned char *curve25519_pk, const unsigned char *ed25519_pk);
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
     * Lightweight FFI for sodium_version_string / sodium_library_version_{major,minor} (#24069).
     */
    private static function ffiVersion(): ?\FFI
    {
        if (self::$ffiVersionUnavailable) {
            return null;
        }
        if (null !== self::$ffiVersion) {
            return self::$ffiVersion;
        }
        if (!\extension_loaded('ffi')) {
            self::$ffiVersionUnavailable = true;

            return null;
        }
        $libs = [];
        $env = \getenv('PHP_COMPILER_LIBSODIUM_SO');
        if (\is_string($env) && '' !== $env) {
            $libs[] = $env;
        }
        $libs = \array_merge($libs, ['libsodium.so.26', 'libsodium.so.23', 'libsodium.so']);
        $cdef = 'const char *sodium_version_string(void);
            int sodium_library_version_major(void);
            int sodium_library_version_minor(void);';
        foreach ($libs as $lib) {
            try {
                self::$ffiVersion = \FFI::cdef($cdef, $lib);

                return self::$ffiVersion;
            } catch (\Throwable) {
                continue;
            }
        }
        self::$ffiVersionUnavailable = true;

        return null;
    }

    private static function requireFfiAegis(): \FFI
    {
        $ffi = self::ffiAegis();
        if (null === $ffi) {
            throw new \LogicException('libsodium AEGIS is not available in this compiler build');
        }

        return $ffi;
    }

    /**
     * AEGIS-only FFI — separate from {@see ffi()} so missing AEGIS symbols do not break the core load (#20518).
     *
     * Optional override: PHP_COMPILER_LIBSODIUM_SO=/path/to/libsodium.so (libsodium ≥ 1.0.19).
     */
    private static function ffiAegis(): ?\FFI
    {
        if (self::$ffiAegisUnavailable) {
            return null;
        }
        if (null !== self::$ffiAegis) {
            return self::$ffiAegis;
        }
        if (!\extension_loaded('ffi')) {
            self::$ffiAegisUnavailable = true;

            return null;
        }
        $libs = [];
        $env = \getenv('PHP_COMPILER_LIBSODIUM_SO');
        if (\is_string($env) && '' !== $env) {
            $libs[] = $env;
        }
        $libs = \array_merge($libs, ['libsodium.so.26', 'libsodium.so.23', 'libsodium.so']);
        $cdef = 'int sodium_init(void);
            int crypto_aead_aegis128l_encrypt(unsigned char *c, unsigned long long *clen_p, const unsigned char *m, unsigned long long mlen, const unsigned char *ad, unsigned long long adlen, const unsigned char *nsec, const unsigned char *npub, const unsigned char *k);
            int crypto_aead_aegis128l_decrypt(unsigned char *m, unsigned long long *mlen_p, unsigned char *nsec, const unsigned char *c, unsigned long long clen, const unsigned char *ad, unsigned long long adlen, const unsigned char *npub, const unsigned char *k);
            int crypto_aead_aegis256_encrypt(unsigned char *c, unsigned long long *clen_p, const unsigned char *m, unsigned long long mlen, const unsigned char *ad, unsigned long long adlen, const unsigned char *nsec, const unsigned char *npub, const unsigned char *k);
            int crypto_aead_aegis256_decrypt(unsigned char *m, unsigned long long *mlen_p, unsigned char *nsec, const unsigned char *c, unsigned long long clen, const unsigned char *ad, unsigned long long adlen, const unsigned char *npub, const unsigned char *k);';
        foreach ($libs as $lib) {
            try {
                $ffi = \FFI::cdef($cdef, $lib);
                $ffi->sodium_init();
                self::$ffiAegis = $ffi;

                return self::$ffiAegis;
            } catch (\Throwable) {
                continue;
            }
        }
        self::$ffiAegisUnavailable = true;

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
