<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCfg\Operand;
use PHPCompiler\JIT\Builtin\UndefinedPropertyFetchRuntime;

/**
 * Undefined dynamic property read — E_WARNING + null (zend_object_handlers.c, #15752).
 *
 * SSOT: {@see \PHPCompiler\VM\UndefinedPropertyFetchJitHelper}
 */
final class UndefinedPropertyFetchHelper
{
    public static function lowerUndefinedDynamicPropertyRead(
        Context $context,
        Operand $destOp,
        string $className,
        string $propertyName
    ): void {
        UndefinedPropertyFetchRuntime::emitWarning($context, $className, $propertyName);
        NonObjectPropertyFetchHelper::lowerNullPropertyDest($context, $destOp);
    }
}
