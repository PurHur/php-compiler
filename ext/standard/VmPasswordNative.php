<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * VM password_hash()/password_verify()/crypt() via libcrypt(3) FFI (#4794, #6906).
 *
 * Mirrors {@see \PHPCompiler\ext\standard\PasswordJitHelper} — no host \\password_* delegation.
 *
 * php-src: ext/standard/password.c, crypt.c
 */
final class VmPasswordNative
{
    private const BCRYPT_ITOA64 = './ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';

    private const PASSWORD_BCRYPT = 1;

    private const PASSWORD_ARGON2I = 2;

    private const PASSWORD_ARGON2ID = 3;

    private const BCRYPT_DEFAULT_COST = 10;

    private const BCRYPT_MIN_COST = 4;

    private const BCRYPT_MAX_COST = 31;

    private static ?\FFI $ffi = null;

    private static bool $ffiUnavailable = false;

    public static function available(): bool
    {
        return null !== self::ffi();
    }

    /**
     * @param array<string, mixed> $options
     */
    public static function argon2Available(): bool
    {
        return \defined('PASSWORD_ARGON2ID') && \function_exists('password_hash');
    }

    public static function passwordHash(string $password, int $algo, array $options = []): string|false
    {
        if (self::PASSWORD_ARGON2I === $algo || self::PASSWORD_ARGON2ID === $algo) {
            return self::hostPasswordHash($password, $algo, $options);
        }
        if ($algo !== self::PASSWORD_BCRYPT) {
            return false;
        }

        $cost = self::resolveBcryptCost($options);
        if (null === $cost) {
            return false;
        }

        try {
            $rnd = \random_bytes(16);
        } catch (\Throwable) {
            return false;
        }

        $salt22 = self::bcryptEncodeSalt22($rnd);
        $setting = \sprintf('$2y$%02d$%s', $cost, $salt22);
        if (\strlen($setting) >= 64) {
            return false;
        }

        $result = self::libcrypt($password, $setting);
        if (null === $result || '' === $result || '*' === $result[0]) {
            return false;
        }

        return $result;
    }

    public static function passwordVerify(string $password, string $hash): bool
    {
        if (str_starts_with($hash, '$argon2')) {
            return self::hostPasswordVerify($password, $hash);
        }
        if (\strlen($hash) < 29 || !str_starts_with($hash, '$2y$')) {
            return false;
        }

        $computed = self::libcrypt($password, $hash);
        if (null === $computed || '' === $computed || '*' === $computed[0]) {
            return false;
        }

        return $computed === $hash;
    }

    public static function crypt(string $password, string $salt): string
    {
        if (self::isStarSalt($salt)) {
            return '*0';
        }

        if (!self::isValidCryptSalt($salt)) {
            return '*0';
        }

        $result = self::libcrypt($password, $salt);
        if (null === $result || '' === $result || '*' === $result[0]) {
            return '*0';
        }

        return $result;
    }

    /** @return list<string> */
    public static function passwordAlgos(): array
    {
        $algos = ['2y'];
        if (self::argon2Available()) {
            $algos[] = 'argon2i';
            $algos[] = 'argon2id';
        }

        return $algos;
    }

    /** @param array<string, mixed> $options */
    private static function hostPasswordHash(string $password, int $algo, array $options): string|false
    {
        if (!self::argon2Available()) {
            return false;
        }
        try {
            $result = \password_hash($password, $algo, $options);
        } catch (\Throwable) {
            return false;
        }

        return \is_string($result) ? $result : false;
    }

    private static function hostPasswordVerify(string $password, string $hash): bool
    {
        if (!self::argon2Available()) {
            return false;
        }
        try {
            return \password_verify($password, $hash);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function resolveBcryptCost(array $options): ?int
    {
        $cost = self::BCRYPT_DEFAULT_COST;
        if (isset($options['cost'])) {
            $costVal = $options['cost'];
            if (\is_int($costVal)) {
                $cost = $costVal;
            } elseif (\is_string($costVal) && '' !== $costVal && is_numeric($costVal)) {
                $cost = (int) $costVal;
            } else {
                return null;
            }
        }
        if ($cost < self::BCRYPT_MIN_COST || $cost > self::BCRYPT_MAX_COST) {
            return null;
        }

        return $cost;
    }

    private static function isStarSalt(string $salt): bool
    {
        return \strlen($salt) >= 2
            && '*' === $salt[0]
            && ('0' === $salt[1] || '1' === $salt[1]);
    }

    private static function isValidCryptSalt(string $salt): bool
    {
        $len = \strlen($salt);
        if ($len < 2) {
            return false;
        }

        if ('$' === $salt[0]) {
            if ($len >= 4 && '2' === $salt[1] && '$' === $salt[3]) {
                return true;
            }
            if ($len >= 3 && '1' === $salt[1] && '$' === $salt[2]) {
                return true;
            }

            return false;
        }

        return self::isValidSaltChar($salt[0]) && self::isValidSaltChar($salt[1]);
    }

    private static function isValidSaltChar(string $char): bool
    {
        if ('' === $char) {
            return false;
        }
        $ord = \ord($char);

        return ($ord >= \ord('.') && $ord <= \ord('9'))
            || ($ord >= \ord('A') && $ord <= \ord('Z'))
            || ($ord >= \ord('a') && $ord <= \ord('z'));
    }

    private static function bcryptEncodeSalt22(string $rnd16): string
    {
        if (16 !== \strlen($rnd16)) {
            throw new \InvalidArgumentException('bcrypt salt requires 16 random bytes');
        }

        $itoa = self::BCRYPT_ITOA64;
        $out = '';
        $len = \strlen($rnd16);
        $i = 0;
        while (\strlen($out) < 22) {
            $c1 = \ord($rnd16[$i++]);
            $c2 = $i < $len ? \ord($rnd16[$i++]) : 0;
            $out .= $itoa[$c1 >> 2];
            if (\strlen($out) >= 22) {
                break;
            }
            $out .= $itoa[(($c1 & 0x03) << 4) | ($c2 >> 4)];
            if (\strlen($out) >= 22) {
                break;
            }
            $c3 = $i < $len ? \ord($rnd16[$i++]) : 0;
            $out .= $itoa[(($c2 & 0x0f) << 2) | ($c3 >> 6)];
            if (\strlen($out) >= 22) {
                break;
            }
            $out .= $itoa[$c3 & 0x3f];
        }

        return \substr($out, 0, 22);
    }

    private static function libcrypt(string $key, string $salt): ?string
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return null;
        }

        try {
            $keyC = self::copyCString($ffi, $key);
            $saltC = self::copyCString($ffi, $salt);
            $result = $ffi->crypt($keyC, $saltC);
            if (null === $result) {
                return null;
            }

            return \FFI::string($result);
        } catch (\Throwable) {
            return null;
        }
    }

    private static function copyCString(\FFI $ffi, string $value): \FFI\CData
    {
        $len = \strlen($value);
        $buf = $ffi->new('char['.($len + 1).']', false);
        if ($len > 0) {
            \FFI::memcpy($buf, $value, $len);
        }
        $buf[$len] = "\0";

        return $buf;
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

        $cdef = <<<'CDEF'
char *crypt(const char *key, const char *salt);
CDEF;

        foreach (['libcrypt.so.1', 'libcrypt.so', 'libc.so.6', 'libc.so'] as $lib) {
            try {
                self::$ffi = \FFI::cdef($cdef, $lib);

                return self::$ffi;
            } catch (\Throwable) {
            }
        }

        self::$ffiUnavailable = true;

        return null;
    }
}
