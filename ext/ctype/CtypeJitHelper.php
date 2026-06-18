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
    /** @return bool LLVM i1 ABI; bridge zext to i32 for __phpc_ctype_* */
    public static function checkString(string $text, int $kind): bool
    {
        return VmCtype::checkString($text, $kind);
    }

    /** @return bool LLVM i1 ABI; bridge zext to i32 for __phpc_ctype_* */
    public static function checkInt(int $value, int $kind, int $allowDigits, int $allowMinus): bool
    {
        return VmCtype::checkInt(
            $value,
            $kind,
            0 !== $allowDigits,
            0 !== $allowMinus
        );
    }
}
