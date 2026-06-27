<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * json_encode() / json_decode() / json_validate() flag bits (Zend ext/json/php_json.h).
 */
final class VmJsonFlags
{
    /** @see JSON_HEX_TAG */
    public const HEX_TAG = 1;

    /** @see JSON_OBJECT_AS_ARRAY — json_decode() when $assoc is null (php_json.h, #11778) */
    public const OBJECT_AS_ARRAY = 1;

    /** @see JSON_BIGINT_AS_STRING — json_decode() oversized integers as digit strings (php_json.h) */
    public const BIGINT_AS_STRING = 2;

    /** @see JSON_HEX_AMP */
    public const HEX_AMP = 2;

    /** @see JSON_HEX_APOS */
    public const HEX_APOS = 4;

    /** @see JSON_HEX_QUOT */
    public const HEX_QUOT = 8;

    /** @see JSON_FORCE_OBJECT */
    public const FORCE_OBJECT = 16;

    /** @see JSON_NUMERIC_CHECK */
    public const NUMERIC_CHECK = 32;

    /** @see JSON_UNESCAPED_SLASHES */
    public const UNESCAPED_SLASHES = 64;

    /** @see JSON_PRETTY_PRINT */
    public const PRETTY_PRINT = 128;

    /** @see JSON_UNESCAPED_UNICODE */
    public const UNESCAPED_UNICODE = 256;

    /** @see JSON_PARTIAL_OUTPUT_ON_ERROR */
    public const PARTIAL_OUTPUT_ON_ERROR = 512;

    /** @see JSON_PRESERVE_ZERO_FRACTION */
    public const PRESERVE_ZERO_FRACTION = 1024;

    /** @see JSON_UNESCAPED_LINE_TERMINATORS */
    public const UNESCAPED_LINE_TERMINATORS = 2048;

    /** @see JSON_INVALID_UTF8_IGNORE */
    public const INVALID_UTF8_IGNORE = 1048576;

    /** @see JSON_INVALID_UTF8_SUBSTITUTE */
    public const INVALID_UTF8_SUBSTITUTE = 2097152;

    /** @see JSON_THROW_ON_ERROR */
    public const THROW_ON_ERROR = 4194304;

    /** Flags honored by json_encode() in this compiler build (issue #3281, #10555, #10601, #10954, #10956). */
    public const ENCODE_SUPPORTED = self::HEX_TAG
        | self::HEX_AMP
        | self::HEX_APOS
        | self::HEX_QUOT
        | self::UNESCAPED_SLASHES
        | self::PRETTY_PRINT
        | self::UNESCAPED_UNICODE
        | self::PARTIAL_OUTPUT_ON_ERROR
        | self::THROW_ON_ERROR
        | self::FORCE_OBJECT
        | self::NUMERIC_CHECK
        | self::PRESERVE_ZERO_FRACTION;

    /** Flags honored by json_decode() in this compiler build (issue #3267, #11778, #12496). */
    public const DECODE_SUPPORTED = self::OBJECT_AS_ARRAY
        | self::BIGINT_AS_STRING
        | self::INVALID_UTF8_IGNORE
        | self::INVALID_UTF8_SUBSTITUTE
        | self::THROW_ON_ERROR;

    /** Flags accepted by json_validate() (issue #4085). */
    public const VALIDATE_ALLOWED = self::INVALID_UTF8_IGNORE | self::INVALID_UTF8_SUBSTITUTE;

    /** @return array<string, int> */
    public static function constants(): array
    {
        return [
            'JSON_HEX_TAG' => self::HEX_TAG,
            'JSON_OBJECT_AS_ARRAY' => self::OBJECT_AS_ARRAY,
            'JSON_BIGINT_AS_STRING' => self::BIGINT_AS_STRING,
            'JSON_HEX_AMP' => self::HEX_AMP,
            'JSON_HEX_APOS' => self::HEX_APOS,
            'JSON_HEX_QUOT' => self::HEX_QUOT,
            'JSON_FORCE_OBJECT' => self::FORCE_OBJECT,
            'JSON_NUMERIC_CHECK' => self::NUMERIC_CHECK,
            'JSON_UNESCAPED_SLASHES' => self::UNESCAPED_SLASHES,
            'JSON_PRETTY_PRINT' => self::PRETTY_PRINT,
            'JSON_UNESCAPED_UNICODE' => self::UNESCAPED_UNICODE,
            'JSON_PARTIAL_OUTPUT_ON_ERROR' => self::PARTIAL_OUTPUT_ON_ERROR,
            'JSON_PRESERVE_ZERO_FRACTION' => self::PRESERVE_ZERO_FRACTION,
            'JSON_UNESCAPED_LINE_TERMINATORS' => self::UNESCAPED_LINE_TERMINATORS,
            'JSON_INVALID_UTF8_IGNORE' => self::INVALID_UTF8_IGNORE,
            'JSON_INVALID_UTF8_SUBSTITUTE' => self::INVALID_UTF8_SUBSTITUTE,
            'JSON_THROW_ON_ERROR' => self::THROW_ON_ERROR,
            'JSON_ERROR_NONE' => 0,
            'JSON_ERROR_DEPTH' => 1,
            'JSON_ERROR_STATE_MISMATCH' => 2,
            'JSON_ERROR_CTRL_CHAR' => 3,
            'JSON_ERROR_SYNTAX' => 4,
            'JSON_ERROR_UTF8' => 5,
            'JSON_ERROR_RECURSION' => 6,
            'JSON_ERROR_INF_OR_NAN' => 7,
            'JSON_ERROR_UNSUPPORTED_TYPE' => VmJson::ERROR_UNSUPPORTED_TYPE,
        ];
    }

    public static function assertValidateFlags(int $flags): void
    {
        if (0 !== ($flags & ~self::VALIDATE_ALLOWED)) {
            throw new \ValueError(
                'json_validate(): Argument #3 ($flags) must be a valid flag (allowed flags: JSON_INVALID_UTF8_IGNORE, JSON_INVALID_UTF8_SUBSTITUTE)'
            );
        }
    }

    public static function ignoreInvalidUtf8(int $flags): bool
    {
        return 0 !== ($flags & (self::INVALID_UTF8_IGNORE | self::INVALID_UTF8_SUBSTITUTE));
    }

    public static function throwsOnError(int $flags): bool
    {
        return 0 !== ($flags & self::THROW_ON_ERROR);
    }

    public static function partialOutputOnError(int $flags): bool
    {
        return 0 !== ($flags & self::PARTIAL_OUTPUT_ON_ERROR);
    }

    /** json_decode(): decode JSON objects as PHP arrays when $assoc is null (#11778). */
    public static function objectAsArray(int $flags): bool
    {
        return 0 !== ($flags & self::OBJECT_AS_ARRAY);
    }

    /** json_decode(): return integers wider than signed 64-bit as digit strings (#12495). */
    public static function bigintAsString(int $flags): bool
    {
        return 0 !== ($flags & self::BIGINT_AS_STRING);
    }
}
