<?php

declare(strict_types=1);

/**
 * JIT/AOT helper for interface_exists() via InterfaceExistsJitHelper PHP (#1371, #16185).
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

        return StringInterfaceExists::invoke(
            $context,
            JitStringArg::lower($context, $nameArg, 'interface_exists() interface name')
        );
    }
}
