<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * PCRE / preg_* constants (php-src ext/pcre/php_pcre.c; #17799).
 */
final class PcreConstants
{
    /** @return array<string, int|string> */
    public static function registeredConstants(): array
    {
        return [
            'PREG_PATTERN_ORDER' => StdlibConstants::PREG_PATTERN_ORDER,
            'PREG_SET_ORDER' => StdlibConstants::PREG_SET_ORDER,
            'PREG_OFFSET_CAPTURE' => StdlibConstants::PREG_OFFSET_CAPTURE,
            'PREG_UNMATCHED_AS_NULL' => StdlibConstants::PREG_UNMATCHED_AS_NULL,
            'PREG_SPLIT_NO_EMPTY' => StdlibConstants::PREG_SPLIT_NO_EMPTY,
            'PREG_SPLIT_DELIM_CAPTURE' => StdlibConstants::PREG_SPLIT_DELIM_CAPTURE,
            'PREG_SPLIT_OFFSET_CAPTURE' => StdlibConstants::PREG_SPLIT_OFFSET_CAPTURE,
            'PREG_GREP_INVERT' => StdlibConstants::PREG_GREP_INVERT,
            'PREG_NO_ERROR' => StdlibConstants::PREG_NO_ERROR,
            'PREG_INTERNAL_ERROR' => StdlibConstants::PREG_INTERNAL_ERROR,
            'PREG_BACKTRACK_LIMIT_ERROR' => StdlibConstants::PREG_BACKTRACK_LIMIT_ERROR,
            'PREG_RECURSION_LIMIT_ERROR' => StdlibConstants::PREG_RECURSION_LIMIT_ERROR,
            'PREG_BAD_UTF8_ERROR' => StdlibConstants::PREG_BAD_UTF8_ERROR,
            'PREG_BAD_UTF8_OFFSET_ERROR' => StdlibConstants::PREG_BAD_UTF8_OFFSET_ERROR,
            'PREG_JIT_STACKLIMIT_ERROR' => StdlibConstants::PREG_JIT_STACKLIMIT_ERROR,
            'PCRE_VERSION' => '10.44',
            'PCRE_VERSION_MAJOR' => 10,
            'PCRE_VERSION_MINOR' => 44,
            'PCRE_JIT_SUPPORT' => 1,
        ];
    }
}
