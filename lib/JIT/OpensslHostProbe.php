<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

/**
 * Process-wide openssl host availability for link/tests without a JIT Context (#36204).
 *
 * Registered from {@see \PHPCompiler\ext\openssl\Module::init} so {@code lib/AOT/Linker}
 * and static Builtin probes do not import {@code ext\openssl}. Before registration,
 * falls back to a libcrypto.so.3 presence check (peer argon2/sodium in Linker).
 */
final class OpensslHostProbe
{
    /** @var (callable(): bool)|null */
    public static $cipherAvailable = null;

    /** @var (callable(): bool)|null */
    public static $signAvailable = null;

    /** @var (callable(): bool)|null */
    public static $pkeyAvailable = null;

    public static function cipherAvailable(): bool
    {
        if (null !== self::$cipherAvailable) {
            return (bool) (self::$cipherAvailable)();
        }

        return self::libcryptoPresent();
    }

    public static function signAvailable(): bool
    {
        if (null !== self::$signAvailable) {
            return (bool) (self::$signAvailable)();
        }

        return self::libcryptoPresent();
    }

    public static function pkeyAvailable(): bool
    {
        if (null !== self::$pkeyAvailable) {
            return (bool) (self::$pkeyAvailable)();
        }

        return self::libcryptoPresent();
    }

    public static function anyCryptoAvailable(): bool
    {
        return self::signAvailable() || self::pkeyAvailable();
    }

    private static function libcryptoPresent(): bool
    {
        $candidates = [
            '/usr/lib/x86_64-linux-gnu/libcrypto.so.3',
            '/usr/lib/aarch64-linux-gnu/libcrypto.so.3',
            '/usr/lib/libcrypto.so.3',
            '/usr/lib/libcrypto.so',
        ];
        foreach ($candidates as $path) {
            if (\is_file($path)) {
                return true;
            }
        }

        return false;
    }
}
