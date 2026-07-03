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

    private static ?\FFI $ffi = null;

    private static bool $ffiUnavailable = false;

    public static function available(): bool
    {
        return \function_exists('sodium_crypto_secretbox')
            || \function_exists('sodium_crypto_auth')
            || \function_exists('sodium_crypto_stream')
            || \function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')
            || null !== self::ffi();
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

    public static function auth(string $message, string $key): string
    {
        if (\function_exists('sodium_crypto_auth')) {
            return \sodium_crypto_auth($message, $key);
        }

        return self::ffiAuth($message, $key);
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
                    int crypto_stream(unsigned char *c, unsigned long long clen, const unsigned char *n, const unsigned char *k);
                    int crypto_stream_xor(unsigned char *c, const unsigned char *m, unsigned long long mlen, const unsigned char *n, const unsigned char *k);
                    int crypto_stream_xchacha20(unsigned char *c, unsigned long long clen, const unsigned char *n, const unsigned char *k);
                    int crypto_stream_xchacha20_xor(unsigned char *c, const unsigned char *m, unsigned long long mlen, const unsigned char *n, const unsigned char *k);
                    int crypto_stream_xchacha20_xor_ic(unsigned char *c, const unsigned char *m, unsigned long long mlen, const unsigned char *n, const unsigned char k[32], unsigned long long ic);
                    int crypto_aead_xchacha20poly1305_ietf_encrypt(unsigned char *c, unsigned long long *clen_p, const unsigned char *m, unsigned long long mlen, const unsigned char *ad, unsigned long long adlen, const unsigned char *nsec, const unsigned char *npub, const unsigned char *k);
                    int crypto_aead_xchacha20poly1305_ietf_decrypt(unsigned char *m, unsigned long long *mlen_p, unsigned char *nsec, const unsigned char *c, unsigned long long clen, const unsigned char *ad, unsigned long long adlen, const unsigned char *npub, const unsigned char *k);
                    int sodium_memcmp(const unsigned char *s1, const unsigned char *s2, size_t len);',
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
