<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * VM password_hash() / password_verify() — delegates to host PHP (issue #172).
 */
final class VmPassword
{
    public const PASSWORD_BCRYPT = 1;

    public const PASSWORD_DEFAULT = 1;

    public static function hash(string $password, int $algo, array $options = []): string|false
    {
        if ($algo !== self::PASSWORD_BCRYPT && $algo !== self::PASSWORD_DEFAULT) {
            return false;
        }

        return \password_hash($password, \PASSWORD_BCRYPT, $options);
    }

    public static function verify(string $password, string $hash): bool
    {
        return \password_verify($password, $hash);
    }
}
