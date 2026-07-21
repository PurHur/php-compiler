<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mysqli;

/**
 * ext/mysqli advertisement — php-src ext/mysqli/mysqli.c (#3435).
 *
 * Gate on host ext/mysqli so extension_loaded('mysqli') matches builds that
 * can establish a real connection. Without the host extension, the module still
 * registers class/function stubs for reflection (function_exists, class_exists)
 * but live connect throws.
 */
final class MysqliExtensionPolicy
{
    public static function advertisesExtension(): bool
    {
        return true;
    }

    public static function hasNativeDriver(): bool
    {
        return \function_exists('\\mysqli_connect');
    }

    public static function advertisesClasses(): bool
    {
        return self::advertisesExtension();
    }

    public static function advertisesBuiltins(): bool
    {
        return self::advertisesExtension();
    }
}
