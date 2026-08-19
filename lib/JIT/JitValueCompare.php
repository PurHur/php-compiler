<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\VM\VmValueCompare;
use PHPLLVM\Value;

/**
 * JIT trampoline for boxed {@see __value__} compare lowering (#9972).
 *
 * SSOT: {@see \PHPCompiler\VM\VmValueCompare}
 */
final class JitValueCompare
{
    public static function identicalToNative(
        Context $context,
        Variable $boxed,
        Variable $native
    ): Value {
        return VmValueCompare::identicalToNative($context, $boxed, $native);
    }

    public static function identicalNativeToValue(
        Context $context,
        Variable $native,
        Variable $boxed
    ): Value {
        return VmValueCompare::identicalNativeToValue($context, $native, $boxed);
    }

    public static function notIdenticalToNative(
        Context $context,
        Variable $boxed,
        Variable $native
    ): Value {
        return VmValueCompare::notIdenticalToNative($context, $boxed, $native);
    }

    public static function notIdenticalNativeToValue(
        Context $context,
        Variable $native,
        Variable $boxed
    ): Value {
        return VmValueCompare::notIdenticalNativeToValue($context, $native, $boxed);
    }

    public static function looseEqualValueToNativeLong(
        Context $context,
        Variable $boxed,
        Value $nativeLong
    ): Value {
        return VmValueCompare::looseEqualValueToNativeLong($context, $boxed, $nativeLong);
    }

    public static function looseEqualNativeLongToValue(
        Context $context,
        Value $nativeLong,
        Variable $boxed
    ): Value {
        return VmValueCompare::looseEqualNativeLongToValue($context, $nativeLong, $boxed);
    }

    public static function notLooseEqualValueToNativeLong(
        Context $context,
        Variable $boxed,
        Value $nativeLong
    ): Value {
        return VmValueCompare::notLooseEqualValueToNativeLong($context, $boxed, $nativeLong);
    }

    public static function notLooseEqualNativeLongToValue(
        Context $context,
        Value $nativeLong,
        Variable $boxed
    ): Value {
        return VmValueCompare::notLooseEqualNativeLongToValue($context, $nativeLong, $boxed);
    }

    public static function looseEqualValueToNativeDouble(
        Context $context,
        Variable $boxed,
        Value $nativeDouble
    ): Value {
        return VmValueCompare::looseEqualValueToNativeDouble($context, $boxed, $nativeDouble);
    }

    public static function looseEqualNativeDoubleToValue(
        Context $context,
        Value $nativeDouble,
        Variable $boxed
    ): Value {
        return VmValueCompare::looseEqualNativeDoubleToValue($context, $nativeDouble, $boxed);
    }

    public static function notLooseEqualValueToNativeDouble(
        Context $context,
        Variable $boxed,
        Value $nativeDouble
    ): Value {
        return VmValueCompare::notLooseEqualValueToNativeDouble($context, $boxed, $nativeDouble);
    }

    public static function notLooseEqualNativeDoubleToValue(
        Context $context,
        Value $nativeDouble,
        Variable $boxed
    ): Value {
        return VmValueCompare::notLooseEqualNativeDoubleToValue($context, $nativeDouble, $boxed);
    }

    public static function looseEqualHashtableToBool(
        Context $context,
        Value $hashtable,
        Value $bool
    ): Value {
        return VmValueCompare::looseEqualHashtableToBool($context, $hashtable, $bool);
    }

    public static function looseEqualArrayToBool(
        Context $context,
        Variable $array,
        Value $bool
    ): Value {
        return VmValueCompare::looseEqualArrayToBool($context, $array, $bool);
    }

    public static function looseEqualArrayToNull(
        Context $context,
        Variable $array
    ): Value {
        return VmValueCompare::looseEqualArrayToNull($context, $array);
    }

    public static function valueBoxIsNull(Context $context, Variable $boxed): Value
    {
        return VmValueCompare::valueBoxIsNull($context, $boxed);
    }

    public static function identicalValueBoxToObject(
        Context $context,
        Variable $boxed,
        Variable $object
    ): Value {
        return VmValueCompare::identicalValueBoxToObject($context, $boxed, $object);
    }

    public static function identicalValueToValue(
        Context $context,
        Variable $left,
        Variable $right
    ): Value {
        return VmValueCompare::identicalValueToValue($context, $left, $right);
    }

