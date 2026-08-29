<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * JIT LLVM entry for phpc_base_convert (arbitrary-base conversion; #5197, #9584).
 *
 * Lowers via {@see MathBaseConvertRuntime} → {@see \PHPCompiler\ext\standard\VmMath}.
 */
final class MathBaseConvert
{
    public static function ensureLinked(Context $context): void
    {
        MathBaseConvertRuntime::ensureLinked($context);
    }

    public static function baseToZvalCall(Context $context, Value $strPtr, int $base): Value
    {
        return MathBaseConvertRuntime::baseToZvalCall($context, $strPtr, $base);
    }

    /** hexdec/bindec/octdec: fold literals at compile time (peer base_convert #31966). */
    public static function tryFoldRadixToZval(Context $context, JITVariable $arg, int $fromBase): ?Value
    {
        return MathBaseConvertRuntime::tryFoldRadixToZval($context, $arg, $fromBase);
    }

    /** Runtime radix parse via base_convert_ + strtol — HELPER_RUNTIME_O=0 safe (#31966). */
    public static function radixStringToZvalCall(Context $context, JITVariable $strArg, int $fromBase): Value
    {
        return MathBaseConvertRuntime::radixStringToZvalViaBaseConvert($context, $strArg, $fromBase);
    }

    public static function implement(Context $context): void
    {
        self::ensureLinked($context);
    }
}
