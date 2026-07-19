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

    /** php-src / ICU U_MISSING_RESOURCE_ERROR */
    public const U_MISSING_RESOURCE_ERROR = 2;

    /** php-src / ICU U_PARSE_ERROR */
    public const U_PARSE_ERROR = 9;

    /** php-src U_USING_FALLBACK_WARNING — not a failure for intl_is_failure() */
    public const U_USING_FALLBACK_WARNING = -128;

    private static int $code = self::U_ZERO_ERROR;

    private static string $message = '';

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
        self::$message = '';
    }

    /** php-src PHP_FUNCTION(intl_is_failure) */
    public static function isFailure(int $code): bool
    {
        return self::U_ZERO_ERROR !== $code && self::U_USING_FALLBACK_WARNING !== $code;
    }
}
