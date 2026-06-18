<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ctype;

/**
 * Lowered into JIT/AOT modules for ctype_* classification (#9234, php-in-PHP).
 *
 * php-src: ext/ctype/ctype.c
 * SSOT: ext/ctype/VmCtype.php
 */
final class CtypeJitHelper
{
    /** @return int 1 when all bytes match, 0 otherwise (LLVM i32 ABI) */
    public static function checkString(string $text, int $kind): int
    {
        return VmCtype::checkString($text, $kind) ? 1 : 0;
    }

    /** @return int 1 when the code point matches, 0 otherwise (LLVM i32 ABI) */
    public static function checkInt(int $value, int $kind, int $allowDigits, int $allowMinus): int
    {
        return VmCtype::checkInt(
            $value,
            $kind,
            0 !== $allowDigits,
            0 !== $allowMinus
        ) ? 1 : 0;
    }
}
