<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Lowered into JIT/AOT modules for string offset read/write/index (#10245, php-in-PHP).
 *
 * php-src: Zend/zend_operators.c — string offset fetch/write, increment on string offsets
 * SSOT: {@see Variable::readStringOffset()}, {@see Variable::writeStringOffset()}
 */
final class StringOffsetJitHelper
{
    public const INCDEC_ERROR = 'Cannot increment/decrement string offsets';

    /**
     * Zend-style byte index: negative offsets count from the end (PHP 7.1+).
     *
     * Mirrors LLVM normalizeOffset / {@see Variable::resolveStringOffsetByteIndex} pre-range check.
     */
    public static function normalizeByteIndex(int $rawIndex, int $len): int
    {
        if ($rawIndex < 0) {
            return $rawIndex + $len;
        }

        return $rawIndex;
    }

    public static function incDecErrorMessage(): string
    {
        return self::INCDEC_ERROR;
    }

    public static function byteFromLong(int $value): int
    {
        return $value & 0xFF;
    }

    public static function byteFromStringFirstChar(string $str): int
    {
        if ('' === $str) {
            return 0;
        }

        return \ord($str[0]);
    }
}
