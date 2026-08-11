<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\ScalarDimFetchRuntime;
use PHPCompiler\VM\ScalarDimFetchJitHelper;
use PHPCfg\Operand;

/**
 * ZEND_FETCH_DIM_R on scalar containers — E_WARNING + null (zend_execute.c, #4867 / #30053).
 *
 * SSOT: {@see \PHPCompiler\VM\ScalarDimFetchJitHelper}, {@see \PHPCompiler\VM\ErrorReporter}
 */
final class ScalarDimFetchHelper
{
    public static function lowerScalarDimRead(
        Context $context,
        Operand $destOp,
        Variable $value
    ): void {
        if (Variable::TYPE_NATIVE_BOOL === $value->type) {
            $constLabel = self::nativeBoolConstantLabel($value);
            if ('true' === $constLabel) {
                ScalarDimFetchRuntime::emitWarning($context, ScalarDimFetchJitHelper::JIT_BOOL_TRUE);
            } elseif ('false' === $constLabel) {
                ScalarDimFetchRuntime::emitWarning($context, ScalarDimFetchJitHelper::JIT_BOOL_FALSE);
            } else {
                ScalarDimFetchRuntime::emitWarningForNativeBool($context, $value);
            }
        } else {
            ScalarDimFetchRuntime::emitWarning($context, $value->type);
        }
        $nullBox = JitValueBox::alloc($context);
        $nullVar = new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VALUE, $nullBox);
        $context->setVariableOp($destOp, $nullVar);
    }

    /**
     * Compile-time true/false when the bool IR value is constant; null if runtime-only.
     */
    private static function nativeBoolConstantLabel(Variable $arg): ?string
    {
        $value = $arg->value;
        if (method_exists($value, 'isConstant') && $value->isConstant()
            && method_exists($value, 'getConstantValue')
        ) {
            return 0 !== (int) $value->getConstantValue() ? 'true' : 'false';
        }

        return null;
    }
}
