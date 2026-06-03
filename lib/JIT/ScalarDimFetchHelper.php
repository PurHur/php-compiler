<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\VM\ErrorReporter;
use PHPCfg\Operand;

/**
 * ZEND_FETCH_DIM_R on scalar containers — E_WARNING + null (zend_execute.c, #4867).
 */
final class ScalarDimFetchHelper
{
    public static function emitArrayOffsetWarning(Context $context, string $typeLabel): void
    {
        $message = "Trying to access array offset on value of type {$typeLabel}";
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

    public static function lowerScalarDimRead(
        Context $context,
        Operand $destOp,
        string $typeLabel
    ): void {
        self::emitArrayOffsetWarning($context, $typeLabel);
        $nullBox = JitValueBox::alloc($context);
        $nullVar = new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VALUE, $nullBox);
        $context->setVariableOp($destOp, $nullVar);
    }
}
