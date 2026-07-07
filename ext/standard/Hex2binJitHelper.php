<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * hex2bin() for compiled JIT/AOT modules (#14627, php-in-PHP).
 *
 * SSOT: {@see VmString::hex2bin()}
 * php-src: ext/standard/string.c — PHP_FUNCTION(hex2bin)
 */
final class Hex2binJitHelper
{
    public const TAG_FALSE = 0;

    public const TAG_STRING = 1;

    private static ?string $lastString = null;

    public static function hex2binArgv(string $data, bool $strict): int
    {
        $len = VmString::byteLength($data);
        if ($len > 0 && 0 !== ($len & 1)) {
            if ($strict) {
                throw new \Error('Hexadecimal input string must have an even length');
            }
            TriggerErrorJitHelper::warning('Hexadecimal input string must have an even length');

            return self::TAG_FALSE;
        }

        $result = VmString::hex2bin($data, $strict);
        if (false === $result) {
            if ($strict) {
                throw new \Error('Input string must be hexadecimal string');
            }
            if ($len > 0) {
                TriggerErrorJitHelper::warning('Input string must be hexadecimal string');
            }

            return self::TAG_FALSE;
        }

        self::$lastString = $result;

        return self::TAG_STRING;
    }

    public static function lastString(): string
    {
        return self::$lastString ?? '';
    }
}
