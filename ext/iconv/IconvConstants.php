<?php

declare(strict_types=1);

namespace PHPCompiler\ext\iconv;

/**
 * iconv extension constants (php-src ext/iconv/iconv.c; #6364).
 */
final class IconvConstants
{
    public const MIME_DECODE_STRICT = 1;
    public const MIME_DECODE_CONTINUE_ON_ERROR = 2;

    /** php-src ICONV_CSNMAXLEN */
    public const ENCODING_NAME_MAX_LEN = 64;

    /** @return array<string, int> */
    public static function registeredConstants(): array
    {
        return [
            'ICONV_MIME_DECODE_STRICT' => self::MIME_DECODE_STRICT,
            'ICONV_MIME_DECODE_CONTINUE_ON_ERROR' => self::MIME_DECODE_CONTINUE_ON_ERROR,
        ];
    }
}
