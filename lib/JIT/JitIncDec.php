<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\StringStrIncdec;
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
     * Boxed ++/--: IS_STRING via increment_string / numeric convert (#32435),
     * IS_DOUBLE += 1.0, else long with PHP_INT overflow (#32281).
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
        $isString = JitValueNumeric::valueIsString($context, $read);
        $strBlock = BasicBlockHelper::append($context, $increment ? 'inc_vbox_string' : 'dec_vbox_string');
        $numBlock = BasicBlockHelper::append($context, $increment ? 'inc_vbox_numeric' : 'dec_vbox_numeric');
        $doneBlock = BasicBlockHelper::append($context, 'incdec_vbox_typed_done');
        $context->builder->branchIf($isString, $strBlock, $numBlock);

        $context->builder->positionAtEnd($strBlock);
        $readPtr = JitValueBox::valuePtrFromVariable($context, $read);
        $strPtr = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $readPtr
        );
        self::writeStringPtrIncDec($context, $strPtr, $writePtr, $increment);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($numBlock);
        $isDouble = JitValueNumeric::valueIsDouble($context, $read);
        $floatBlock = BasicBlockHelper::append($context, $increment ? 'inc_vbox_float' : 'dec_vbox_float');
        $longBlock = BasicBlockHelper::append($context, $increment ? 'inc_vbox_as_long' : 'dec_vbox_as_long');
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
     * Runtime string ++/-- into a value box (zend increment_function IS_STRING). (#32435)
     *
     * Numeric strings promote like VM {@see \PHPCompiler\VM\Variable} storeNumericStringIncDec;
     * non-numeric ++ uses increment_string(); non-numeric -- is a no-op; empty -- is int(-1).
     */
    public static function writeStringPtrIncDec(
        Context $context,
        LlvmValue $strPtr,
        LlvmValue $writePtr,
        bool $increment
    ): void {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'incdec_str_cont');
        $map = $context->structFieldMap['__string__'];
        $len = $context->builder->load(
            $context->builder->structGep($strPtr, $map['length'])
        );
        $isEmpty = $context->builder->icmp(
            Builder::INT_EQ,
            $len,
            $len->typeOf()->constInt(0, false)
        );
        $emptyBlock = BasicBlockHelper::append($context, $increment ? 'inc_str_empty' : 'dec_str_empty');
        $kindBlock = BasicBlockHelper::append($context, 'incdec_str_kind');
        $doneBlock = BasicBlockHelper::append($context, 'incdec_str_done');
        $context->builder->branchIf($isEmpty, $emptyBlock, $kindBlock);

        $context->builder->positionAtEnd($emptyBlock);
        if ($increment) {
            $oneStr = StringStrIncdec::invokeOperatorIncrement($context, $strPtr);
            $context->builder->call(
                $context->lookupFunction('__value__writeString'),
                $writePtr,
                $oneStr
            );
        } else {
            $i64 = $context->getTypeFromString('int64');
            $context->builder->call(
                $context->lookupFunction('__value__writeLong'),
                $writePtr,
                $i64->constInt(-1, true)
            );
        }
        JitValueBox::publishAfterWrite($context, $writePtr);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($kindBlock);
        $kind = StringStrIncdec::invokeNumericKind($context, $strPtr);
        $i64 = $context->getTypeFromString('int64');
        $isNonNum = $context->builder->icmp(Builder::INT_EQ, $kind, $i64->constInt(0, false));
        $nonNumBlock = BasicBlockHelper::append($context, 'incdec_str_nonnum');
        $numBlock = BasicBlockHelper::append($context, 'incdec_str_num');
        $context->builder->branchIf($isNonNum, $nonNumBlock, $numBlock);

        $context->builder->positionAtEnd($nonNumBlock);
        if ($increment) {
            $newStr = StringStrIncdec::invokeOperatorIncrement($context, $strPtr);
            $context->builder->call(
                $context->lookupFunction('__value__writeString'),
                $writePtr,
                $newStr
            );
        } else {
            $context->builder->call(
                $context->lookupFunction('__value__writeString'),
                $writePtr,
                $strPtr
            );
        }
        JitValueBox::publishAfterWrite($context, $writePtr);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($numBlock);
        $isFloat = $context->builder->icmp(Builder::INT_EQ, $kind, $i64->constInt(2, false));
        $floatBlock = BasicBlockHelper::append($context, 'incdec_str_float');
        $intBlock = BasicBlockHelper::append($context, 'incdec_str_int');
        $context->builder->branchIf($isFloat, $floatBlock, $intBlock);

        $context->builder->positionAtEnd($floatBlock);
        $f64 = $context->getTypeFromString('double');
        $cur = JitLongArg::lowerStringToDouble($context, $strPtr);
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

        $context->builder->positionAtEnd($intBlock);
        $curLong = JitLongArg::lowerStringValue($context, $strPtr);
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
