<?php

declare(strict_types=1);

/**
 * JIT/AOT helper for interface_exists() via InterfaceExistsJitHelper PHP (#1371, #16185, #19223).
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\StringInterfaceExists;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\ReflectionBuiltinHelper;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

final class JitInterfaceExists
{
    public static function invoke(Context $context, JITVariable $nameArg): Value
    {
        $literal = JitStringArg::compileTimeLiteral($nameArg);
        if (null !== $literal) {
            return ReflectionBuiltinHelper::interfaceExistsLiteral($context, $literal);
        }

        return self::invokeLowered(
            $context,
            JitStringArg::lower($context, $nameArg, 'interface_exists() interface name')
        );
    }

    /** Pre-lowered {@see __string__*} name (8.4 Z_PARAM_STR null guard at call site, #19223). */
    public static function invokeLowered(Context $context, Value $nameStr): Value
    {
        return StringInterfaceExists::invoke($context, $nameStr);
    }
}
