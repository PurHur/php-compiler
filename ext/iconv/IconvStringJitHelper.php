<?php

declare(strict_types=1);

namespace PHPCompiler\ext\iconv;

/**
 * iconv_substr() for compiled JIT/AOT modules (#27197, php-in-PHP).
 *
 * SSOT: {@see VmIconv::iconvSubstr()}
 * php-src: ext/iconv/iconv.c — PHP_FUNCTION(iconv_substr)
 */
final class IconvStringJitHelper
{
    /** Marker for "length omitted / null" (avoids a third int64 ABI flag). */
    public const LENGTH_OMITTED = -9223372036854775806;

    /**
     * @return string|null null when substr fails (JIT ABI uses null __string__*)
     */
    public static function substrArgv(
        string $input,
        int $offset,
        int $lengthOrOmitted,
        string $encoding
    ): ?string {
        $length = self::LENGTH_OMITTED === $lengthOrOmitted ? null : $lengthOrOmitted;
        $result = VmIconv::iconvSubstr($input, $offset, $length, $encoding);
        if (false === $result) {
            return null;
        }

        return $result;
    }
}
