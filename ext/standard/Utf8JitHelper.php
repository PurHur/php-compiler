<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Lowered into JIT/AOT modules for __compiler_utf8_* (#9246, php-in-PHP).
 *
 * php-src: ext/standard/basic_functions.c, ext/mbstring/mbstring.c
 * SSOT: ext/standard/VmString.php
 */
final class Utf8JitHelper
{
    public static function utf8CharLength(string $string): int
    {
        return VmString::utf8CharLength($string);
    }

    /** @return int 1 when valid UTF-8, 0 otherwise (LLVM i64 ABI) */
    public static function isValidUtf8(string $string): int
    {
        return VmString::isValidUtf8($string) ? 1 : 0;
    }
}
