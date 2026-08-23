<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

/**
 * mb_convert_case() TITLE/TITLE_SIMPLE NestedJIT runtime (#34284 leftover of #34280).
 *
 * Separate TU from {@see MbCaseJitHelper} so helper-runtime cache hits for
 * strtoupper/strtolower/ucfirst/lcfirst do not skip NestedJIT of title helpers
 * (and so we do not re-emit those cached symbols into the user module).
 *
 * SSOT: {@see VmMbstring::convertCase()}
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_convert_case)
 */
final class MbConvertCaseJitHelper
{
    public static function titleArgv(string $string, string $encoding): string
    {
        return VmMbstring::convertCase(
            $string,
            MbstringConstants::MB_CASE_TITLE,
            $encoding,
            'mb_convert_case',
            2
        );
    }

    public static function titleSimpleArgv(string $string, string $encoding): string
    {
        return VmMbstring::convertCase(
            $string,
            MbstringConstants::MB_CASE_TITLE_SIMPLE,
            $encoding,
            'mb_convert_case',
            2
        );
    }
}
