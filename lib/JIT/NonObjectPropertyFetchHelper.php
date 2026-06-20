<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCfg\Operand;
use PHPCompiler\VM\ErrorReporter;

/**
 * ZEND_FETCH_PROPERTY_R on non-object receivers — E_WARNING + null (zend_fetch.c, #5276, #10381).
 * Increment/decrement on null still throws Error (zend_execute.c, #7431).
 */
final class NonObjectPropertyFetchHelper
{
    public static function emitPropertyReadWarning(Context $context, string $propertyName, string $typeLabel): void
    {
        $message = sprintf('Attempt to read property "%s" on %s', $propertyName, $typeLabel);
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $i32 = $context->getTypeFromString('int32');
        $msgPtr = $context->builder->pointerCast($context->constantFromString($message), $i8p);
        $msgLen = $sizeT->constInt(\strlen($message), false);
        $emptyFile = $context->builder->pointerCast($context->constantFromString(''), $i8p);
        $context->builder->call(
            $context->lookupFunction('__compiler_trigger_error'),
            $msgPtr,
            $msgLen,
            $i32->constInt(ErrorReporter::E_WARNING, false),
            $emptyFile,
            $i32->constInt(0, false)
        );
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
