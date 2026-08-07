<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPCompiler\OpCode;
use PHPLLVM\Builder;
use PHPLLVM\Value as LlvmValue;

/**
 * SSOT for JIT unary - lowering (#5083, zend_operators.c, #9976, #28761).
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

        // Native long: PHP_INT_MIN overflows to double (zendi_negate_function, #28761).
        if (Variable::KIND_VALUE === $coerced->kind
            && null !== $coerced->value
            && LlvmValue::KIND_CONSTANT_INT === $coerced->value->getKind()
        ) {
            $const = (int) $context->llvm->lib->LLVMConstIntGetSExtValue($coerced->value->value);
            if (\PHP_INT_MIN === $const) {
                $neg = -(float) $const;
                $out = new Variable(
                    $context,
                    Variable::TYPE_NATIVE_DOUBLE,
                    Variable::KIND_VALUE,
                    $context->constantFromFloat($neg, 'double')
                );
                $out->compileTimeFloat = $neg;

                return $out;
            }
            $neg = -$const;

            return new Variable(
                $context,
                Variable::TYPE_NATIVE_LONG,
                Variable::KIND_VALUE,
                $context->constantFromInteger($neg, 'long')
            );
        }

        return self::negateLongWithIntMinPromote($context, $value);
    }

    /**
     * Runtime long negate with PHP_INT_MIN → double promotion into a value box (#28761).
     */
    private static function negateLongWithIntMinPromote(Context $context, LlvmValue $value): Variable
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'unary_minus_int_min_cont');
        $i64 = $context->getTypeFromString('int64');
        $f64 = $context->getTypeFromString('double');
        $long = $context->builder->intCast($value, $i64);
        $valuePtr = $context->builder->alloca($context->getTypeFromString('__value__'));

        $isMin = $context->builder->icmp(
            Builder::INT_EQ,
            $long,
            $i64->constInt(\PHP_INT_MIN, true)
        );
        $minBlock = BasicBlockHelper::append($context, 'unary_minus_int_min');
        $longBlock = BasicBlockHelper::append($context, 'unary_minus_long');
        $doneBlock = BasicBlockHelper::append($context, 'unary_minus_done');
        $context->builder->branchIf($isMin, $minBlock, $longBlock);

        $context->builder->positionAtEnd($minBlock);
        // fneg(sitofp(INT_MIN)): LLVM integer negate wraps INT_MIN to itself.
        $minNeg = $context->builder->fNegate($context->builder->sitofp($long, $f64));
        $context->builder->call(
            $context->lookupFunction('__value__writeDouble'),
            $valuePtr,
            $minNeg
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($longBlock);
        $negLong = $context->builder->negate($long);
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            $valuePtr,
            $negLong
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VALUE, $valuePtr);
    }
}
