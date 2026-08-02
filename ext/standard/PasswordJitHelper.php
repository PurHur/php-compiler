<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;

/**
 * password_* / crypt() for compiled JIT/AOT modules (#9908, php-in-PHP).
 *
 * SSOT: {@see VmPassword}, {@see VmPasswordNative}
 * php-src: ext/standard/password.c, ext/standard/crypt.c
 */
final class PasswordJitHelper
{
    /**
     * @return string empty string when hash fails (NestedJIT/?string returns were nullled under AOT — #26773)
     *
     * Do not gate on {@see VmPassword::PASSWORD_BCRYPT} with `!==` here: NestedJIT/AOT
     * class-const identity against the i64 algo param rejected bcrypt algo=1 (#26773).
     * Delegate all algos (bcrypt + argon2*) to {@see VmPassword::hash}.
     */
    public static function hashArgv(string $password, int $algo, int $cost): string
    {
        $options = [];
        if ($cost > 0) {
            $options['cost'] = $cost;
        }
        $result = VmPassword::hash($password, $algo, $options);

        return false === $result ? '' : $result;
    }

    public static function verifyArgv(string $password, string $hash): int
    {
        return VmPassword::verify($password, $hash) ? 1 : 0;
    }

    public static function cryptArgv(string $password, string $salt): string
    {
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
}
