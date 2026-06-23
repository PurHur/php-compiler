<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCfg\Operand;
use PHPCompiler\JIT\Builtin\NonObjectPropertyFetchRuntime;

/**
 * ZEND_FETCH_PROPERTY_R on non-object receivers — E_WARNING + null (zend_fetch.c, #5276, #10381).
 * Increment/decrement on null still throws Error (zend_execute.c, #7431).
 *
 * SSOT: {@see \PHPCompiler\VM\NonObjectPropertyFetchJitHelper}
 */
final class NonObjectPropertyFetchHelper
{
    public static function emitPropertyReadWarning(Context $context, string $propertyName, string $typeLabel): void
    {
        NonObjectPropertyFetchRuntime::emitWarning($context, $propertyName, $typeLabel);
    }

    public static function lowerNonObjectPropertyRead(
        Context $context,
        Operand $destOp,
        string $propertyName,
        string $typeLabel
    ): void {
        self::emitPropertyReadWarning($context, $propertyName, $typeLabel);
        self::lowerNullPropertyDest($context, $destOp);
    }

    public static function lowerNullPropertyDest(Context $context, Operand $destOp): void
    {
        $nullBox = JitValueBox::alloc($context);
        $nullVar = new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VALUE, $nullBox);
        $context->setVariableOp($destOp, $nullVar);
    }
}
