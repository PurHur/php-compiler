<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

/**
 * mb_convert_kana() NestedJIT runtime (#34294 leftover of #13099).
 *
 * SSOT: {@see KanaConvert::convert()}
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_convert_kana)
 */
final class MbConvertKanaJitHelper
{
    /**
     * Explicit $mode (incl. empty string after soft-null coerce — #24209).
     */
    public static function convertArgv(string $string, string $mode, string $encoding): string
    {
        return KanaConvert::convert($string, $mode, $encoding);
    }

    /**
     * Omitted $mode → php-src default "KV" (null option in {@see KanaConvert::convert}).
     */
    public static function convertDefaultArgv(string $string, string $encoding): string
    {
        return KanaConvert::convert($string, null, $encoding);
    }
}
