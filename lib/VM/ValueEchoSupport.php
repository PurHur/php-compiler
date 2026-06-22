<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\JIT\Variable as JitVariable;

/**
 * SSOT for echo/print value → output bytes (Zend zend_print_variable parity, #3564, #10204).
 *
 * php-src: Zend/zend_operators.c — zend_print_variable
 * php-src: main/output.c — php_output_write
 */
final class ValueEchoSupport
{
    public const ARRAY_LABEL = 'Array';

    public const BOOL_TRUE_LABEL = '1';

    public const OBJECT_FALLBACK_LABEL = 'Object';

    public const RESOURCE_FORMAT = 'Resource id #%lld';

    public static function objectToStringErrorMessage(string $className): string
    {
        return 'Object of class '.$className.' could not be converted to string';
    }

    public static function jitTypeIsNull(int $typeByte): bool
    {
        return JitVariable::TYPE_NULL === $typeByte;
    }

    public static function jitTypeIsNativeLong(int $typeByte): bool
    {
        return JitVariable::TYPE_NATIVE_LONG === $typeByte;
    }

    public static function jitTypeIsNativeBool(int $typeByte): bool
    {
        return JitVariable::TYPE_NATIVE_BOOL === $typeByte;
    }

    public static function jitTypeIsNativeDouble(int $typeByte): bool
    {
        return JitVariable::TYPE_NATIVE_DOUBLE === $typeByte;
    }

    public static function jitTypeIsString(int $typeByte): bool
    {
        return JitVariable::TYPE_STRING === $typeByte;
    }

    public static function jitTypeIsHashtable(int $typeByte): bool
    {
        return JitVariable::TYPE_HASHTABLE === $typeByte;
    }

    public static function jitTypeIsObject(int $typeByte): bool
    {
        return JitVariable::TYPE_OBJECT === $typeByte;
    }
}
