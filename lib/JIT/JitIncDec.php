<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\VM\VmIncDec;
use PHPLLVM\Builder;
use PHPLLVM\Value as LlvmValue;

/**
 * JIT ++/-- with PHP_INT_MAX/MIN → double promotion (#29144),
 * boxed/native float ± 1.0 (#32281), and IS_NULL decrement no-op (#32297).
 *
 * @see php-src Zend/zend_operators.h fast_long_increment_function /
 *      fast_long_decrement_function
 * @see php-src Zend/zend_operators.c increment_function / decrement_function IS_DOUBLE
 */
final class JitIncDec
{
    /**
     * Compile-time constant long: fold overflow to double, else ±1 long.
     */
    public static function tryFoldConstantLong(
        Context $context,
        Variable $read,
        bool $increment
    ): ?Variable {
        if (Variable::KIND_VALUE !== $read->kind
            || null === $read->value
            || LlvmValue::KIND_CONSTANT_INT !== $read->value->getKind()
        ) {
            return null;
        }
        $const = (int) $context->llvm->lib->LLVMConstIntGetSExtValue($read->value->value);
        if ($increment) {
            if (\PHP_INT_MAX === $const) {
                return self::doubleConst($context, VmIncDec::overflowIncrementFloat());
            }
            $next = $const + 1;

            return new Variable(
                $context,
                Variable::TYPE_NATIVE_LONG,
                Variable::KIND_VALUE,
                $context->constantFromInteger($next, 'long')
            );
        }
        if (\PHP_INT_MIN === $const) {
            return self::doubleConst($context, VmIncDec::overflowDecrementFloat());
        }
        $next = $const - 1;

        return new Variable(
            $context,
            Variable::TYPE_NATIVE_LONG,
            Variable::KIND_VALUE,
            $context->constantFromInteger($next, 'long')
        );
    }

    /**
     * Runtime long ++/-- into a value box (long or double on overflow).
     */
    public static function promoteLongIntoValueBox(
        Context $context,
        LlvmValue $cur,
        bool $increment
    ): Variable {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'incdec_overflow_cont');
        $i64 = $context->getTypeFromString('int64');
        $f64 = $context->getTypeFromString('double');
        $long = $context->builder->intCast($cur, $i64);
        $valuePtr = $context->builder->alloca($context->getTypeFromString('__value__'));

        $limit = $i64->constInt($increment ? \PHP_INT_MAX : \PHP_INT_MIN, true);
        $isOverflow = $context->builder->icmp(Builder::INT_EQ, $long, $limit);
        $ovBlock = BasicBlockHelper::append($context, $increment ? 'inc_int_max' : 'dec_int_min');
        $longBlock = BasicBlockHelper::append($context, 'incdec_long');
        $doneBlock = BasicBlockHelper::append($context, 'incdec_done');
        $context->builder->branchIf($isOverflow, $ovBlock, $longBlock);

