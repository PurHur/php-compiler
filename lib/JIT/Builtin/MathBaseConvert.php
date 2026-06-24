<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
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

    public static function baseToZvalCall(Context $context, Value $strDataPtr, int $base): Value
    {
        return MathBaseConvertRuntime::baseToZvalCall($context, $strDataPtr, $base);
    }

    public static function implement(Context $context): void
    {
        self::ensureLinked($context);
    }
}
