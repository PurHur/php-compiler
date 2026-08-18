<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\VM\VmIncDec;
use PHPLLVM\Builder;
use PHPLLVM\Value as LlvmValue;

/**
 * JIT ++/-- with PHP_INT_MAX/MIN → double promotion (#29144),
 * boxed/native float ± 1.0 (#32281), IS_NULL decrement no-op (#32297),
 * and IS_STRING increment_string / numeric convert (#32435).
 *
 * @see php-src Zend/zend_operators.h fast_long_increment_function /
 *      fast_long_decrement_function
 * @see php-src Zend/zend_operators.c increment_function / decrement_function
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
     * Boxed ++/--: IS_STRING increment_string (#32435), IS_DOUBLE ± 1.0, else long overflow.
     *
     * NestedJIT helpers keep the float/long path only: emitting {@see writeStringIncDecToValuePtr}
     * NestedJITs StrIncdecJitHelper from inside WeakRef/session and SIGSEGVs getInsertBlock().
     * In-place writeString of the same pointer UAFs (valueDelref then store).
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
        if (NestedJitCompileScope::isActive()) {
            self::writeFloatOrLongIncDec($context, $read, $curLong, $writePtr, $increment);

            return;
        }

        $isDouble = JitValueNumeric::valueIsDouble($context, $read);
        $isString = JitValueNumeric::valueIsJitString($context, $read);
        $strBlock = BasicBlockHelper::append($context, $increment ? 'inc_vbox_string' : 'dec_vbox_string');
        $floatBlock = BasicBlockHelper::append($context, $increment ? 'inc_vbox_float' : 'dec_vbox_float');
        $longBlock = BasicBlockHelper::append($context, $increment ? 'inc_vbox_as_long' : 'dec_vbox_as_long');
        $doneBlock = BasicBlockHelper::append($context, 'incdec_vbox_typed_done');
        $notStrBlock = BasicBlockHelper::append($context, $increment ? 'inc_vbox_not_string' : 'dec_vbox_not_string');
        $context->builder->branchIf($isString, $strBlock, $notStrBlock);

        $context->builder->positionAtEnd($notStrBlock);
        $context->builder->branchIf($isDouble, $floatBlock, $longBlock);

        $context->builder->positionAtEnd($strBlock);
        $readPtr = JitValueBox::valuePtrFromVariable($context, $read);
        $strPtr = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $readPtr
        );
        $strPtrTy = $context->getTypeFromString('__string__*');
        $isNull = $context->builder->icmp(
            Builder::INT_EQ,
            $strPtr,
            $strPtrTy->constNull()
        );
        $strBody = BasicBlockHelper::append($context, $increment ? 'inc_vbox_string_body' : 'dec_vbox_string_body');
        $strLong = BasicBlockHelper::append($context, $increment ? 'inc_vbox_string_as_long' : 'dec_vbox_string_as_long');
        $context->builder->branchIf($isNull, $strLong, $strBody);

        $context->builder->positionAtEnd($strLong);
        self::writeLongIncDecToValuePtr($context, $curLong, $writePtr, $increment);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($strBody);
        self::writeStringIncDecToValuePtr($context, $strPtr, $writePtr, $increment, true);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($floatBlock);
        self::writeDoubleIncDecToValuePtr($context, $read, $writePtr, $increment);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($longBlock);
        self::writeLongIncDecToValuePtr($context, $curLong, $writePtr, $increment);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
    }

    private static function writeFloatOrLongIncDec(
        Context $context,
        Variable $read,
        LlvmValue $curLong,
        LlvmValue $writePtr,
        bool $increment
    ): void {
        $isDouble = JitValueNumeric::valueIsDouble($context, $read);
        $floatBlock = BasicBlockHelper::append($context, $increment ? 'inc_vbox_float' : 'dec_vbox_float');
        $longBlock = BasicBlockHelper::append($context, $increment ? 'inc_vbox_as_long' : 'dec_vbox_as_long');
        $doneBlock = BasicBlockHelper::append($context, 'incdec_vbox_typed_done');
        $context->builder->branchIf($isDouble, $floatBlock, $longBlock);

        $context->builder->positionAtEnd($floatBlock);
        self::writeDoubleIncDecToValuePtr($context, $read, $writePtr, $increment);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($longBlock);
        self::writeLongIncDecToValuePtr($context, $curLong, $writePtr, $increment);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
    }

    private static function writeDoubleIncDecToValuePtr(
        Context $context,
        Variable $read,
        LlvmValue $writePtr,
        bool $increment
    ): void {
        $readPtrFloat = JitValueBox::valuePtrFromVariable($context, $read);
        $f64 = $context->getTypeFromString('double');
        $cur = $context->builder->call(
            $context->lookupFunction('__value__readDouble'),
            $readPtrFloat
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
    }

    /**
     * Zend increment_function IS_STRING: numeric convert then ±1, else increment_string().
     *
     * `$inPlace`: write target is the same box the string was read from. writeString of that
     * pointer UAFs (valueDelref then store). Perl `--` is a no-op on that box.
     *
     * @see php-src Zend/zend_operators.c increment_function / decrement_function
     */
    public static function writeStringIncDecToValuePtr(
        Context $context,
        LlvmValue $strPtr,
        LlvmValue $writePtr,
        bool $increment,
        bool $inPlace = false
    ): void {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'incdec_string_cont');
        $i64 = $context->getTypeFromString('int64');
        $isNumeric = TypedParamCoerce::stringIsNumeric($context, $strPtr);
        $numBlock = BasicBlockHelper::append($context, $increment ? 'inc_str_numeric' : 'dec_str_numeric');
        $perlBlock = BasicBlockHelper::append($context, $increment ? 'inc_str_perl' : 'dec_str_perl');
        $doneBlock = BasicBlockHelper::append($context, 'incdec_string_done');
        $context->builder->branchIf($isNumeric, $numBlock, $perlBlock);

        $context->builder->positionAtEnd($numBlock);
        $curLong = JitLongArg::lowerStringValue($context, $strPtr);
        self::writeLongIncDecToValuePtr($context, $curLong, $writePtr, $increment);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($perlBlock);
        if ($increment) {
            $len = $context->builder->call(
                $context->lookupFunction('__string__strlen'),
                $strPtr
            );
            $isEmpty = $context->builder->icmp(
                Builder::INT_EQ,
                $len,
                $len->typeOf()->constInt(0, false)
            );
            $emptyBlock = BasicBlockHelper::append($context, 'inc_str_empty');
            $alnumBlock = BasicBlockHelper::append($context, 'inc_str_alnum');
            $context->builder->branchIf($isEmpty, $emptyBlock, $alnumBlock);

            $context->builder->positionAtEnd($emptyBlock);
            $oneStr = $context->builder->load($context->constantStringFromString('1'));
            self::storeStringInValuePtr($context, $writePtr, $oneStr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($alnumBlock);
            if (!NestedJitCompileScope::isActive()) {
                $newStr = self::invokeIncrementStringOperator($context, $strPtr);
                self::storeStringInValuePtr($context, $writePtr, $newStr);
            } elseif (!$inPlace) {
                self::storeStringInValuePtr($context, $writePtr, $strPtr);
            }
            $context->builder->branch($doneBlock);
        } else {
            $len = $context->builder->call(
                $context->lookupFunction('__string__strlen'),
                $strPtr
            );
            $isEmpty = $context->builder->icmp(
                Builder::INT_EQ,
                $len,
                $len->typeOf()->constInt(0, false)
            );
            $emptyBlock = BasicBlockHelper::append($context, 'dec_str_empty');
            $keepBlock = BasicBlockHelper::append($context, 'dec_str_keep');
            $context->builder->branchIf($isEmpty, $emptyBlock, $keepBlock);

            $context->builder->positionAtEnd($emptyBlock);
            $context->builder->call(
                $context->lookupFunction('__value__writeLong'),
                $writePtr,
                $i64->constInt(-1, true)
            );
            JitValueBox::publishAfterWrite($context, $writePtr);
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($keepBlock);
            if (!$inPlace) {
                self::storeStringInValuePtr($context, $writePtr, $strPtr);
            }
            $context->builder->branch($doneBlock);
        }

        $context->builder->positionAtEnd($doneBlock);
    }

    /**
     * Zend increment_string() as a module-level LLVM helper (no NestedJIT).
     *
     * @see php-src Zend/zend_operators.c increment_string
     */
    private static function invokeIncrementStringOperator(
        Context $context,
        LlvmValue $strPtr
    ): LlvmValue {
        self::ensureIncrementStringOperator($context);

        return $context->builder->call(
            $context->lookupFunction('__phpc_increment_string_op'),
            $strPtr
        );
    }

    private static function ensureIncrementStringOperator(Context $context): void
    {
        $name = '__phpc_increment_string_op';
        $probe = $context->module->getNamedFunction($name);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($name, $probe);

            return;
        }
        $savedBlock = BasicBlockHelper::tryGetInsertBlock($context);
        $savedActive = $context->activeFunction;
        $savedLowering = $context->loweringLlvmFunction;
        $strPtrTy = $context->getTypeFromString('__string__*');
        $fn = $probe;
        if (null === $fn) {
            $fn = $context->module->addFunction(
                $name,
                $context->context->functionType($strPtrTy, false, $strPtrTy)
            );
        }
        $context->registerFunction($name, $fn);
        $context->activeFunction = $name;
        $context->loweringLlvmFunction = $fn;
        $entry = $fn->appendBasicBlock('inc_str_op_entry');
        $context->builder->positionAtEnd($entry);
        try {
            self::emitIncrementStringOperatorBody($context, $fn);
        } finally {
            $context->activeFunction = $savedActive;
            $context->loweringLlvmFunction = $savedLowering;
            if (null !== $savedBlock) {
                BasicBlockHelper::restoreInsertBlock($context, $savedBlock);
            } else {
                $context->builder->clearInsertionPosition();
            }
        }
    }

    /**
     * Copy, then perl-increment alphanumeric trailing bytes; grow on full wrap.
     *
     * @param \PHPLLVM\Value\Function_ $fn
     */
    private static function emitIncrementStringOperatorBody(Context $context, $fn): void
    {
        $b = $context->builder;
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $in = $fn->getParam(0);
        $len = $b->call($context->lookupFunction('__string__strlen'), $in);
        $map = $context->structFieldMap['__string__'];
        $src = $b->pointerCast(
            $b->structGep($in, $map['value']),
            $context->getTypeFromString('int8*')
        );
        $copy = $b->call($context->lookupFunction('__string__init'), $len, $src);
        $dst = $b->pointerCast(
            $b->structGep($copy, $map['value']),
            $context->getTypeFromString('int8*')
        );

        $iSlot = $b->alloca($i64);
        $lastSlot = $b->alloca($i32);
        $b->store($b->sub($len, $i64->constInt(1, false)), $iSlot);
        $b->store($i32->constInt(0, false), $lastSlot);

        $loop = $fn->appendBasicBlock('inc_str_op_loop');
        $lower = $fn->appendBasicBlock('inc_str_op_lower');
        $upper = $fn->appendBasicBlock('inc_str_op_upper');
        $digit = $fn->appendBasicBlock('inc_str_op_digit');
        $retCopy = $fn->appendBasicBlock('inc_str_op_ret_copy');
        $afterCarry = $fn->appendBasicBlock('inc_str_op_after_carry');
        $decI = $fn->appendBasicBlock('inc_str_op_dec_i');
        $grow = $fn->appendBasicBlock('inc_str_op_grow');
        $b->branch($loop);

        $b->positionAtEnd($loop);
        $i = $b->load($iSlot);
        $cptr = $b->gep($dst, $i);
        $c = $b->load($cptr);
        $isLower = $b->and(
            $b->icmp(Builder::INT_UGE, $c, $i8->constInt(97, false)),
            $b->icmp(Builder::INT_ULE, $c, $i8->constInt(122, false))
        );
        $notLower = $fn->appendBasicBlock('inc_str_op_not_lower');
        $b->branchIf($isLower, $lower, $notLower);

        $b->positionAtEnd($lower);
        $b->store($i32->constInt(1, false), $lastSlot);
        $isZ = $b->icmp(Builder::INT_EQ, $c, $i8->constInt(122, false));
        $bumpL = $fn->appendBasicBlock('inc_str_op_bump_l');
        $wrapL = $fn->appendBasicBlock('inc_str_op_wrap_l');
        $b->branchIf($isZ, $wrapL, $bumpL);
        $b->positionAtEnd($bumpL);
        $b->store($b->add($c, $i8->constInt(1, false)), $cptr);
        $b->branch($retCopy);
        $b->positionAtEnd($wrapL);
        $b->store($i8->constInt(97, false), $cptr);
        $b->branch($afterCarry);

        $b->positionAtEnd($notLower);
        $isUpper = $b->and(
            $b->icmp(Builder::INT_UGE, $c, $i8->constInt(65, false)),
            $b->icmp(Builder::INT_ULE, $c, $i8->constInt(90, false))
        );
        $notUpper = $fn->appendBasicBlock('inc_str_op_not_upper');
        $b->branchIf($isUpper, $upper, $notUpper);

        $b->positionAtEnd($upper);
        $b->store($i32->constInt(2, false), $lastSlot);
        $isZed = $b->icmp(Builder::INT_EQ, $c, $i8->constInt(90, false));
        $bumpU = $fn->appendBasicBlock('inc_str_op_bump_u');
        $wrapU = $fn->appendBasicBlock('inc_str_op_wrap_u');
        $b->branchIf($isZed, $wrapU, $bumpU);
        $b->positionAtEnd($bumpU);
        $b->store($b->add($c, $i8->constInt(1, false)), $cptr);
        $b->branch($retCopy);
        $b->positionAtEnd($wrapU);
        $b->store($i8->constInt(65, false), $cptr);
        $b->branch($afterCarry);

        $b->positionAtEnd($notUpper);
        $isDigit = $b->and(
            $b->icmp(Builder::INT_UGE, $c, $i8->constInt(48, false)),
            $b->icmp(Builder::INT_ULE, $c, $i8->constInt(57, false))
        );
        $b->branchIf($isDigit, $digit, $retCopy);

        $b->positionAtEnd($digit);
        $b->store($i32->constInt(3, false), $lastSlot);
        $isNine = $b->icmp(Builder::INT_EQ, $c, $i8->constInt(57, false));
        $bumpD = $fn->appendBasicBlock('inc_str_op_bump_d');
        $wrapD = $fn->appendBasicBlock('inc_str_op_wrap_d');
        $b->branchIf($isNine, $wrapD, $bumpD);
        $b->positionAtEnd($bumpD);
        $b->store($b->add($c, $i8->constInt(1, false)), $cptr);
        $b->branch($retCopy);
        $b->positionAtEnd($wrapD);
        $b->store($i8->constInt(48, false), $cptr);
        $b->branch($afterCarry);

        $b->positionAtEnd($afterCarry);
        $atStart = $b->icmp(Builder::INT_EQ, $i, $i64->constInt(0, false));
        $b->branchIf($atStart, $grow, $decI);

        $b->positionAtEnd($decI);
        $b->store($b->sub($i, $i64->constInt(1, false)), $iSlot);
        $b->branch($loop);

        $b->positionAtEnd($grow);
        $last = $b->load($lastSlot);
        $isLastUpper = $b->icmp(Builder::INT_EQ, $last, $i32->constInt(2, false));
        $isLastDigit = $b->icmp(Builder::INT_EQ, $last, $i32->constInt(3, false));
        $prefUpper = $fn->appendBasicBlock('inc_str_op_pref_u');
        $prefRest = $fn->appendBasicBlock('inc_str_op_pref_rest');
        $prefDigit = $fn->appendBasicBlock('inc_str_op_pref_d');
        $prefLower = $fn->appendBasicBlock('inc_str_op_pref_l');
        $prefMerge = $fn->appendBasicBlock('inc_str_op_pref_merge');
        $b->branchIf($isLastUpper, $prefUpper, $prefRest);
        $b->positionAtEnd($prefUpper);
        $b->branch($prefMerge);
        $b->positionAtEnd($prefRest);
        $b->branchIf($isLastDigit, $prefDigit, $prefLower);
        $b->positionAtEnd($prefDigit);
        $first = $b->load($b->gep($dst, $i64->constInt(0, false)));
        $firstIsZero = $b->icmp(Builder::INT_EQ, $first, $i8->constInt(48, false));
        $digitPref = $b->select($firstIsZero, $i8->constInt(49, false), $first);
        $b->branch($prefMerge);
        $b->positionAtEnd($prefLower);
        $b->branch($prefMerge);
        $b->positionAtEnd($prefMerge);
        $prefix = $b->phi($i8, 'inc_str_op_prefix');
        $prefix->addIncoming($i8->constInt(65, false), $prefUpper);
        $prefix->addIncoming($digitPref, $prefDigit);
        $prefix->addIncoming($i8->constInt(97, false), $prefLower);
        $newLen = $b->add($len, $i64->constInt(1, false));
        $grown = $b->call($context->lookupFunction('__string__alloc'), $newLen);
        $grownDst = $b->pointerCast(
            $b->structGep($grown, $map['value']),
            $context->getTypeFromString('int8*')
        );
        $b->store($prefix, $b->gep($grownDst, $i64->constInt(0, false)));
        $tail = $b->gep($grownDst, $i64->constInt(1, false));
        $context->intrinsic->memcpy($tail, $dst, $len, false);
        $b->returnValue($grown);

        $b->positionAtEnd($retCopy);
        $b->returnValue($copy);
    }

    private static function storeStringInValuePtr(
        Context $context,
        LlvmValue $writePtr,
        LlvmValue $strPtr
    ): void {
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $writePtr,
            $strPtr
        );
        JitValueBox::publishAfterWrite($context, $writePtr);
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
