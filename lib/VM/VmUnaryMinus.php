<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPCompiler\OpCode;
use PHPLLVM\Value as LlvmValue;

/**
 * SSOT for JIT unary - lowering (#5083, zend_operators.c, #9976).
 *
 * JIT trampoline: {@see \PHPCompiler\JIT\JitUnaryMinus}
 */
final class VmUnaryMinus
{
    public static function lower(Context $context, OpCode $opcode, Variable $var): Variable
    {
        if (OpCode::TYPE_UNARY_MINUS !== $opcode->type) {
            throw new \InvalidArgumentException('Expected TYPE_UNARY_MINUS opcode');
        }

        try {
            $coerced = VmUnaryPlus::lower($context, new OpCode(OpCode::TYPE_UNARY_PLUS), $var);
        } catch (\LogicException) {
            return $context->helper->unaryOp($opcode, $var);
        }

        $value = $context->helper->loadValue($coerced);
        if (Variable::TYPE_NATIVE_DOUBLE === $coerced->type) {
            if (null === $coerced->compileTimeFloat
                && null !== $coerced->value
                && LlvmValue::KIND_CONSTANT_FP === $coerced->value->getKind()
            ) {
                $lib = $context->llvm->lib;
                $losesInfo = $lib->FFI->new('bool');
                $coerced->compileTimeFloat = $lib->LLVMConstRealGetDouble(
                    $coerced->value->value,
                    $losesInfo
                );
            }
            if (null !== $coerced->compileTimeFloat) {
                $neg = -$coerced->compileTimeFloat;
                $var = new Variable(
                    $context,
                    Variable::TYPE_NATIVE_DOUBLE,
                    Variable::KIND_VALUE,
                    $context->constantFromFloat($neg, 'double')
                );
                $var->compileTimeFloat = $neg;

                return $var;
            }
            $negated = $context->builder->fNegate($value);

            return new Variable($context, Variable::TYPE_NATIVE_DOUBLE, Variable::KIND_VALUE, $negated);
        }

        $negated = $context->builder->negate($value);

        return new Variable($context, Variable::TYPE_NATIVE_LONG, Variable::KIND_VALUE, $negated);
    }
}