    public static function looseEqualOperands(Context $context, Variable $left, Variable $right): Value
    {
        return VmValueCompare::looseEqualOperands($context, $left, $right);
    }

    public static function looseEqualValueToValue(
        Context $context,
        Variable $left,
        Variable $right
    ): Value {
        return VmValueCompare::looseEqualValueToValue($context, $left, $right);
    }

    public static function looseEqualObjectPair(Context $context, Value $leftObj, Value $rightObj): Value
    {
        return VmValueCompare::looseEqualObjectPair($context, $leftObj, $rightObj);
    }

    public static function looseEqualNativeArrayPair(
        Context $context,
        Variable $left,
        Variable $right
    ): Value {
        return VmValueCompare::looseEqualNativeArrayPair($context, $left, $right);
    }

    public static function looseEqualHashtablePair(Context $context, Value $leftHt, Value $rightHt): Value
    {
        return VmValueCompare::looseEqualHashtablePair($context, $leftHt, $rightHt);
    }

    public static function spaceshipArrayPair(Context $context, Variable $left, Variable $right): Value
    {
        return VmValueCompare::spaceshipArrayPair($context, $left, $right);
    }

    public static function runtimeValuePtr(Context $context, Variable $var): Value
    {
        return VmValueCompare::runtimeValuePtr($context, $var);
    }

    public static function notIdenticalValueToValue(
        Context $context,
        Variable $left,
        Variable $right
    ): Value {
        return VmValueCompare::notIdenticalValueToValue($context, $left, $right);
    }

    public static function orderedValueToNativeLong(
        Context $context,
        int $opcodeType,
        Variable $boxed,
        Value $nativeLong
    ): Value {
        return VmValueCompare::orderedValueToNativeLong($context, $opcodeType, $boxed, $nativeLong);
    }

    public static function orderedNativeLongToValue(
        Context $context,
        int $opcodeType,
        Value $nativeLong,
        Variable $boxed
    ): Value {
        return VmValueCompare::orderedNativeLongToValue($context, $opcodeType, $nativeLong, $boxed);
    }

    public static function orderedValueToNativeDouble(
        Context $context,
        int $opcodeType,
        Variable $boxed,
        Value $nativeDouble
    ): Value {
        return VmValueCompare::orderedValueToNativeDouble($context, $opcodeType, $boxed, $nativeDouble);
    }

    public static function orderedNativeDoubleToValue(
        Context $context,
        int $opcodeType,
        Value $nativeDouble,
        Variable $boxed
    ): Value {
        return VmValueCompare::orderedNativeDoubleToValue($context, $opcodeType, $nativeDouble, $boxed);
    }

    public static function orderedValueToValue(
        Context $context,
        int $opcodeType,
        Variable $left,
        Variable $right
    ): Value {
        return VmValueCompare::orderedValueToValue($context, $opcodeType, $left, $right);
    }

    public static function boolFromSpaceshipCmp(
        Context $context,
        int $opcodeType,
        Value $cmp
    ): Value {
        return VmValueCompare::boolFromSpaceshipCmp($context, $opcodeType, $cmp);
    }

    public static function looseEqualStringToNativeLong(
        Context $context,
        Value $strPtr,
        Value $nativeLong
    ): Value {
        return VmValueCompare::looseEqualStringToNativeLong($context, $strPtr, $nativeLong);
    }

    public static function looseEqualStringToString(
        Context $context,
        Value $leftStr,
        Value $rightStr
    ): Value {
        return VmValueCompare::looseEqualStringToString($context, $leftStr, $rightStr);
    }

    public static function stringIsNumeric(Context $context, Value $strPtr): Value
    {
        return VmValueCompare::stringIsNumeric($context, $strPtr);
    }

    public static function stringHasNoLeadingIntegerPrefix(Context $context, Value $strPtr): Value
    {
        return VmValueCompare::stringHasNoLeadingIntegerPrefix($context, $strPtr);
    }

    public static function nativeLongIsResource(Context $context, Value $handleLong): Value
    {
        return VmValueCompare::nativeLongIsResource($context, $handleLong);
    }

    public static function nativeLongEqualWithResourceIdentity(
        Context $context,
        Value $leftLong,
        Value $rightLong
    ): Value {
        return VmValueCompare::nativeLongEqualWithResourceIdentity($context, $leftLong, $rightLong);
    }
}
