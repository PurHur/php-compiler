<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sodium;

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

    private static ?\FFI $ffi = null;

    private static bool $ffiUnavailable = false;

    public static function available(): bool
    {
        return \function_exists('sodium_crypto_secretbox') || null !== self::ffi();
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

    private static function validateKeyNonce(string $key, string $nonce): void
    {
        if (\strlen($key) !== self::CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \Exception('sodium_crypto_secretbox(): invalid key size');
        }
        if (\strlen($nonce) !== self::CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \Exception('sodium_crypto_secretbox(): invalid nonce size');
        }
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
                    int crypto_secretbox_open(unsigned char *m, const unsigned char *c, unsigned long long clen, const unsigned char *n, const unsigned char *k);',
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