        $context->builder->positionAtEnd($ovBlock);
        $ovFloat = $f64->constReal(
            $increment ? VmIncDec::overflowIncrementFloat() : VmIncDec::overflowDecrementFloat()
        );
        $context->builder->call(
            $context->lookupFunction('__value__writeDouble'),
            $valuePtr,
            $ovFloat
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($longBlock);
        $one = $i64->constInt(1, false);
        $newLong = $increment
            ? $context->builder->add($long, $one)
            : $context->builder->sub($long, $one);
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            $valuePtr,
            $newLong
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VALUE, $valuePtr);
    }

    /**
     * Write ++/-- of a long into an existing value-box lvalue (in-place local).
     */
    public static function writeLongIncDecToValuePtr(
        Context $context,
        LlvmValue $cur,
        LlvmValue $writePtr,
        bool $increment
    ): void {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'incdec_vbox_overflow_cont');
        $i64 = $context->getTypeFromString('int64');
        $f64 = $context->getTypeFromString('double');
        $long = $context->builder->intCast($cur, $i64);

        $limit = $i64->constInt($increment ? \PHP_INT_MAX : \PHP_INT_MIN, true);
        $isOverflow = $context->builder->icmp(Builder::INT_EQ, $long, $limit);
        $ovBlock = BasicBlockHelper::append($context, $increment ? 'inc_vbox_int_max' : 'dec_vbox_int_min');
        $longBlock = BasicBlockHelper::append($context, 'incdec_vbox_long');
        $doneBlock = BasicBlockHelper::append($context, 'incdec_vbox_done');
        $context->builder->branchIf($isOverflow, $ovBlock, $longBlock);

        $context->builder->positionAtEnd($ovBlock);
        $ovFloat = $f64->constReal(
            $increment ? VmIncDec::overflowIncrementFloat() : VmIncDec::overflowDecrementFloat()
        );
        $context->builder->call(
            $context->lookupFunction('__value__writeDouble'),
            $writePtr,
            $ovFloat
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($longBlock);
        $one = $i64->constInt(1, false);
        $newLong = $increment
            ? $context->builder->add($long, $one)
            : $context->builder->sub($long, $one);
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            $writePtr,
            $newLong
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        JitValueBox::publishAfterWrite($context, $writePtr);
    }

    /**
     * Boxed ++/--: IS_DOUBLE += 1.0, else long with PHP_INT overflow (#32281).
     *
     * @see php-src Zend/zend_operators.c increment_function / decrement_function
     */
    public static function writeValueBoxIncDec(
        Context $context,
        Variable $read,
        LlvmValue $curLong,
        LlvmValue $writePtr,
        bool $increment
    ): void {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'incdec_vbox_typed_cont');
        $isDouble = JitValueNumeric::valueIsDouble($context, $read);
        $floatBlock = BasicBlockHelper::append($context, $increment ? 'inc_vbox_float' : 'dec_vbox_float');
        $longBlock = BasicBlockHelper::append($context, $increment ? 'inc_vbox_as_long' : 'dec_vbox_as_long');
        $doneBlock = BasicBlockHelper::append($context, 'incdec_vbox_typed_done');
        $context->builder->branchIf($isDouble, $floatBlock, $longBlock);

        $context->builder->positionAtEnd($floatBlock);
        $readPtr = JitValueBox::valuePtrFromVariable($context, $read);
        $f64 = $context->getTypeFromString('double');
        $cur = $context->builder->call(
            $context->lookupFunction('__value__readDouble'),
            $readPtr
        );
        $one = $f64->constReal(1.0);
        $new = $increment
            ? $context->builder->fadd($cur, $one)
            : $context->builder->fsub($cur, $one);
        $context->builder->call(
            $context->lookupFunction('__value__writeDouble'),
            $writePtr,
            $new
        );
        JitValueBox::publishAfterWrite($context, $writePtr);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($longBlock);
        self::writeLongIncDecToValuePtr($context, $curLong, $writePtr, $increment);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
    }

    /**
     * Native double ++/-- : dval ± 1.0 (#32281).
     */
    public static function nativeDoubleIncDec(
        Context $context,
        LlvmValue $cur,
        bool $increment
    ): Variable {
        $f64 = $context->getTypeFromString('double');
        $one = $f64->constReal(1.0);
        $new = $increment
            ? $context->builder->fadd($cur, $one)
            : $context->builder->fsub($cur, $one);

        return new Variable(
            $context,
            Variable::TYPE_NATIVE_DOUBLE,
            Variable::KIND_VALUE,
            $new
        );
    }

    private static function doubleConst(Context $context, float $f): Variable
    {
        $out = new Variable(
            $context,
            Variable::TYPE_NATIVE_DOUBLE,
            Variable::KIND_VALUE,
            $context->constantFromFloat($f, 'double')
        );
        $out->compileTimeFloat = $f;

        return $out;
    }
}
