<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\OpCode;
use PHPCompiler\VM\VmStringCompare;
use PHPLLVM\Value;

/**
 * JIT trampoline for native __string__ compare lowering (#9972).
 *
 * SSOT: {@see \PHPCompiler\VM\VmStringCompare}
 */
final class JitStringCompare
{
    public static function binaryOp(
        Context $context,
        OpCode $opcode,
        Value $leftStr,
        Value $rightStr
    ): Value {
        return VmStringCompare::binaryOp($context, $opcode, $leftStr, $rightStr);
    }

    public static function suffixIdentical(Context $context, Value $haystack, Value $suffix): Value
    {
        return VmStringCompare::suffixIdentical($context, $haystack, $suffix);
    }

    public static function strcmp(Context $context, Value $leftStr, Value $rightStr): Value
    {
        return VmStringCompare::strcmp($context, $leftStr, $rightStr);
    }

    public static function identical(Context $context, Value $leftStr, Value $rightStr): Value
    {
        return VmStringCompare::identical($context, $leftStr, $rightStr);
    }

    public static function identicalValueToString(
        Context $context,
        Variable $boxed,
        Value $nativeStr
    ): Value {
        return VmStringCompare::identicalValueToString($context, $boxed, $nativeStr);
    }

    public static function identicalStringToValue(
        Context $context,
        Value $nativeStr,
        Variable $boxed
    ): Value {
        return VmStringCompare::identicalStringToValue($context, $nativeStr, $boxed);
    }
}
