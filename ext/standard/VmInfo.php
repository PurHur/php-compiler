<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\CompilerVersion;

/** VM helpers for phpversion() / php_uname() / php_sapi_name() (ext/standard/info.c parity, #3174). */
final class VmInfo
{
    public static function phpversion(?string $extension = null): string|false
    {
        if (null === $extension) {
            return CompilerVersion::VERSION;
        }
        if (!\extension_loaded($extension)) {
            return false;
        }
        $version = \phpversion($extension);

        return false === $version ? false : (string) $version;
    }

    public static function php_sapi_name(): string
    {
        return CompilerVersion::SAPI;
    }

    public static function php_uname(string $mode = 'a'): string
    {
        self::validateUnameMode($mode);

        return \php_uname($mode);
    }

    private static function validateUnameMode(string $mode): void
    {
        if ('' === $mode || !isset($mode[0])) {
            return;
        }
        if (!\in_array($mode[0], ['a', 's', 'n', 'r', 'v', 'm'], true)) {
            throw new \LogicException(
                'php_uname(): Argument #1 ($mode) must be one of "a", "s", "n", "r", "v", or "m"'
            );
        }
    }
}
