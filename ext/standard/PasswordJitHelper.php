<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\VM\HashTable;

/**
 * password_* / crypt() for compiled JIT/AOT modules (#9908, php-in-PHP).
 *
 * SSOT host/VM: {@see VmPassword}, {@see VmPasswordNative}
 * NestedJIT/AOT: thin {@see phpc_libcrypt_kernel} / {@see phpc_argon2_hash} (#26773) —
 * VmPasswordNative cannot NestedJIT (FFI); unbound stubs returned false under AOT.
 * php-src: ext/standard/password.c, ext/standard/crypt.c
 */
final class PasswordJitHelper
{
    /**
     * @return string empty string when hash fails (NestedJIT/?string returns were nullled under AOT — #26773)
     *
     * Do not gate on {@see VmPassword::PASSWORD_BCRYPT} with `!==` here: NestedJIT/AOT
     * class-const identity against the i64 algo param rejected bcrypt algo=1 (#26773).
     */
    public static function hashArgv(string $password, int $algo, int $cost): string
    {
        if (NestedJitCompileScope::isActive()) {
            return self::hashArgvThin($password, $algo, $cost);
        }
        $options = [];
        if ($cost > 0) {
            $options['cost'] = $cost;
        }
        $result = VmPassword::hash($password, $algo, $options);

        return false === $result ? '' : $result;
    }

    public static function verifyArgv(string $password, string $hash): int
    {
        if (NestedJitCompileScope::isActive()) {
            return self::verifyArgvThin($password, $hash);
        }

        return VmPassword::verify($password, $hash) ? 1 : 0;
    }

    public static function cryptArgv(string $password, string $salt): string
    {
        if (NestedJitCompileScope::isActive()) {
            $result = \phpc_libcrypt_kernel($password, $salt);
            if (!\is_string($result)) {
                return '*0';
            }
            if ('' === $result) {
                return '*0';
            }
            if ('*' === $result[0]) {
                return '*0';
            }

            return $result;
        }

        return VmPassword::crypt($password, $salt);
    }

    public static function getInfoHashtable(string $hash): HashTable
    {
        return VmPassword::infoToHashTable(VmPassword::getInfo($hash));
    }

    public static function needsRehashArgv(string $hash, int $algo, int $cost): int
    {
        $options = [];
        if ($cost > 0) {
            $options['cost'] = $cost;
        }

        return VmPassword::needsRehash($hash, $algo, $options) ? 1 : 0;
    }

    public static function algosHashtable(): HashTable
    {
        return VmPassword::algos();
    }

    private static function hashArgvThin(string $password, int $algo, int $cost): string
    {
        // Literal type ints only — NestedJIT miscompiles class-const ternaries (#26773).
        // Argon2_i=1, Argon2_id=2 (libargon2); algo 2/3 are VmPassword::PASSWORD_ARGON2*.
        if (2 === $algo) {
            return self::argon2HashThin($password, 1);
        }
        if (3 === $algo) {
            return self::argon2HashThin($password, 2);
        }
        if (1 !== $algo) {
            return '';
        }
        $bcryptCost = $cost > 0 ? $cost : 10;
        if ($bcryptCost < 4 || $bcryptCost > 31) {
            return '';
        }
        $rnd = \random_bytes(16);
        if (!\is_string($rnd)) {
            return '';
        }
        if (16 !== \strlen($rnd)) {
            return '';
        }
        $costTwo = ($bcryptCost < 10 ? '0' : '').(string) $bcryptCost;
        $setting = '$2y$'.$costTwo.'$'.self::bcryptEncodeSalt22($rnd);
        $result = \phpc_libcrypt_kernel($password, $setting);
        if (!\is_string($result)) {
            return '';
        }

        return $result;
    }

    private static function argon2HashThin(string $password, int $type): string
    {
        $rnd = \random_bytes(16);
        if (!\is_string($rnd)) {
            return '';
        }
        if (16 !== \strlen($rnd)) {
            return '';
        }
        $result = \phpc_argon2_hash($password, $type, 65536, 4, 1, $rnd);
        if (!\is_string($result)) {
            return '';
        }

        return $result;
    }

    private static function verifyArgvThin(string $password, string $hash): int
    {
        if (\str_starts_with($hash, '$argon2i$')) {
            return \phpc_argon2_verify($password, $hash, 1);
        }
        if (\str_starts_with($hash, '$argon2id$')) {
            return \phpc_argon2_verify($password, $hash, 2);
        }
        if (\strlen($hash) < 29) {
            return 0;
        }
        if (!\str_starts_with($hash, '$2y$')) {
            return 0;
        }

        return \phpc_libcrypt_verify($password, $hash);
    }

    private static function bcryptEncodeSalt22(string $rnd16): string
    {
        // Local literal — NestedJIT class-const string can be null (#26773).
        $itoa = './ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
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
}
