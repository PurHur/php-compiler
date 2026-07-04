<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\StringClassExists;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/**
 * JIT/AOT helper for class_exists() via ClassExistsJitHelper PHP (#1214, #16185).
 *
 * {@see self::stringDataPtr()} remains for other JIT class-name scans.
 */
final class JitClassExists
{
    public static function invoke(Context $context, Value $nameStr): Value
    {
        return StringClassExists::invoke($context, $nameStr);
    }

    public static function stringDataPtr(Context $context, Value $strPtr): Value
    {
        $structName = $strPtr->typeOf()->getElementType()->getName();
        $off = $context->structFieldMap[$structName]['value'];

        return $context->builder->structGep($strPtr, $off);
    }
}
