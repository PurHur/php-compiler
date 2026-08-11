<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\NestedJitCompileScope;

/**
 * VM password_hash()/password_verify()/crypt() — bcrypt via {@see VmPasswordPure}, Argon2 via libargon2 FFI (#4794, #6906, #8731, #14182).
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

    private static function bcryptDefaultCost(): int
    {
        return VmPassword::bcryptDefaultCost();
    }

    private const BCRYPT_MIN_COST = 4;

    private const BCRYPT_MAX_COST = 31;

    private const ARGON2_HASH_LEN = 32;

    private const ARGON2_SALT_RAW_LEN = 16;

    private const ARGON2_DEFAULT_MEMORY_COST = 65536;

    private const ARGON2_DEFAULT_TIME_COST = 4;

    private const ARGON2_DEFAULT_THREADS = 1;

    private const ARGON2_MIN_MEMORY = 8;

    private const ARGON2_TYPE_I = 1;

    private const ARGON2_TYPE_ID = 2;

    private const ARGON2_VERSION = 19;

    private static ?\FFI $argon2Ffi = null;

    private static bool $argon2FfiUnavailable = false;

    public static function available(): bool
    {
        return VmPasswordPure::available();
    }

    /**
     * @param array<string, mixed> $options
     */
    public static function argon2Available(): bool
    {
        return null !== self::argon2Ffi();
    }

    public static function passwordHash(string $password, int $algo, array $options = []): string|false
    {
        if (self::PASSWORD_ARGON2I === $algo || self::PASSWORD_ARGON2ID === $algo) {
            return self::argon2Hash($password, $algo, $options);
        }
        if ($algo !== self::PASSWORD_BCRYPT) {
            return false;
        }

        $cost = self::resolveBcryptCost($options);
        if (null === $cost) {
            return false;
        }

        try {
            $rnd = self::secureRandomBytes(16);
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
            return self::argon2Verify($password, $hash);
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

    /**
     * @param array<string, mixed> $options
     */
    private static function argon2Hash(string $password, int $algo, array $options): string|false
    {
        $ffi = self::argon2Ffi();
        if (null === $ffi) {
            return false;
        }

        $resolved = self::resolveArgon2Options($options);
        if (null === $resolved) {
            return false;
        }
        [$memoryCost, $timeCost, $threads] = $resolved;
        $type = self::PASSWORD_ARGON2I === $algo ? self::ARGON2_TYPE_I : self::ARGON2_TYPE_ID;

        try {
            $saltEncoded = self::bcryptEncodeSalt22(self::secureRandomBytes(self::ARGON2_SALT_RAW_LEN));
        } catch (\Throwable) {
            return false;
        }

        $saltLen = \strlen($saltEncoded);
        $encodedLen = (int) $ffi->argon2_encodedlen(
            $timeCost,
            $memoryCost,
            $threads,
            $saltLen,
            self::ARGON2_HASH_LEN,
            $type
        );
        if ($encodedLen <= 1) {
            return false;
        }

        try {
            $hashBuf = $ffi->new('char['.self::ARGON2_HASH_LEN.']', false);
            $encodedBuf = $ffi->new('char['.$encodedLen.']', false);
            $pwdBuf = self::copyCString($ffi, $password);
            $saltBuf = self::copyCString($ffi, $saltEncoded);
            $status = $ffi->argon2_hash(
                $timeCost,
                $memoryCost,
                $threads,
                $pwdBuf,
                \strlen($password),
                $saltBuf,
                $saltLen,
                $hashBuf,
                self::ARGON2_HASH_LEN,
                $encodedBuf,
                $encodedLen,
                $type,
                self::ARGON2_VERSION
            );
            if (0 !== $status) {
                return false;
            }

            return \FFI::string($encodedBuf, $encodedLen - 1);
        } catch (\Throwable) {
            return false;
        }
    }

    private static function argon2Verify(string $password, string $hash): bool
    {
        $ffi = self::argon2Ffi();
        if (null === $ffi) {
            return false;
        }

        if (str_starts_with($hash, '$argon2i$')) {
            $type = self::ARGON2_TYPE_I;
        } elseif (str_starts_with($hash, '$argon2id$')) {
            $type = self::ARGON2_TYPE_ID;
        } else {
            return false;
        }

        try {
            $pwdBuf = self::copyCString($ffi, $password);

            return 0 === $ffi->argon2_verify($hash, $pwdBuf, \strlen($password), $type);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array{0: int, 1: int, 2: int}|null
     */
    private static function resolveArgon2Options(array $options): ?array
    {
        $memoryCost = self::ARGON2_DEFAULT_MEMORY_COST;
        $timeCost = self::ARGON2_DEFAULT_TIME_COST;
        $threads = self::ARGON2_DEFAULT_THREADS;

        if (isset($options['memory_cost'])) {
            $value = $options['memory_cost'];
            if (!\is_int($value)) {
                return null;
            }
            $memoryCost = $value;
        }
        if (isset($options['time_cost'])) {
            $value = $options['time_cost'];
            if (!\is_int($value)) {
                return null;
            }
            $timeCost = $value;
        }
        if (isset($options['threads'])) {
            $value = $options['threads'];
            if (!\is_int($value)) {
                return null;
            }
            $threads = $value;
        }

        if ($memoryCost < self::ARGON2_MIN_MEMORY || $memoryCost > 0xFFFFFFFF) {
            return null;
        }
        if ($timeCost < 1 || $timeCost > 0xFFFFFFFF) {
            return null;
        }
        if ($threads < 1 || $threads > 0xFFFFFF) {
            return null;
        }

        return [$memoryCost, $timeCost, $threads];
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function resolveBcryptCost(array $options): ?int
    {
        $cost = self::bcryptDefaultCost();
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
            throw new \ValueError(\sprintf('Invalid bcrypt cost parameter specified: %d', $cost));
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
            if ($len >= 3 && ('5' === $salt[1] || '6' === $salt[1]) && '$' === $salt[2]) {
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

    /** @throws \RuntimeException when CSPRNG fails */
    private static function secureRandomBytes(int $length): string
    {
        if (NestedJitCompileScope::isActive()) {
            $bytes = __compiler_password_random_bytes($length);
        } else {
            $bytes = \random_bytes($length);
        }
        if (!\is_string($bytes) || \strlen($bytes) !== $length) {
            throw new \RuntimeException('password random bytes failed');
        }

        return $bytes;
    }

    private static function libcrypt(string $key, string $salt): ?string
    {
        return VmPasswordPure::crypt($key, $salt);
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

    private static function argon2Ffi(): ?\FFI
    {
        if (self::$argon2FfiUnavailable) {
            return null;
        }
        if (null !== self::$argon2Ffi) {
            return self::$argon2Ffi;
        }
        if (!\extension_loaded('ffi')
            || !\in_array(strtolower((string) \ini_get('ffi.enable')), ['1', 'true', 'preload'], true)) {
            self::$argon2FfiUnavailable = true;

            return null;
        }

        $cdef = <<<'CDEF'
typedef int argon2_type;
size_t argon2_encodedlen(uint32_t t_cost, uint32_t m_cost, uint32_t parallelism, uint32_t saltlen, uint32_t hashlen, argon2_type type);
int argon2_hash(uint32_t t_cost, uint32_t m_cost, uint32_t parallelism, void *pwd, size_t pwdlen, void *salt, size_t saltlen, void *hash, size_t hashlen, char *encoded, size_t encodedlen, argon2_type type, uint32_t version);
int argon2_verify(const char *encoded, void *pwd, size_t pwdlen, argon2_type type);
CDEF;

        foreach (['libargon2.so.1', 'libargon2.so'] as $lib) {
            try {
                self::$argon2Ffi = \FFI::cdef($cdef, $lib);

                return self::$argon2Ffi;
            } catch (\Throwable) {
            }
        }

        self::$argon2FfiUnavailable = true;

        return null;
    }
}
