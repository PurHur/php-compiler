<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

/**
 * Last intl error state (php-src ext/intl/intl_error.c — intl_error_set / intl_error_get).
 *
 * PHP-in-PHP request slot; future intl builtins call {@see set()} on ICU/UErrorCode failures.
 */
final class IntlError
{
    /** php-src U_ZERO_ERROR */
    public const U_ZERO_ERROR = 0;

    /** php-src / ICU U_ILLEGAL_ARGUMENT_ERROR */
    public const U_ILLEGAL_ARGUMENT_ERROR = 1;

    /**
     * php-src / ICU U_INVALID_ID — utrans_openU unknown transliterator id (#25355).
     * Value is U_ERROR_WARNING_START + 1 (0x10001).
     */
    public const U_INVALID_ID = 65569;

    /** php-src / ICU U_MISSING_RESOURCE_ERROR */
    public const U_MISSING_RESOURCE_ERROR = 2;

    /** php-src / ICU U_PARSE_ERROR */
    public const U_PARSE_ERROR = 9;

    /** php-src / ICU U_INTERNAL_PROGRAM_ERROR */
    public const U_INTERNAL_PROGRAM_ERROR = 5;

    /** php-src / ICU U_UNMATCHED_BRACES — MessageFormat create/parse (#22577). */
    public const U_UNMATCHED_BRACES = 65801;

    /** php-src / ICU U_UNSUPPORTED_ERROR — numfmt_create bad style (#25204). */
    public const U_UNSUPPORTED_ERROR = 16;

    /** php-src / ICU U_USING_FALLBACK_WARNING — not a failure for intl_is_failure() */
    public const U_USING_FALLBACK_WARNING = -128;

    /** php-src / ICU U_USING_DEFAULT_WARNING */
    public const U_USING_DEFAULT_WARNING = -127;

    private static int $code = self::U_ZERO_ERROR;

    /** Idle / cleared state matches php-src intl_error_reset → "U_ZERO_ERROR" (#22577). */
    private static string $message = 'U_ZERO_ERROR';

    private static ?\FFI $errorNameFfi = null;

    private static string $errorNameSym = '';

    private static bool $errorNameFfiUnavailable = false;

    public static function getCode(): int
    {
        return self::$code;
    }

    public static function getMessage(): string
    {
        return self::$message;
    }

    public static function set(int $code, string $message): void
    {
        self::$code = $code;
        self::$message = $message;
    }

    public static function clear(): void
    {
        self::$code = self::U_ZERO_ERROR;
        self::$message = 'U_ZERO_ERROR';
    }

    /** php-src PHP_FUNCTION(intl_is_failure) */
    public static function isFailure(int $code): bool
    {
        return self::U_ZERO_ERROR !== $code && self::U_USING_FALLBACK_WARNING !== $code;
    }

    /**
     * php-src PHP_FUNCTION(intl_error_name) — ICU u_errorName (#20872).
     */
    public static function errorName(int $code): string
    {
        $ffi = self::errorNameFfi();
        if (null !== $ffi) {
            $sym = self::$errorNameSym;
            $cstr = $ffi->$sym($code);
            if (\is_string($cstr) && '' !== $cstr) {
                return $cstr;
            }
            if ($cstr instanceof \FFI\CData) {
                $name = \FFI::string($cstr);
                if ('' !== $name) {
                    return $name;
                }
            }
        }

        return self::errorNameFallback($code);
    }

    private static function errorNameFfi(): ?\FFI
    {
        if (self::$errorNameFfiUnavailable) {
            return null;
        }
        if (null !== self::$errorNameFfi) {
            return self::$errorNameFfi;
        }
        if (!\class_exists(\FFI::class, false) && !\extension_loaded('FFI')) {
            self::$errorNameFfiUnavailable = true;

            return null;
        }
        /** @var list<array{0: string, 1: string}> */
        $candidates = [
            ['libicuuc.so.74', '_74'],
            ['libicuuc.so.72', '_72'],
            ['libicuuc.so.71', '_71'],
            ['libicuuc.so.70', '_70'],
            ['libicuuc.so', '_70'],
            ['libicuuc.dylib', ''],
        ];
        foreach ($candidates as [$lib, $suffix]) {
            try {
                $sym = 'u_errorName'.$suffix;
                $ffi = \FFI::cdef('const char *'.$sym.'(int code);', $lib);
                $ffi->$sym(0);
                self::$errorNameFfi = $ffi;
                self::$errorNameSym = $sym;

                return self::$errorNameFfi;
            } catch (\Throwable) {
                self::$errorNameFfi = null;
            }
        }
        self::$errorNameFfiUnavailable = true;

        return null;
    }

    /** Static ICU names when FFI/libicuuc is unavailable (common codes only). */
    private static function errorNameFallback(int $code): string
    {
        static $names = [
            0 => 'U_ZERO_ERROR',
            1 => 'U_ILLEGAL_ARGUMENT_ERROR',
            2 => 'U_MISSING_RESOURCE_ERROR',
            3 => 'U_INVALID_FORMAT_ERROR',
            4 => 'U_FILE_ACCESS_ERROR',
            5 => 'U_INTERNAL_PROGRAM_ERROR',
            6 => 'U_MESSAGE_PARSE_ERROR',
            7 => 'U_MEMORY_ALLOCATION_ERROR',
            8 => 'U_INDEX_OUTOFBOUNDS_ERROR',
            9 => 'U_PARSE_ERROR',
            10 => 'U_INVALID_CHAR_FOUND',
            15 => 'U_BUFFER_OVERFLOW_ERROR',
            16 => 'U_UNSUPPORTED_ERROR',
            65569 => 'U_INVALID_ID',
            65801 => 'U_UNMATCHED_BRACES',
            -127 => 'U_USING_DEFAULT_WARNING',
            -128 => 'U_USING_FALLBACK_WARNING',
        ];

        return $names[$code] ?? '[BOGUS UErrorCode]';
    }
}
