<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * trim/ltrim/rtrim character-mask membership for compiled JIT/AOT modules (#14908, php-in-PHP).
 *
 * SSOT: {@see VmString::charInTrimMask()}
 * php-src: ext/standard/string.c — php_charmask()
 */
final class CharInMaskJitHelper
{
    public static function charInMaskArgv(int $ch, string $mask): int
    {
        return VmString::charInTrimMask(\chr($ch & 0xFF), $mask) ? 1 : 0;
    }
}
