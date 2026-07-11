<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * convert_uuencode()/convert_uudecode() for compiled JIT/AOT modules (#13227, php-in-PHP).
 *
 * SSOT: {@see VmString::convert_uuencode()} / {@see VmString::convert_uudecode()}
 * php-src: ext/standard/uuencode.c
 */
final class ConvertUuJitHelper
{
    private const TAG_FALSE = 0;

    private const TAG_STRING = 1;

    private const MSG_INVALID = 'convert_uudecode(): Argument #1 ($data) is not a valid uuencoded string';

    private static string $lastString = '';

    public static function encode(string $data): string
    {
        return VmString::convert_uuencode($data);
    }

    /** @return int TAG_FALSE or TAG_STRING */
    public static function decodeTag(string $data): int
    {
        $result = VmString::convert_uudecode($data);
        if (false === $result) {
            TriggerErrorJitHelper::warning(self::MSG_INVALID);

            return self::TAG_FALSE;
        }
        self::$lastString = $result;

        return self::TAG_STRING;
    }

    public static function lastString(): string
    {
        return self::$lastString;
    }

    /** @internal test reset */
    public static function resetForTest(): void
    {
        self::$lastString = '';
    }
}
