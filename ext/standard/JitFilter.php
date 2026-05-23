<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM JIT/AOT helpers for filter_var() / filter_input() (issue #104). */
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
        if (JITVariable::TYPE_STRING === $arg->type || JITVariable::TYPE_NATIVE_LONG === $arg->type) {
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

    public static function validateInt(Context $context, JITVariable $value): Value
    {
        if (JITVariable::TYPE_VALUE === $value->type) {
            return self::boxValueValidateInt($context, $value);
        }

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $falseVal = $context->constantFromBool(false);

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

    public static function validateEmail(Context $context, JITVariable $value): Value
    {
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

    private static function stringIsFullInteger(Context $context, Value $strPtr): Value
    {
        $structName = $strPtr->typeOf()->getElementType()->getName();
        $map = $context->structFieldMap[$structName];
        $len = $context->builder->load(
            $context->builder->structGep($strPtr, $map['length'])
        );
        $zero = $len->typeOf()->constInt(0, false);
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

        return $context->builder->select($isEmpty, $context->constantFromBool(false), $numeric);
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
}
