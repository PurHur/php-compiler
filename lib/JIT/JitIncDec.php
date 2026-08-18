<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\ext\standard\is_numeric;
use PHPCompiler\VM\VmIncDec;
use PHPLLVM\Builder;
use PHPLLVM\Value as LlvmValue;
use PHPLLVM\Value\Function_ as LlvmFunction;

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
        // NestedJIT of phpc_str_increment mid-helper compile loses the insert block (#32435).
        LibcExtern::ensureStrtodDecl($context);
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
        $restBlock = BasicBlockHelper::append($context, 'incdec_str_rest');
        $doneBlock = BasicBlockHelper::append($context, 'incdec_str_done');
        $context->builder->branchIf($isEmpty, $emptyBlock, $restBlock);

        $context->builder->positionAtEnd($emptyBlock);
        if ($increment) {
            $i64 = $context->getTypeFromString('int64');
            $oneStr = $context->builder->call(
                $context->lookupFunction('__string__init'),
                $i64->constInt(1, false),
                $context->pointerFromStringConstant('1')
            );
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

        $context->builder->positionAtEnd($restBlock);
        $isNum = is_numeric::llvmStringIsNumeric($context, $strPtr);
        $numBlock = BasicBlockHelper::append($context, 'incdec_str_num');
        $alnumBlock = BasicBlockHelper::append($context, 'incdec_str_alnum');
        $context->builder->branchIf($isNum, $numBlock, $alnumBlock);

        $context->builder->positionAtEnd($numBlock);
        $curLong = JitLongArg::lowerStringValue($context, $strPtr);
        self::writeLongIncDecToValuePtr($context, $curLong, $writePtr, $increment);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($alnumBlock);
        if ($increment) {
            $newStr = self::incrementStringOperator($context, $strPtr);
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

    /**
     * zend_operators.c increment_string() as module LLVM — no NestedJIT (#32435).
     */
    private static function incrementStringOperator(Context $context, LlvmValue $strPtr): LlvmValue
    {
        self::ensureIncrementStringOp($context);

        return $context->builder->call(
            $context->lookupFunction('__string__increment_string'),
            $strPtr
        );
    }

    private static function ensureIncrementStringOp(Context $context): void
    {
        $name = '__string__increment_string';
        $probe = $context->module->getNamedFunction($name);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($name, $probe);

            return;
        }
        $restore = BasicBlockHelper::tryGetInsertBlock($context);
        try {
            self::emitIncrementStringOp($context, $probe);
        } finally {
            BasicBlockHelper::restoreInsertBlock($context, $restore);
        }
    }

    private static function emitIncrementStringOp(Context $context, ?LlvmFunction $probe): void
    {
        $strPtrTy = $context->getTypeFromString('__string__*');
        $fn = $probe ?? $context->module->addFunction(
            '__string__increment_string',
            $context->context->functionType($strPtrTy, false, $strPtrTy)
        );
        $context->registerFunction('__string__increment_string', $fn);
        $b = $context->builder;
        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');
        $map = $context->structFieldMap['__string__'];
        $ch = static fn (int $ord) => $i8->constInt($ord, false);

        $entry = $fn->appendBasicBlock('incstr_entry');
        $empty = $fn->appendBasicBlock('incstr_empty');
        $copy = $fn->appendBasicBlock('incstr_copy');
        $loop = $fn->appendBasicBlock('incstr_loop');
        $lower = $fn->appendBasicBlock('incstr_lower');
        $lowerBump = $fn->appendBasicBlock('incstr_lower_bump');
        $upper = $fn->appendBasicBlock('incstr_upper');
        $upperIs = $fn->appendBasicBlock('incstr_upper_is');
        $upperBump = $fn->appendBasicBlock('incstr_upper_bump');
        $wrapZ = $fn->appendBasicBlock('incstr_wrap_z');
        $digit = $fn->appendBasicBlock('incstr_digit');
        $digitIs = $fn->appendBasicBlock('incstr_digit_is');
        $digitBump = $fn->appendBasicBlock('incstr_digit_bump');
        $wrap9 = $fn->appendBasicBlock('incstr_wrap_9');
        $nonAlnum = $fn->appendBasicBlock('incstr_non_alnum');
        $carry = $fn->appendBasicBlock('incstr_carry');
        $next = $fn->appendBasicBlock('incstr_next');
        $prepend = $fn->appendBasicBlock('incstr_prepend');
        $preUpper = $fn->appendBasicBlock('incstr_pre_upper');
        $preDigit = $fn->appendBasicBlock('incstr_pre_digit');
        $preLower = $fn->appendBasicBlock('incstr_pre_lower');
        $preDigitZero = $fn->appendBasicBlock('incstr_pre_digit_zero');
        $preDigitKeep = $fn->appendBasicBlock('incstr_pre_digit_keep');
        $preAlloc = $fn->appendBasicBlock('incstr_pre_alloc');

        $b->positionAtEnd($entry);
        $src = $fn->getParam(0);
        $len = $b->load($b->structGep($src, $map['length']));
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);
        $b->branchIf($b->icmp(Builder::INT_EQ, $len, $zero), $empty, $copy);

        $b->positionAtEnd($empty);
        $b->returnValue($b->call(
            $context->lookupFunction('__string__init'),
            $i64->constInt(1, false),
            $context->pointerFromStringConstant('1')
        ));

        $b->positionAtEnd($copy);
        $out = $b->call($context->lookupFunction('__string__alloc'), $len);
        $srcData = $b->structGep($src, $map['value']);
        $outData = $b->structGep($out, $map['value']);
        $context->intrinsic->memcpy($outData, $srcData, $len, false);
        $idxSlot = BasicBlockHelper::entryAllocaForFunction($context, $fn, $i64);
        $lastSlot = BasicBlockHelper::entryAllocaForFunction($context, $fn, $i64);
        $b->store($b->sub($len, $one), $idxSlot);
        $b->store($zero, $lastSlot);
        $b->branch($loop);

        $b->positionAtEnd($loop);
        $idx = $b->load($idxSlot);
        $cPtr = $b->gep($outData, $idx);
        $c = $b->load($cPtr);
        $isLower = $b->and(
            $b->icmp(Builder::INT_SGE, $c, $ch(97)),
            $b->icmp(Builder::INT_SLE, $c, $ch(122))
        );
        $b->branchIf($isLower, $lower, $upper);

        $b->positionAtEnd($lower);
        $b->store($i64->constInt(1, false), $lastSlot);
        $b->branchIf($b->icmp(Builder::INT_EQ, $c, $ch(122)), $carry, $lowerBump);
        $b->positionAtEnd($lowerBump);
        $b->store($b->add($c, $ch(1)), $cPtr);
        $b->returnValue($out);

        $b->positionAtEnd($upper);
        $isUpper = $b->and(
            $b->icmp(Builder::INT_SGE, $c, $ch(65)),
            $b->icmp(Builder::INT_SLE, $c, $ch(90))
        );
        $b->branchIf($isUpper, $upperIs, $digit);

        $b->positionAtEnd($upperIs);
        $b->store($i64->constInt(2, false), $lastSlot);
        $b->branchIf($b->icmp(Builder::INT_EQ, $c, $ch(90)), $wrapZ, $upperBump);
        $b->positionAtEnd($wrapZ);
        $b->store($ch(65), $cPtr);
        $b->branch($carry);
        $b->positionAtEnd($upperBump);
        $b->store($b->add($c, $ch(1)), $cPtr);
        $b->returnValue($out);

        $b->positionAtEnd($digit);
        $isDigit = $b->and(
            $b->icmp(Builder::INT_SGE, $c, $ch(48)),
            $b->icmp(Builder::INT_SLE, $c, $ch(57))
        );
        $b->branchIf($isDigit, $digitIs, $nonAlnum);

        $b->positionAtEnd($digitIs);
        $b->store($i64->constInt(3, false), $lastSlot);
        $b->branchIf($b->icmp(Builder::INT_EQ, $c, $ch(57)), $wrap9, $digitBump);
        $b->positionAtEnd($wrap9);
        $b->store($ch(48), $cPtr);
        $b->branch($carry);
        $b->positionAtEnd($digitBump);
        $b->store($b->add($c, $ch(1)), $cPtr);
        $b->returnValue($out);

        $b->positionAtEnd($nonAlnum);
        $b->returnValue($out);

        $b->positionAtEnd($carry);
        // 'z' wrap stores 'a' here; 'Z'/'9' already stored before this block.
        $isLastLower = $b->icmp(
            Builder::INT_EQ,
            $b->load($lastSlot),
            $i64->constInt(1, false)
        );
        $storeA = $fn->appendBasicBlock('incstr_store_a');
        $afterWrap = $fn->appendBasicBlock('incstr_after_wrap');
        $b->branchIf($isLastLower, $storeA, $afterWrap);
        $b->positionAtEnd($storeA);
        $b->store($ch(97), $cPtr);
        $b->branch($afterWrap);
        $b->positionAtEnd($afterWrap);
        $b->branchIf(
            $b->icmp(Builder::INT_EQ, $b->load($idxSlot), $zero),
            $prepend,
            $next
        );

        $b->positionAtEnd($next);
        $b->store($b->sub($b->load($idxSlot), $one), $idxSlot);
        $b->branch($loop);

        $b->positionAtEnd($prepend);
        $last = $b->load($lastSlot);
        $isU = $b->icmp(Builder::INT_EQ, $last, $i64->constInt(2, false));
        $notU = $fn->appendBasicBlock('incstr_pre_not_u');
        $b->branchIf($isU, $preUpper, $notU);
        $b->positionAtEnd($notU);
        $isD = $b->icmp(Builder::INT_EQ, $last, $i64->constInt(3, false));
        $b->branchIf($isD, $preDigit, $preLower);

        $b->positionAtEnd($preUpper);
        $b->branch($preAlloc);
        $b->positionAtEnd($preLower);
        $b->branch($preAlloc);
        $b->positionAtEnd($preDigit);
        $first = $b->load($b->gep($outData, $zero));
        $b->branchIf($b->icmp(Builder::INT_EQ, $first, $ch(48)), $preDigitZero, $preDigitKeep);
        $b->positionAtEnd($preDigitZero);
        $b->branch($preAlloc);
        $b->positionAtEnd($preDigitKeep);
        $b->branch($preAlloc);

        $b->positionAtEnd($preAlloc);
        $prefix = $b->phi($i8);
        $prefix->addIncoming($ch(65), $preUpper);
        $prefix->addIncoming($ch(97), $preLower);
        $prefix->addIncoming($ch(49), $preDigitZero);
        $prefix->addIncoming($first, $preDigitKeep);
        $grownLen = $b->add($len, $one);
        $grown = $b->call($context->lookupFunction('__string__alloc'), $grownLen);
        $grownData = $b->structGep($grown, $map['value']);
        $b->store($prefix, $grownData);
        $tail = $b->gep($grownData, $one);
        $context->intrinsic->memcpy($tail, $outData, $len, false);
        $b->returnValue($grown);
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
