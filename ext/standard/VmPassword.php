<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * VM password_hash() / password_verify() / crypt() — delegates to host PHP (issue #172, #3771).
 */
final class VmPassword
{
    public const PASSWORD_BCRYPT = 1;

    public const PASSWORD_DEFAULT = 1;

    /** crypt() salt generation flags (ext/standard/password.c — CRYPT_*). */
    public const CRYPT_STD_DES = 1;

    public const CRYPT_EXT_DES = 2;

    public const CRYPT_MD5 = 3;

    public const CRYPT_BLOWFISH = 4;

    public static function hash(string $password, int $algo, array $options = []) {
        if ($algo !== self::PASSWORD_BCRYPT && $algo !== self::PASSWORD_DEFAULT) {
            return false;
        }

        return \password_hash($password, \PASSWORD_BCRYPT, $options);
    }

    public static function verify(string $password, string $hash): bool
    {
        return \password_verify($password, $hash);
    }

    /**
     * crypt() — delegate to host PHP php_crypt() for Zend parity (issue #3771).
     */
    public static function crypt(string $password, string $salt): string
    {
        return \crypt($password, $salt);
    }
}
