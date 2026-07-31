<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Lowered into JIT/AOT modules for string offset read/write/index (#10245, #21497, php-in-PHP).
 *
 * php-src: Zend/zend_operators.c — string offset fetch/write, increment on string offsets
 * SSOT: {@see Variable::readStringOffset()}, {@see Variable::writeStringOffset()}
 */
final class StringOffsetJitHelper
{
    public const INCDEC_ERROR = 'Cannot increment/decrement string offsets';

    /** @see Variable::STRING_OFFSET_ASSIGN_OP_ERROR (#22897) */
    public const ASSIGN_OP_ERROR = 'Cannot use assign-op operators with string offsets';

    public const EMPTY_ASSIGN_ERROR = 'Cannot assign an empty string to a string offset';

    /** @see Variable::STRING_OFFSET_FIRST_BYTE_WARNING (#22380) */
    public const FIRST_BYTE_WARNING = 'Only the first byte will be assigned to the string offset';

    /** Zend _convert_to_string() array branch before string-offset assign (#22925). */
    public const ARRAY_TO_STRING_WARNING = 'Array to string conversion';

    /**
     * Object RHS without __toString — Zend Error text (#25794).
     *
     * @see ValueEchoSupport::objectToStringErrorMessage()
     */
    public static function objectToStringErrorMessage(string $className): string
    {
        return ValueEchoSupport::objectToStringErrorMessage($className);
    }

    /** @see Variable::STRING_OFFSET_REF_ERROR (#21910) */
    public const REF_ERROR = 'Cannot create references to/from string offsets';

    /**
     * Zend zend_illegal_string_offset TypeError text (#22895).
     *
     * php-src: Zend/zend_execute.c — "Cannot access offset of type %s on string"
     */
    public static function illegalDimTypeErrorMessage(string $typeName): string
    {
        return 'Cannot access offset of type ' . $typeName . ' on string';
    }

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

    /**
     * @return array{0: int, 1: bool}|null
     * @see Variable::tryParseStringOffsetLong()
     */
    public static function tryParseOffsetLong(string $s): ?array
    {
        return Variable::tryParseStringOffsetLong($s);
    }

    /**
     * Read one byte as a length-1 string; OOR → empty (#22646).
     *
     * Warning is emitted by {@see \PHPCompiler\JIT\Builtin\StringOffsetRuntime} (LLVM), not here —
     * NestedJIT of this helper must not pull in trigger_error.
     *
     * php-src: Zend/zend_operators.c — zend_fetch_dimension_address_read (IS_STRING)
     * SSOT peer: {@see Variable::readStringOffset()}
     */
    public static function readOffset(string $str, int $rawIndex): string
    {
        $len = \strlen($str);
        $index = self::normalizeByteIndex($rawIndex, $len);
        if ($index < 0 || $index >= $len) {
            return '';
        }

        return $str[$index];
    }

    public static function incDecErrorMessage(): string
    {
        return self::INCDEC_ERROR;
    }

    public static function assignOpErrorMessage(): string
    {
        return self::ASSIGN_OP_ERROR;
    }

    public static function emptyAssignErrorMessage(): string
    {
        return self::EMPTY_ASSIGN_ERROR;
    }

    public static function refErrorMessage(): string
    {
        return self::REF_ERROR;
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
