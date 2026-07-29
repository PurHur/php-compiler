<?php

declare(strict_types=1);

namespace PHPCompiler\ext\iconv;

/**
 * iconv extension constants (php-src ext/iconv/iconv.c; #6364, #24053).
 */
final class IconvConstants
{
    public const MIME_DECODE_STRICT = 1;
    public const MIME_DECODE_CONTINUE_ON_ERROR = 2;

    /** php-src ICONV_CSNMAXLEN */
    public const ENCODING_NAME_MAX_LEN = 64;

    /**
     * Conversion backend identity (php-src ICONV_IMPL).
     *
     * VmIconv / CharsetEngine are pure-PHP — do not claim glibc/libiconv (#24053).
     */
    public const ICONV_IMPL = 'php-compiler';

    /**
     * CharsetEngine bootstrap subset version string (php-src ICONV_VERSION).
     *
     * Not a libc soname; documents the PHP-in-PHP converter (#6251 / #24053).
     */
    public const ICONV_VERSION = '1.0';

    /** @return array<string, int|string> */
    public static function registeredConstants(): array
    {
        return self::identityConstants() + [
            'ICONV_MIME_DECODE_STRICT' => self::MIME_DECODE_STRICT,
            'ICONV_MIME_DECODE_CONTINUE_ON_ERROR' => self::MIME_DECODE_CONTINUE_ON_ERROR,
        ];
    }

    /**
     * iconv library identity (php-src iconv.c REGISTER_STRING_CONSTANT; #24053).
     *
     * @return array<string, string>
     */
    public static function identityConstants(): array
    {
        return [
            'ICONV_IMPL' => self::ICONV_IMPL,
            'ICONV_VERSION' => self::ICONV_VERSION,
        ];
    }
}
