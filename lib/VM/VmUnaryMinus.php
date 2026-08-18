<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\JitValueNumeric;
use PHPCompiler\JIT\Variable;
use PHPCompiler\OpCode;
use PHPLLVM\Builder;
use PHPLLVM\Value as LlvmValue;

/**
 * SSOT for JIT unary - lowering (#5083, zend_operators.c, #9976, #28761, #32317, #32442).
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

        $constName = strtolower($var->compileTimeConstantName ?? '');
        if ('inf' === $constName || 'nan' === $constName) {
            return $context->helper->unaryOp($opcode, $var);
        }

        // Native float: fneg. Boxed values type-switch (IS_DOUBLE vs convert_to_long).
        // Do not sitofp every non-double box — that made -$string float(-0) (#32442)
        // and skipped integer negate for boxed longs.
        if (Variable::TYPE_NATIVE_DOUBLE === $var->type) {
            return $context->helper->unaryOp($opcode, $var);
        }
        if (Variable::TYPE_VALUE === $var->type && JitValueBox::isValueOperand($var)) {
            return self::negateValueBox($context, $var);
        }

        try {
            $coerced = VmUnaryPlus::lower($context, new OpCode(OpCode::TYPE_UNARY_PLUS), $var);
        } catch (\LogicException) {
            return $context->helper->unaryOp($opcode, $var);
        }

        $value = $context->helper->loadValue($coerced);
        if (Variable::TYPE_NATIVE_DOUBLE === $coerced->type) {
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
     * Boxed unary minus: zendi_convert_scalar_to_number then zendi_negate_function (#32442).
     *
     * IS_DOUBLE stays double (fneg). Everything else convert_to_long then integer
     * negate with ZEND_LONG_MIN → double. php-src Zend/zend_operators.c.
     */
    private static function negateValueBox(Context $context, Variable $var): Variable
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'unary_minus_vbox_typed_cont');
        $resultPtr = JitValueBox::alloc($context);
        $isDouble = JitValueNumeric::valueIsDouble($context, $var);
        $doubleBlock = BasicBlockHelper::append($context, 'unary_minus_vbox_double');
        $longBlock = BasicBlockHelper::append($context, 'unary_minus_vbox_as_long');
        $doneBlock = BasicBlockHelper::append($context, 'unary_minus_vbox_typed_done');
        $context->builder->branchIf($isDouble, $doubleBlock, $longBlock);

        $context->builder->positionAtEnd($doubleBlock);
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $var);
        $dval = $context->builder->call(
            $context->lookupFunction('__value__readDouble'),
            $valuePtr
        );
        $dneg = $context->builder->fNegate($dval);
        $context->builder->call(
            $context->lookupFunction('__value__writeDouble'),
            $resultPtr,
            $dneg
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($longBlock);
        $long = JitLongArg::lower($context, $var, 'unary minus boxed operand');
        $i64 = $context->getTypeFromString('int64');
        $f64 = $context->getTypeFromString('double');
        $isMin = $context->builder->icmp(
            Builder::INT_EQ,
            $long,
            $i64->constInt(\PHP_INT_MIN, true)
        );
        $minBlock = BasicBlockHelper::append($context, 'unary_minus_vbox_int_min');
        $negBlock = BasicBlockHelper::append($context, 'unary_minus_vbox_neg_long');
        $context->builder->branchIf($isMin, $minBlock, $negBlock);

        $context->builder->positionAtEnd($minBlock);
        $minNeg = $context->builder->fNegate($context->builder->sitofp($long, $f64));
        $context->builder->call(
            $context->lookupFunction('__value__writeDouble'),
            $resultPtr,
            $minNeg
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($negBlock);
        $negLong = $context->builder->negate($long);
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            $resultPtr,
            $negLong
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        JitValueBox::publishAfterWrite($context, $resultPtr);

        return new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VALUE, $resultPtr);
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
