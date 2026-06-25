<?php

declare(strict_types=1);

namespace PHPCompiler\ext\filter;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringFilterBoolean;
use PHPCompiler\JIT\Builtin\StringFilterEmail;
use PHPCompiler\JIT\Builtin\StringFilterInt;
use PHPCompiler\JIT\Builtin\StringFilterUrl;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM JIT/AOT helpers for filter_var() / filter_input() (issues #104, #6028). */
final class JitFilter
{
    private static int $blockSerial = 0;

    public static function loadFilterId(Context $context, JITVariable $filter): Value
    {
        if (JITVariable::TYPE_NATIVE_LONG === $filter->type) {
            return $context->helper->loadValue($filter);
        }
        if (JITVariable::TYPE_VALUE === $filter->type) {
            $ptrType = $context->getTypeFromString('__value__*');
            $ptr = JITVariable::KIND_VALUE === $filter->kind
                ? $filter->value
                : $context->builder->pointerCast($filter->value, $ptrType);

            return $context->builder->call(
                $context->lookupFunction('__value__readLong'),
                $ptr
            );
        }

        throw new \LogicException('filter argument must be an integer constant in this compiler build');
    }

    public static function asValueVar(Context $context, JITVariable $arg): JITVariable
    {
        if (JITVariable::TYPE_NULL === $arg->type) {
            return $arg;
        }
        if (JITVariable::TYPE_STRING === $arg->type
            || JITVariable::TYPE_NATIVE_LONG === $arg->type
            || JITVariable::TYPE_NATIVE_BOOL === $arg->type
            || JITVariable::TYPE_NATIVE_DOUBLE === $arg->type) {
            return $arg;
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return $arg;
        }

        throw new \LogicException('filter_var() value type is not supported in this compiler build');
    }

    public static function boxedNull(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $ptr);

        return $ptr;
    }

    public static function boxedFalse(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));

        return $ptr;
    }

    public static function loadNullOnFailureFlag(Context $context, ?JITVariable $options): Value
    {
        if (null === $options || JITVariable::TYPE_NULL === $options->type) {
            return $context->constantFromBool(false);
        }

        $optionsVal = self::loadFilterId($context, $options);
        $i64 = $context->getTypeFromString('int64');
        $flag = $i64->constInt(VmFilter::FILTER_NULL_ON_FAILURE, false);
        $masked = $context->builder->and($optionsVal, $flag);

        return $context->builder->icmp(
            Builder::INT_NE,
            $masked,
            $i64->constInt(0, false)
        );
    }

    public static function loadFilterFlags(Context $context, ?JITVariable $options): Value
    {
        if (null === $options || JITVariable::TYPE_NULL === $options->type) {
            return $context->getTypeFromString('int64')->constInt(0, false);
        }

        return self::loadFilterId($context, $options);
    }

    private static function flagsNeedExtendedIntParse(Context $context, Value $flagsVal): bool
    {
        $lib = $context->llvm->lib;
        if (null === $lib->LLVMIsAConstantInt($flagsVal->value)) {
            return true;
        }
        $flags = (int) $lib->LLVMConstIntGetZExtValue($flagsVal->value);

        return 0 !== ($flags & (VmFilter::FILTER_FLAG_ALLOW_HEX | VmFilter::FILTER_FLAG_ALLOW_OCTAL));
    }

    /** When flag is set, rewrite boxed false validation results to null. */
    public static function applyNullOnFailure(Context $context, Value $resultPtr, Value $nullOnFailure): Value
    {
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($resultPtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $boolTag = $i8->constInt(JITVariable::TYPE_NATIVE_BOOL, false);
        $isBool = $context->builder->icmp(Builder::INT_EQ, $typeByte, $boolTag);
        $stored = $context->builder->call(
            $context->lookupFunction('__value__readLong'),
            $resultPtr
        );
        $zero = $context->getTypeFromString('int64')->constInt(0, false);
        $isFalse = $context->builder->icmp(Builder::INT_EQ, $stored, $zero);
        $isBoolFalse = $context->builder->and($isBool, $isFalse);
        $shouldRewrite = $context->builder->and($nullOnFailure, $isBoolFalse);

        $id = (string) (++self::$blockSerial);
        $rewriteBlock = BasicBlockHelper::append($context, 'fv_null_on_fail_'.$id);
        $keepBlock = BasicBlockHelper::append($context, 'fv_keep_result_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'fv_null_on_fail_done_'.$id);
        $context->builder->branchIf($shouldRewrite, $rewriteBlock, $keepBlock);

        $context->builder->positionAtEnd($rewriteBlock);
        $nullResult = self::boxedNull($context);
        $rewriteTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($keepBlock);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($resultPtr->typeOf());
        $phi->addIncoming($nullResult, $rewriteTail);
        $phi->addIncoming($resultPtr, $keepBlock);

        return $phi;
    }

    public static function validateBoolean(Context $context, JITVariable $value): Value
    {
        if (JITVariable::TYPE_VALUE === $value->type) {
            return self::boxValueValidateBoolean($context, $value);
        }

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $falseVal = $context->constantFromBool(false);
        $trueVal = $context->constantFromBool(true);

        if (JITVariable::TYPE_NATIVE_BOOL === $value->type) {
            JitValueBox::writeBool($context, $slot, $context->helper->loadValue($value));

            return $ptr;
        }
        if (JITVariable::TYPE_NATIVE_LONG === $value->type) {
            return self::longToBooleanBox($context, $context->helper->loadValue($value));
        }
        if (JITVariable::TYPE_NATIVE_DOUBLE === $value->type) {
            return self::doubleToBooleanBox($context, $context->helper->loadValue($value));
        }
        if (JITVariable::TYPE_NULL === $value->type
            || JITVariable::TYPE_STRING !== $value->type) {
            JitValueBox::writeBool($context, $slot, $falseVal);

            return $ptr;
        }

        return self::stringToBooleanBox($context, $context->helper->loadValue($value));
    }

    public static function validateFloat(Context $context, JITVariable $value): Value
    {
        if (JITVariable::TYPE_VALUE === $value->type) {
            return self::boxValueValidateFloat($context, $value);
        }

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $falseVal = $context->constantFromBool(false);

        if (JITVariable::TYPE_NATIVE_LONG === $value->type) {
            $dblTy = $context->getTypeFromString('double');
            $parsed = $context->builder->sitofp($context->helper->loadValue($value), $dblTy);
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $ptr,
                $parsed
            );

            return $ptr;
        }
        if (JITVariable::TYPE_NATIVE_DOUBLE === $value->type) {
            $context->builder->call(
                $context->lookupFunction('__value__writeDouble'),
                $ptr,
                $context->helper->loadValue($value)
            );

            return $ptr;
        }
        if (JITVariable::TYPE_NULL === $value->type
            || JITVariable::TYPE_STRING !== $value->type) {
            JitValueBox::writeBool($context, $slot, $falseVal);

            return $ptr;
        }

        return self::stringToFloatBox($context, $context->helper->loadValue($value));
    }

    public static function validateInt(Context $context, JITVariable $value, ?Value $flags = null): Value
    {
        if (JITVariable::TYPE_VALUE === $value->type) {
            return self::boxValueValidateInt($context, $value);
        }

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $falseVal = $context->constantFromBool(false);
        $i64 = $context->getTypeFromString('int64');
        $flagsVal = $flags ?? $i64->constInt(0, false);

        if (JITVariable::TYPE_NATIVE_LONG === $value->type) {
            JitValueBox::writeLong($context, $slot, $context->helper->loadValue($value));

            return $ptr;
        }
        if (JITVariable::TYPE_NULL === $value->type
            || JITVariable::TYPE_STRING !== $value->type) {
            JitValueBox::writeBool($context, $slot, $falseVal);

            return $ptr;
        }

        $str = $context->helper->loadValue($value);
        if (self::flagsNeedExtendedIntParse($context, $flagsVal)) {
            return self::validateIntStringWithFlags($context, $str, $flagsVal);
        }

        $isValid = self::stringIsFullInteger($context, $str);
        $parsed = self::stringToInt64($context, $str);

        $id = (string) (++self::$blockSerial);
        $okBlock = BasicBlockHelper::append($context, 'fvi_ok_'.$id);
        $failBlock = BasicBlockHelper::append($context, 'fvi_fail_'.$id);
        $mergeBlock = BasicBlockHelper::append($context, 'fvi_merge_'.$id);
        $context->builder->branchIf($isValid, $okBlock, $failBlock);

        $context->builder->positionAtEnd($okBlock);
        JitValueBox::writeLong($context, $slot, $parsed);
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($failBlock);
        JitValueBox::writeBool($context, $slot, $falseVal);
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($mergeBlock);

        return $ptr;
    }

    private static function validateIntStringWithFlags(Context $context, Value $str, Value $flagsVal): Value
    {
        StringFilterInt::ensureLinked($context);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $falseVal = $context->constantFromBool(false);
        $i64 = $context->getTypeFromString('int64');
        $failSentinel = $i64->constInt(-1, true);

        $parsed = $context->builder->call(
            $context->lookupFunction('__compiler_filter_validate_int_string'),
            $str,
            $flagsVal
        );
        $isOk = $context->builder->icmp(Builder::INT_NE, $parsed, $failSentinel);

        $id = (string) (++self::$blockSerial);
        $okBlock = BasicBlockHelper::append($context, 'fvi_flags_ok_'.$id);
        $failBlock = BasicBlockHelper::append($context, 'fvi_flags_fail_'.$id);
        $mergeBlock = BasicBlockHelper::append($context, 'fvi_flags_merge_'.$id);
        $context->builder->branchIf($isOk, $okBlock, $failBlock);

        $context->builder->positionAtEnd($okBlock);
        JitValueBox::writeLong($context, $slot, $parsed);
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($failBlock);
        JitValueBox::writeBool($context, $slot, $falseVal);
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($mergeBlock);

        return $ptr;
    }

    public static function validateEmail(Context $context, JITVariable $value): Value
    {
        StringFilterEmail::ensureLinked($context);
        if (JITVariable::TYPE_VALUE === $value->type) {
            return self::boxValueValidateEmail($context, $value);
        }

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $falseVal = $context->constantFromBool(false);

        if (JITVariable::TYPE_NULL === $value->type
            || JITVariable::TYPE_STRING !== $value->type) {
            JitValueBox::writeBool($context, $slot, $falseVal);

            return $ptr;
        }

        $str = $context->helper->loadValue($value);
        $validated = $context->builder->call(
            $context->lookupFunction('__compiler_filter_validate_email'),
            $str
        );
        $null = $context->getTypeFromString('__string__*')->constNull();
        $isNull = $context->builder->icmp(Builder::INT_EQ, $validated, $null);

        $id = (string) (++self::$blockSerial);
        $failBlock = BasicBlockHelper::append($context, 'fve_fail_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'fve_ok_'.$id);
        $mergeBlock = BasicBlockHelper::append($context, 'fve_merge_'.$id);
        $context->builder->branchIf($isNull, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        JitValueBox::writeBool($context, $slot, $falseVal);
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($okBlock);
        $owned = $context->builder->call($context->lookupFunction('__string__separate'), $str);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $owned
        );
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($mergeBlock);

        return $ptr;
    }

    public static function validateUrl(Context $context, JITVariable $value): Value
    {
        StringFilterUrl::ensureLinked($context);
        if (JITVariable::TYPE_VALUE === $value->type) {
            return self::boxValueValidateUrl($context, $value);
        }

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $falseVal = $context->constantFromBool(false);

        if (JITVariable::TYPE_NULL === $value->type
            || JITVariable::TYPE_STRING !== $value->type) {
            JitValueBox::writeBool($context, $slot, $falseVal);

            return $ptr;
        }

        $str = $context->helper->loadValue($value);
        $validated = $context->builder->call(
            $context->lookupFunction('__compiler_filter_validate_url'),
            $str
        );
        $null = $context->getTypeFromString('__string__*')->constNull();
        $isNull = $context->builder->icmp(Builder::INT_EQ, $validated, $null);

        $id = (string) (++self::$blockSerial);
        $failBlock = BasicBlockHelper::append($context, 'fvu_fail_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'fvu_ok_'.$id);
        $mergeBlock = BasicBlockHelper::append($context, 'fvu_merge_'.$id);
        $context->builder->branchIf($isNull, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        JitValueBox::writeBool($context, $slot, $falseVal);
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($okBlock);
        $owned = $context->builder->call($context->lookupFunction('__string__separate'), $str);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $owned
        );
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($mergeBlock);

        return $ptr;
    }

    private static function boxValueValidateInt(Context $context, JITVariable $arg): Value
    {
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $strVal = $context->builder->call($context->lookupFunction('__value__readString'), $valuePtr);
        $strPtrTy = $context->getTypeFromString('__string__*');
        $hasString = $context->builder->icmp(Builder::INT_NE, $strVal, $strPtrTy->constNull());

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);

        $stringBlock = BasicBlockHelper::append($context, 'fvi_box_string');
        $failBlock = BasicBlockHelper::append($context, 'fvi_box_fail');
        $doneBlock = BasicBlockHelper::append($context, 'fvi_box_done');

        $context->builder->branchIf($hasString, $stringBlock, $failBlock);

        $context->builder->positionAtEnd($stringBlock);
        $strVar = new JITVariable($context, JITVariable::TYPE_STRING, JITVariable::KIND_VALUE, $strVal);
        $stringResult = self::validateInt($context, $strVar);
        $stringTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($failBlock);
        $longVal = $context->builder->call($context->lookupFunction('__value__readLong'), $valuePtr);
        JitValueBox::writeLong($context, $slot, $longVal);
        $longTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($ptr->typeOf());
        $phi->addIncoming($stringResult, $stringTail);
        $phi->addIncoming($ptr, $longTail);

        return $phi;
    }

    private static function boxValueValidateEmail(Context $context, JITVariable $arg): Value
    {
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $strVal = $context->builder->call($context->lookupFunction('__value__readString'), $valuePtr);
        $strPtrTy = $context->getTypeFromString('__string__*');
        $hasString = $context->builder->icmp(Builder::INT_NE, $strVal, $strPtrTy->constNull());

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $falseVal = $context->constantFromBool(false);

        $stringBlock = BasicBlockHelper::append($context, 'fve_box_string');
        $failBlock = BasicBlockHelper::append($context, 'fve_box_fail');
        $doneBlock = BasicBlockHelper::append($context, 'fve_box_done');

        $context->builder->branchIf($hasString, $stringBlock, $failBlock);

        $context->builder->positionAtEnd($stringBlock);
        $strVar = new JITVariable($context, JITVariable::TYPE_STRING, JITVariable::KIND_VALUE, $strVal);
        $stringResult = self::validateEmail($context, $strVar);
        $stringTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($failBlock);
        JitValueBox::writeBool($context, $slot, $falseVal);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($ptr->typeOf());
        $phi->addIncoming($stringResult, $stringTail);
        $phi->addIncoming($ptr, $failBlock);

        return $phi;
    }

    private static function boxValueValidateUrl(Context $context, JITVariable $arg): Value
    {
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $strVal = $context->builder->call($context->lookupFunction('__value__readString'), $valuePtr);
        $strPtrTy = $context->getTypeFromString('__string__*');
        $hasString = $context->builder->icmp(Builder::INT_NE, $strVal, $strPtrTy->constNull());

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $falseVal = $context->constantFromBool(false);

        $stringBlock = BasicBlockHelper::append($context, 'fvu_box_string');
        $failBlock = BasicBlockHelper::append($context, 'fvu_box_fail');
        $doneBlock = BasicBlockHelper::append($context, 'fvu_box_done');

        $context->builder->branchIf($hasString, $stringBlock, $failBlock);

        $context->builder->positionAtEnd($stringBlock);
        $strVar = new JITVariable($context, JITVariable::TYPE_STRING, JITVariable::KIND_VALUE, $strVal);
        $stringResult = self::validateUrl($context, $strVar);
        $stringTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($failBlock);
        JitValueBox::writeBool($context, $slot, $falseVal);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($ptr->typeOf());
        $phi->addIncoming($stringResult, $stringTail);
        $phi->addIncoming($ptr, $failBlock);

        return $phi;
    }

    private static function stringIsFullInteger(Context $context, Value $strPtr): Value
    {
        $structName = $strPtr->typeOf()->getElementType()->getName();
        $map = $context->structFieldMap[$structName];
        $len = $context->builder->load(
            $context->builder->structGep($strPtr, $map['length'])
        );
        $zero = $len->typeOf()->constInt(0, false);
        $one = $len->typeOf()->constInt(1, false);
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $len, $zero);
        $charPtr = $context->builder->structGep($strPtr, $map['value']);
        $endPtrSlot = $context->builder->alloca(
            $context->getTypeFromString('int8*'),
            1,
            'filter_int_end'
        );
        $nullEnd = $context->getTypeFromString('int8*')->constNull();
        $context->builder->store($nullEnd, $endPtrSlot);
        $i32 = $context->getTypeFromString('int32');
        $context->builder->call(
            $context->lookupFunction('strtol'),
            $charPtr,
            $endPtrSlot,
            $i32->constInt(10, false)
        );
        $endPtr = $context->builder->load($endPtrSlot);
        $notConsumed = $context->builder->icmp(Builder::INT_EQ, $endPtr, $charPtr);
        $i64 = $context->getTypeFromString('int64');
        $endOffset = $context->builder->sub(
            $context->builder->ptrToInt($endPtr, $i64),
            $context->builder->ptrToInt($charPtr, $i64)
        );
        $consumedAll = $context->builder->icmp(Builder::INT_EQ, $endOffset, $len);
        $numeric = $context->builder->and(
            $context->builder->not($notConsumed),
            $consumedAll
        );

        $i8 = $context->getTypeFromString('int8');
        $firstByte = $context->builder->load($charPtr);
        $isPlus = $context->builder->icmp(
            Builder::INT_EQ,
            $firstByte,
            $i8->constInt(ord('+'), false)
        );
        $isMinus = $context->builder->icmp(
            Builder::INT_EQ,
            $firstByte,
            $i8->constInt(ord('-'), false)
        );
        $hasSign = $context->builder->or($isPlus, $isMinus);
        $digitOffset = $context->builder->select($hasSign, $one, $zero);
        $digitOffsetI64 = $context->builder->zExt($digitOffset, $i64);
        $remaining = $context->builder->sub($len, $digitOffset);
        $moreThanOneDigit = $context->builder->icmp(Builder::INT_UGT, $remaining, $one);
        $digitPtr = $context->builder->gep($charPtr, $digitOffsetI64);
        $digitByte = $context->builder->load($digitPtr);
        $isLeadingZero = $context->builder->icmp(
            Builder::INT_EQ,
            $digitByte,
            $i8->constInt(ord('0'), false)
        );
        $leadingZeroInvalid = $context->builder->and($moreThanOneDigit, $isLeadingZero);
        $noLeadingZeroIssue = $context->builder->not($leadingZeroInvalid);
        $validDigits = $context->builder->and($numeric, $noLeadingZeroIssue);

        return $context->builder->select($isEmpty, $context->constantFromBool(false), $validDigits);
    }

    private static function stringToInt64(Context $context, Value $strPtr): Value
    {
        $structName = $strPtr->typeOf()->getElementType()->getName();
        $map = $context->structFieldMap[$structName];
        $charPtr = $context->builder->structGep($strPtr, $map['value']);
        $endPtrSlot = $context->builder->alloca(
            $context->getTypeFromString('int8*'),
            1,
            'filter_int_parse'
        );
        $nullEnd = $context->getTypeFromString('int8*')->constNull();
        $context->builder->store($nullEnd, $endPtrSlot);
        $i32 = $context->getTypeFromString('int32');
        $parsed = $context->builder->call(
            $context->lookupFunction('strtol'),
            $charPtr,
            $endPtrSlot,
            $i32->constInt(10, false)
        );
        $i64 = $context->getTypeFromString('int64');

        return $context->builder->sExt($parsed, $i64);
    }

    private static function longToBooleanBox(Context $context, Value $longVal): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);
        $isZero = $context->builder->icmp(Builder::INT_EQ, $longVal, $zero);
        $isOne = $context->builder->icmp(Builder::INT_EQ, $longVal, $one);
        $boolVal = $context->builder->select(
            $isZero,
            $context->constantFromBool(false),
            $context->builder->select($isOne, $context->constantFromBool(true), $context->constantFromBool(false))
        );
        JitValueBox::writeBool($context, $slot, $boolVal);

        return $ptr;
    }

    private static function doubleToBooleanBox(Context $context, Value $doubleVal): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $dblTy = $context->getTypeFromString('double');
        $zero = $dblTy->constFloat(0.0, false);
        $one = $dblTy->constFloat(1.0, false);
        $isZero = $context->builder->fcmp(Builder::REAL_OEQ, $doubleVal, $zero);
        $isOne = $context->builder->fcmp(Builder::REAL_OEQ, $doubleVal, $one);

        $id = (string) (++self::$blockSerial);
        $zeroBlock = BasicBlockHelper::append($context, 'fvb_dbl_zero_'.$id);
        $oneCheckBlock = BasicBlockHelper::append($context, 'fvb_dbl_one_chk_'.$id);
        $trueBlock = BasicBlockHelper::append($context, 'fvb_dbl_true_'.$id);
        $failBlock = BasicBlockHelper::append($context, 'fvb_dbl_fail_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'fvb_dbl_done_'.$id);
        $context->builder->branchIf($isZero, $zeroBlock, $oneCheckBlock);

        $context->builder->positionAtEnd($zeroBlock);
        JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));
        $zeroTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($oneCheckBlock);
        $context->builder->branchIf($isOne, $trueBlock, $failBlock);

        $context->builder->positionAtEnd($trueBlock);
        JitValueBox::writeBool($context, $slot, $context->constantFromBool(true));
        $trueTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($failBlock);
        JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));
        $failTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($ptr->typeOf());
        $phi->addIncoming($ptr, $zeroTail);
        $phi->addIncoming($ptr, $trueTail);
        $phi->addIncoming($ptr, $failTail);

        return $phi;
    }

    private static function stringToBooleanBox(Context $context, Value $strPtr): Value
    {
        StringFilterBoolean::ensureLinked($context);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $falseVal = $context->constantFromBool(false);
        $trueVal = $context->constantFromBool(true);
        $i32 = $context->getTypeFromString('int32');
        $tokenResult = $context->builder->call(
            $context->lookupFunction('__compiler_filter_parse_boolean_string'),
            $strPtr
        );
        $isUnknown = $context->builder->icmp(
            Builder::INT_EQ,
            $tokenResult,
            $i32->constInt(-1, true)
        );
        $isTrue = $context->builder->icmp(
            Builder::INT_NE,
            $tokenResult,
            $i32->constInt(0, false)
        );
        JitValueBox::writeBool(
            $context,
            $slot,
            $context->builder->select(
                $isUnknown,
                $falseVal,
                $context->builder->select($isTrue, $trueVal, $falseVal)
            )
        );

        return $ptr;
    }

    private static function stringToFloatBox(Context $context, Value $strPtr): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $falseVal = $context->constantFromBool(false);
        $isValid = self::stringIsFullFloat($context, $strPtr);

        $id = (string) (++self::$blockSerial);
        $okBlock = BasicBlockHelper::append($context, 'fvf_ok_'.$id);
        $failBlock = BasicBlockHelper::append($context, 'fvf_fail_'.$id);
        $mergeBlock = BasicBlockHelper::append($context, 'fvf_merge_'.$id);
        $context->builder->branchIf($isValid, $okBlock, $failBlock);

        $context->builder->positionAtEnd($okBlock);
        $parsed = self::stringToDouble($context, $strPtr);
        $context->builder->call(
            $context->lookupFunction('__value__writeDouble'),
            $ptr,
            $parsed
        );
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($failBlock);
        JitValueBox::writeBool($context, $slot, $falseVal);
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($mergeBlock);

        return $ptr;
    }

    private static function stringIsFullFloat(Context $context, Value $strPtr): Value
    {
        $map = $context->structFieldMap['__string__'];
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $len = $context->builder->load(
            $context->builder->structGep($strPtr, $map['length'])
        );
        $zero = $len->typeOf()->constInt(0, false);
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $len, $zero);
        $charPtr = $context->builder->structGep($strPtr, $map['value']);
        $endPtrSlot = $context->builder->alloca($i8p, 1, 'filter_float_end');
        $context->builder->store($i8p->constNull(), $endPtrSlot);
        $context->builder->call(
            $context->lookupFunction('strtod'),
            $charPtr,
            $endPtrSlot
        );
        $endPtr = $context->builder->load($endPtrSlot);
        $endOffset = $context->builder->sub(
            $context->builder->ptrToInt($endPtr, $i64),
            $context->builder->ptrToInt($charPtr, $i64)
        );
        $consumedAll = $context->builder->icmp(Builder::INT_EQ, $endOffset, $len);
        $numeric = $context->builder->and(
            $context->builder->not($isEmpty),
            $consumedAll
        );

        return $numeric;
    }

    private static function stringToDouble(Context $context, Value $strPtr): Value
    {
        $map = $context->structFieldMap['__string__'];
        $charPtr = $context->builder->structGep($strPtr, $map['value']);
        $endPtr = $context->getTypeFromString('int8**')->constNull();

        return $context->builder->call($context->lookupFunction('strtod'), $charPtr, $endPtr);
    }

    private static function boxValueValidateBoolean(Context $context, JITVariable $arg): Value
    {
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $strVal = $context->builder->call($context->lookupFunction('__value__readString'), $valuePtr);
        $strPtrTy = $context->getTypeFromString('__string__*');
        $hasString = $context->builder->icmp(Builder::INT_NE, $strVal, $strPtrTy->constNull());

        $id = (string) (++self::$blockSerial);
        $stringBlock = BasicBlockHelper::append($context, 'fvb_box_string_'.$id);
        $longBlock = BasicBlockHelper::append($context, 'fvb_box_long_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'fvb_box_done_'.$id);
        $context->builder->branchIf($hasString, $stringBlock, $longBlock);

        $context->builder->positionAtEnd($stringBlock);
        $strVar = new JITVariable($context, JITVariable::TYPE_STRING, JITVariable::KIND_VALUE, $strVal);
        $stringResult = self::validateBoolean($context, $strVar);
        $stringTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($longBlock);
        $longVal = $context->builder->call($context->lookupFunction('__value__readLong'), $valuePtr);
        $longResult = self::longToBooleanBox($context, $longVal);
        $longTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($longResult->typeOf());
        $phi->addIncoming($stringResult, $stringTail);
        $phi->addIncoming($longResult, $longTail);

        return $phi;
    }

    private static function boxValueValidateFloat(Context $context, JITVariable $arg): Value
    {
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $strVal = $context->builder->call($context->lookupFunction('__value__readString'), $valuePtr);
        $strPtrTy = $context->getTypeFromString('__string__*');
        $hasString = $context->builder->icmp(Builder::INT_NE, $strVal, $strPtrTy->constNull());

        $id = (string) (++self::$blockSerial);
        $stringBlock = BasicBlockHelper::append($context, 'fvf_box_string_'.$id);
        $numericBlock = BasicBlockHelper::append($context, 'fvf_box_numeric_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'fvf_box_done_'.$id);
        $context->builder->branchIf($hasString, $stringBlock, $numericBlock);

        $context->builder->positionAtEnd($stringBlock);
        $strVar = new JITVariable($context, JITVariable::TYPE_STRING, JITVariable::KIND_VALUE, $strVal);
        $stringResult = self::validateFloat($context, $strVar);
        $stringTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($numericBlock);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $longVal = $context->builder->call($context->lookupFunction('__value__readLong'), $valuePtr);
        $dblTy = $context->getTypeFromString('double');
        $dblVal = $context->builder->call($context->lookupFunction('__value__readDouble'), $valuePtr);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $isLong = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $context->getTypeFromString('int8')->constInt(JITVariable::TYPE_NATIVE_LONG, false)
        );
        $parsed = $context->builder->select(
            $isLong,
            $context->builder->sitofp($longVal, $dblTy),
            $dblVal
        );
        $context->builder->call(
            $context->lookupFunction('__value__writeDouble'),
            $ptr,
            $parsed
        );
        $numericTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($ptr->typeOf());
        $phi->addIncoming($stringResult, $stringTail);
        $phi->addIncoming($ptr, $numericTail);

        return $phi;
    }
}
