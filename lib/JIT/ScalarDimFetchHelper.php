<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\ScalarDimFetchRuntime;
use PHPCfg\Operand;

/**
 * ZEND_FETCH_DIM_R on scalar containers — E_WARNING + null (zend_execute.c, #4867).
 *
 * SSOT: {@see \PHPCompiler\VM\ScalarDimFetchJitHelper}, {@see \PHPCompiler\VM\ErrorReporter}
 */
final class ScalarDimFetchHelper
{
    public static function lowerScalarDimRead(
        Context $context,
        Operand $destOp,
        int $jitType
    ): void {
        ScalarDimFetchRuntime::emitWarning($context, $jitType);
        $nullBox = JitValueBox::alloc($context);
        $nullVar = new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VALUE, $nullBox);
        $context->setVariableOp($destOp, $nullVar);
    }
}
