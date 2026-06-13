<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

/**
 * Lowered into JIT/AOT modules that call mb_strcut() at runtime (#4573, php-in-PHP).
 *
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_strcut).
 */
final class MbStrcutJitHelper
{
    /** @param int $length negative means null (cut to end) */
    public static function strcut(string $string, int $from, int $length, string $encoding): string
    {
        return VmMbstring::strcut(
            $string,
            $from,
            $length < 0 ? null : $length,
            $encoding
        );
    }
}
