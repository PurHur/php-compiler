<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\OpCode;
use PHPLLVM\Builder;
use PHPLLVM\Value as LlvmValue;

/**
 * JIT long + / * with PHP_INT overflow → double promotion (#31964).
 *
 * @see php-src Zend/zend_operators.h ZEND_SIGNED_ADD_OVERFLOW /
 *      ZEND_LONG_MUL_OVERFLOW / add_function / mul_function
 */
final class JitLongArithOverflow
{
    public static function supportsOpcode(int $opType): bool
    {
        return OpCode::TYPE_PLUS === $opType || OpCode::TYPE_MUL === $opType;
    }

    /**
     * Compile-time fold: PHP native arithmetic already promotes on overflow.
     */
    public static function tryFoldBinary(
        Context $context,
        int $opType,
        Variable $left,
        Variable $right
    ): ?Variable {
        if (!self::supportsOpcode($opType)) {
            return null;
        }
        $a = self::extractConstantLong($context, $left);
        $b = self::extractConstantLong($context, $right);
        if (null === $a || null === $b) {
            return null;
        }
        $result = OpCode::TYPE_PLUS === $opType ? ($a + $b) : ($a * $b);
        if (\is_int($result)) {
            return new Variable(
                $context,
                Variable::TYPE_NATIVE_LONG,
                Variable::KIND_VALUE,
                $context->constantFromInteger($result, 'long')
            );
        }

        return new Variable(
            $context,
            Variable::TYPE_NATIVE_DOUBLE,
            Variable::KIND_VALUE,
            $context->constantFromFloat((float) $result, 'double')
        );
    }

    /**
     * Native long ⊙ native long with overflow → double (#31964).
     */
    public static function binaryNativeLong(
        Context $context,
        int $opType,
        LlvmValue $left,
        LlvmValue $right
    ): Variable {
        $slot = JitValueBox::alloc($context);
        $slotPtr = JitValueBox::pointer($context, $slot);
        self::writeBoxedBinary($context, $opType, $left, $right, $slotPtr);

        return new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VALUE, $slotPtr);
    }

    /**
     * Boxed long ⊙ boxed long — write long or double into an existing value slot.
     */
    public static function writeBoxedBinary(
        Context $context,
        int $opType,
        LlvmValue $left,
        LlvmValue $right,
        LlvmValue $slotPtr
    ): void {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'vbox_arith_overflow_cont');
        $i64 = $context->getTypeFromString('int64');
        $f64 = $context->getTypeFromString('double');
        $a = $context->builder->intCast($left, $i64);
        $b = $context->builder->intCast($right, $i64);

        $overflow = OpCode::TYPE_PLUS === $opType
            ? self::signedAddOverflow($context, $a, $b)
            : self::signedMulOverflow($context, $a, $b);

        $ovBlock = BasicBlockHelper::append($context, 'vbox_arith_overflow');
        $okBlock = BasicBlockHelper::append($context, 'vbox_arith_ok');
        $doneBlock = BasicBlockHelper::append($context, 'vbox_arith_done');
        $context->builder->branchIf($overflow, $ovBlock, $okBlock);

        $context->builder->positionAtEnd($ovBlock);
        $ad = $context->builder->siToFp($a, $f64);
        $bd = $context->builder->siToFp($b, $f64);
        $fd = OpCode::TYPE_PLUS === $opType
            ? $context->builder->fadd($ad, $bd)
            : $context->builder->fmul($ad, $bd);
        $context->builder->call(
            $context->lookupFunction('__value__writeDouble'),
            $slotPtr,
            $fd
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $lres = OpCode::TYPE_PLUS === $opType
            ? $context->builder->add($a, $b)
            : $context->builder->mul($a, $b);
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            $slotPtr,
            $lres
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        JitValueBox::publishAfterWrite($context, $slotPtr);
    }

    /**
     * @see php-src Zend/zend_operators.h ZEND_SIGNED_ADD_OVERFLOW
     */
    private static function signedAddOverflow(Context $context, LlvmValue $a, LlvmValue $b): LlvmValue
    {
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, true);
        $intMax = $i64->constInt(\PHP_INT_MAX, true);
        $intMin = $i64->constInt(\PHP_INT_MIN, true);

        $bPos = $context->builder->icmp(Builder::INT_SGT, $b, $zero);
        $maxMinusB = $context->builder->sub($intMax, $b);
        $aGtMaxMinusB = $context->builder->icmp(Builder::INT_SGT, $a, $maxMinusB);
        $minMinusB = $context->builder->sub($intMin, $b);
        $aLtMinMinusB = $context->builder->icmp(Builder::INT_SLT, $a, $minMinusB);
        $ovIfBPos = $context->builder->and($bPos, $aGtMaxMinusB);
        $bNonPos = $context->builder->icmp(Builder::INT_SLE, $b, $zero);
        $ovIfBNonPos = $context->builder->and($bNonPos, $aLtMinMinusB);

        return $context->builder->or($ovIfBPos, $ovIfBNonPos);
    }

    /**
     * @see php-src Zend/zend_operators.h ZEND_LONG_MUL_OVERFLOW
     *
     * `sdiv` is eager: `(a*b)/a` SIGFPEs when `a==0` (false* int after #32337 zext).
     * php-src skips the quotient when `op1==0`.
     */
    private static function signedMulOverflow(Context $context, LlvmValue $a, LlvmValue $b): LlvmValue
    {
        $i64 = $context->getTypeFromString('int64');
        $i1 = $context->getTypeFromString('int1');
        $zero = $i64->constInt(0, true);
        $falseVal = $i1->constInt(0, false);
        $aZero = $context->builder->icmp(Builder::INT_EQ, $a, $zero);

        $resultSlot = BasicBlockHelper::entryAlloca($context, $i1);
        $checkBb = BasicBlockHelper::append($context, 'mul_ov_sdiv');
        $doneBb = BasicBlockHelper::append($context, 'mul_ov_done');
        $context->builder->store($falseVal, $resultSlot);
        $context->builder->branchIf($aZero, $doneBb, $checkBb);

        $context->builder->positionAtEnd($checkBb);
        $product = $context->builder->mul($a, $b);
        $quot = $context->builder->signedDiv($product, $a);
        $neq = $context->builder->icmp(Builder::INT_NE, $quot, $b);
        $context->builder->store($neq, $resultSlot);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);

        return $context->builder->load($resultSlot);
    }

    private static function extractConstantLong(Context $context, Variable $var): ?int
    {
        // ConstFetch of PHP_INT_MIN/MAX is a load of a module global with
        // compileTimeLong — not KIND_CONSTANT_INT (#32309).
        if (null !== $var->compileTimeLong) {
            return $var->compileTimeLong;
        }
        if (Variable::KIND_VALUE !== $var->kind
            || null === $var->value
            || LlvmValue::KIND_CONSTANT_INT !== $var->value->getKind()
        ) {
            return null;
        }

        return (int) $context->llvm->lib->LLVMConstIntGetSExtValue($var->value->value);
    }
}
