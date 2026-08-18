<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\ArrayCountRuntime;
use PHPCompiler\JIT\LibcExtern;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\LLVMAbstract\Type as LlvmType;
use PHPLLVM\Value;

/**
 * JIT lowering for Zend scalar (int)/(float) casts (#5714, #5791, #32444, zend_operators.c).
 *
 * Zend php-src: (int)/(float) on enum cases warn and yield legacy 1 / 1.0 (#5714, #7120).
 * intval/floatval JIT must use the same legacy paths (not backing extract).
 */
final class JitZendScalarCast
{
    public static function emitIntCast(Context $context, JITVariable $arg): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $v = $context->helper->loadValue($arg);
        switch ($arg->type) {
            case JITVariable::TYPE_NATIVE_LONG:
                return $v;
            case JITVariable::TYPE_NATIVE_DOUBLE:
                return $context->builder->fpToSi($v, $i64);
            case JITVariable::TYPE_NATIVE_BOOL:
                return $context->builder->zExt($v, $i64);
            case JITVariable::TYPE_STRING:
                return self::stringToInt(
                    $context,
                    JitStringArg::lower($context, $arg, '(int) cast')
                );
            case JITVariable::TYPE_NULL:
                return $i64->constInt(0, false);
            case JITVariable::TYPE_HASHTABLE:
                return self::arrayToLong01($context, $arg);
            case JITVariable::TYPE_VALUE:
                return self::valueBoxToInt($context, $arg);
            default:
                if (ArrayBuiltinHelper::isNativeArray($arg->type)) {
                    return self::arrayToLong01($context, $arg);
                }
                throw new \LogicException('(int) cast unsupported operand type in JIT');
        }
    }

    public static function emitFloatCast(Context $context, JITVariable $arg): Value
    {
        $double = $context->getTypeFromString('double');
        $v = $context->helper->loadValue($arg);
        switch ($arg->type) {
            case JITVariable::TYPE_NATIVE_LONG:
                return $context->builder->siToFp($v, $double);
            case JITVariable::TYPE_NATIVE_DOUBLE:
                return $v;
            case JITVariable::TYPE_NATIVE_BOOL:
                return $context->builder->uiToFp($v, $double);
            case JITVariable::TYPE_STRING:
                $ptr = self::stringDataPtr(
                    $context,
                    JitStringArg::lower($context, $arg, '(float) cast')
                );
                $endPtr = $context->getTypeFromString('int8**')->constNull();
                // strtod(3) via LibcExtern::ensureStrtodDecl after always-on drop (#31997).
                LibcExtern::ensureStrtodDecl($context);

                return $context->builder->call($context->lookupFunction('strtod'), $ptr, $endPtr);
            case JITVariable::TYPE_NULL:
                return $double->constReal(0.0);
            case JITVariable::TYPE_HASHTABLE:
                return $context->builder->siToFp(
                    self::arrayToLong01($context, $arg),
                    $double
                );
            case JITVariable::TYPE_VALUE:
                return self::valueBoxToFloat($context, $arg);
            default:
                if (ArrayBuiltinHelper::isNativeArray($arg->type)) {
                    return $context->builder->siToFp(
                        self::arrayToLong01($context, $arg),
                        $double
                    );
                }
                throw new \LogicException('(float) cast unsupported operand type in JIT');
        }
    }

    /**
     * Zend convert_scalar_to_number IS_ARRAY: zend_hash_num_elements ? 1 : 0 (#32444).
     *
     * php-src: Zend/zend_operators.c — _zval_get_long_func / zval_get_double_func
     * VM SSOT: {@see \PHPCompiler\VM\Variable} toInt/toFloat TYPE_ARRAY arms.
     */
    private static function arrayToLong01(Context $context, JITVariable $arg): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $count = ArrayCountRuntime::numElements($context, $arg);

        return $context->builder->select(
            $context->builder->icmp(Builder::INT_NE, $count, $i64->constInt(0, false)),
            $i64->constInt(1, false),
            $i64->constInt(0, false)
        );
    }

    private static function valueBoxToInt(Context $context, JITVariable $arg): Value
    {
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);

        $nullBlock = BasicBlockHelper::append($context, 'int_cast_value_null');
        $longBlock = BasicBlockHelper::append($context, 'int_cast_value_long');
        $boolBlock = BasicBlockHelper::append($context, 'int_cast_value_bool');
        $doubleBlock = BasicBlockHelper::append($context, 'int_cast_value_double');
        $stringBlock = BasicBlockHelper::append($context, 'int_cast_value_string');
        $doneBlock = BasicBlockHelper::append($context, 'int_cast_value_done');

        $afterNull = BasicBlockHelper::append($context, 'int_cast_value_after_null');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(JITVariable::TYPE_NULL, false)),
            $nullBlock,
            $afterNull
        );
        $context->builder->positionAtEnd($nullBlock);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($afterNull);
        $afterLong = BasicBlockHelper::append($context, 'int_cast_value_after_long');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(JITVariable::TYPE_NATIVE_LONG, false)),
            $longBlock,
            $afterLong
        );

        $context->builder->positionAtEnd($longBlock);
        $longVal = $context->builder->call($context->lookupFunction('__value__readLong'), $valuePtr);
        $longEndBlock = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($afterLong);
        $afterBool = BasicBlockHelper::append($context, 'int_cast_value_after_bool');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(JITVariable::TYPE_NATIVE_BOOL, false)),
            $boolBlock,
            $afterBool
        );

        $context->builder->positionAtEnd($boolBlock);
        $boolInt = self::readBoolByteFromValueBox($context, $valuePtr, $i64);
        $boolEndBlock = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($afterBool);
        $afterDouble = BasicBlockHelper::append($context, 'int_cast_value_after_double');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(JITVariable::TYPE_NATIVE_DOUBLE, false)),
            $doubleBlock,
            $afterDouble
        );

        $context->builder->positionAtEnd($doubleBlock);
        $doubleVal = $context->builder->call($context->lookupFunction('__value__readDouble'), $valuePtr);
        $doubleInt = $context->builder->fpToSi($doubleVal, $i64);
        $doubleEndBlock = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($afterDouble);
        $objectEnumBlock = BasicBlockHelper::append($context, 'int_cast_value_object_enum');
        $afterEnumDispatch = BasicBlockHelper::append($context, 'int_cast_value_after_enum_dispatch');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(JITVariable::TYPE_OBJECT, false)),
            $objectEnumBlock,
            $afterEnumDispatch
        );
        $enumLong = null;
        $enumEndBlock = null;
        $context->builder->positionAtEnd($objectEnumBlock);
        $objPtr = $context->builder->call($context->lookupFunction('__value__readObject'), $valuePtr);
        $enumLong = JitScalarEnumCoerce::tryEmitObjectEnumCaseLegacyCastToLong(
            $context,
            $objPtr,
            'int_cast',
            $afterEnumDispatch
        );
        if (null !== $enumLong) {
            $enumEndBlock = $context->builder->getInsertBlock();
            $context->builder->branch($doneBlock);
        }
        $context->builder->positionAtEnd($afterEnumDispatch);
        $plainObjectBlock = BasicBlockHelper::append($context, 'int_cast_value_plain_object');
        $afterPlainObjectCheck = BasicBlockHelper::append($context, 'int_cast_value_after_plain_object');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(JITVariable::TYPE_OBJECT, false)),
            $plainObjectBlock,
            $afterPlainObjectCheck
        );

        $plainObjectInt = null;
        $plainObjectEndBlock = null;
        $context->builder->positionAtEnd($plainObjectBlock);
        $plainObjPtr = $context->builder->call($context->lookupFunction('__value__readObject'), $valuePtr);
        $plainObjectInt = JitScalarTypeCoerce::emitPlainObjectToScalar($context, $plainObjPtr, 'int');
        $plainObjectEndBlock = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($afterPlainObjectCheck);
        $htBlock = BasicBlockHelper::append($context, 'int_cast_value_ht');
        $afterHt = BasicBlockHelper::append($context, 'int_cast_value_after_ht');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(JITVariable::TYPE_HASHTABLE, false)),
            $htBlock,
            $afterHt
        );

        $context->builder->positionAtEnd($htBlock);
        $htPtr = $context->builder->call($context->lookupFunction('__value__readHashtable'), $valuePtr);
        $htCount = ArrayBuiltinHelper::getNumElements($context, $htPtr);
        $htInt = $context->builder->select(
            $context->builder->icmp(Builder::INT_NE, $htCount, $zero),
            $i64->constInt(1, false),
            $zero
        );
        $htEndBlock = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($afterHt);
        $fallbackBlock = BasicBlockHelper::append($context, 'int_cast_value_fallback');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(JITVariable::TYPE_STRING, false)),
            $stringBlock,
            $fallbackBlock
        );

        $context->builder->positionAtEnd($stringBlock);
        $stringVal = $context->builder->call($context->lookupFunction('__value__readString'), $valuePtr);
        $stringInt = self::stringToInt($context, $stringVal);
        $stringEndBlock = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($fallbackBlock);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($i64, 'int_cast_value_phi');
        $phi->addIncoming($zero, $nullBlock);
        $phi->addIncoming($longVal, $longEndBlock);
        $phi->addIncoming($boolInt, $boolEndBlock);
        $phi->addIncoming($doubleInt, $doubleEndBlock);
        $phi->addIncoming($stringInt, $stringEndBlock);
        $phi->addIncoming($htInt, $htEndBlock);
        if (null !== $enumLong && null !== $enumEndBlock) {
            $phi->addIncoming($enumLong, $enumEndBlock);
        }
        if (null !== $plainObjectInt && null !== $plainObjectEndBlock) {
            $phi->addIncoming($plainObjectInt, $plainObjectEndBlock);
        }
        $phi->addIncoming($zero, $fallbackBlock);

        return $phi;
    }

    private static function valueBoxToFloat(Context $context, JITVariable $arg): Value
    {
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $double = $context->getTypeFromString('double');
        $zero = $double->constReal(0.0);

        $nullBlock = BasicBlockHelper::append($context, 'float_cast_value_null');
        $longBlock = BasicBlockHelper::append($context, 'float_cast_value_long');
        $boolBlock = BasicBlockHelper::append($context, 'float_cast_value_bool');
        $nativeDoubleBlock = BasicBlockHelper::append($context, 'float_cast_value_double');
        $stringBlock = BasicBlockHelper::append($context, 'float_cast_value_string');
        $doneBlock = BasicBlockHelper::append($context, 'float_cast_value_done');

        $afterNull = BasicBlockHelper::append($context, 'float_cast_value_after_null');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(JITVariable::TYPE_NULL, false)),
            $nullBlock,
            $afterNull
        );
        $context->builder->positionAtEnd($nullBlock);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($afterNull);
        $afterLong = BasicBlockHelper::append($context, 'float_cast_value_after_long');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(JITVariable::TYPE_NATIVE_LONG, false)),
            $longBlock,
            $afterLong
        );

        $context->builder->positionAtEnd($longBlock);
        $longVal = $context->builder->call($context->lookupFunction('__value__readLong'), $valuePtr);
        $longFloat = $context->builder->siToFp($longVal, $double);
        $longEndBlock = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($afterLong);
        $afterBool = BasicBlockHelper::append($context, 'float_cast_value_after_bool');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(JITVariable::TYPE_NATIVE_BOOL, false)),
            $boolBlock,
            $afterBool
        );

        $context->builder->positionAtEnd($boolBlock);
        $boolByte = self::readBoolByteFromValueBox($context, $valuePtr, $context->getTypeFromString('int8'));
        $boolFloat = $context->builder->uiToFp($boolByte, $double);
        $boolEndBlock = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($afterBool);
        $afterDouble = BasicBlockHelper::append($context, 'float_cast_value_after_double');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(JITVariable::TYPE_NATIVE_DOUBLE, false)),
            $nativeDoubleBlock,
            $afterDouble
        );

        $context->builder->positionAtEnd($nativeDoubleBlock);
        $nativeDoubleVal = $context->builder->call($context->lookupFunction('__value__readDouble'), $valuePtr);
        $nativeDoubleEndBlock = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($afterDouble);
        $objectEnumBlock = BasicBlockHelper::append($context, 'float_cast_value_object_enum');
        $afterEnumDispatch = BasicBlockHelper::append($context, 'float_cast_value_after_enum_dispatch');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(JITVariable::TYPE_OBJECT, false)),
            $objectEnumBlock,
            $afterEnumDispatch
        );
        $enumDouble = null;
        $enumEndBlock = null;
        $context->builder->positionAtEnd($objectEnumBlock);
        $objPtr = $context->builder->call($context->lookupFunction('__value__readObject'), $valuePtr);
        $enumDouble = JitScalarEnumCoerce::tryEmitObjectEnumCaseLegacyCastToDouble(
            $context,
            $objPtr,
            'float_cast',
            $afterEnumDispatch
        );
        if (null !== $enumDouble) {
            $enumEndBlock = $context->builder->getInsertBlock();
            $context->builder->branch($doneBlock);
        }
        $context->builder->positionAtEnd($afterEnumDispatch);
        $plainObjectBlock = BasicBlockHelper::append($context, 'float_cast_value_plain_object');
        $afterPlainObjectCheck = BasicBlockHelper::append($context, 'float_cast_value_after_plain_object');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(JITVariable::TYPE_OBJECT, false)),
            $plainObjectBlock,
            $afterPlainObjectCheck
        );

        $plainObjectDouble = null;
        $plainObjectEndBlock = null;
        $context->builder->positionAtEnd($plainObjectBlock);
        $plainObjPtr = $context->builder->call($context->lookupFunction('__value__readObject'), $valuePtr);
        $plainObjectDouble = JitScalarTypeCoerce::emitPlainObjectToScalar($context, $plainObjPtr, 'float');
        $plainObjectEndBlock = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($afterPlainObjectCheck);
        $htBlock = BasicBlockHelper::append($context, 'float_cast_value_ht');
        $afterHt = BasicBlockHelper::append($context, 'float_cast_value_after_ht');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(JITVariable::TYPE_HASHTABLE, false)),
            $htBlock,
            $afterHt
        );

        $context->builder->positionAtEnd($htBlock);
        $htPtr = $context->builder->call($context->lookupFunction('__value__readHashtable'), $valuePtr);
        $htCount = ArrayBuiltinHelper::getNumElements($context, $htPtr);
        $i64 = $context->getTypeFromString('int64');
        $htInt = $context->builder->select(
            $context->builder->icmp(Builder::INT_NE, $htCount, $i64->constInt(0, false)),
            $i64->constInt(1, false),
            $i64->constInt(0, false)
        );
        $htFloat = $context->builder->siToFp($htInt, $double);
        $htEndBlock = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($afterHt);
        $fallbackBlock = BasicBlockHelper::append($context, 'float_cast_value_fallback');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(JITVariable::TYPE_STRING, false)),
            $stringBlock,
            $fallbackBlock
        );

        $context->builder->positionAtEnd($stringBlock);
        $stringVal = $context->builder->call($context->lookupFunction('__value__readString'), $valuePtr);
        $ptr = self::stringDataPtr($context, $stringVal);
        $endPtr = $context->getTypeFromString('int8**')->constNull();
        // strtod(3) via LibcExtern::ensureStrtodDecl after always-on drop (#31997).
        LibcExtern::ensureStrtodDecl($context);
        $stringFloat = $context->builder->call($context->lookupFunction('strtod'), $ptr, $endPtr);
        $stringEndBlock = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($fallbackBlock);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($double, 'float_cast_value_phi');
        $phi->addIncoming($zero, $nullBlock);
        $phi->addIncoming($longFloat, $longEndBlock);
        $phi->addIncoming($boolFloat, $boolEndBlock);
        $phi->addIncoming($nativeDoubleVal, $nativeDoubleEndBlock);
        $phi->addIncoming($stringFloat, $stringEndBlock);
        $phi->addIncoming($htFloat, $htEndBlock);
        if (null !== $enumDouble && null !== $enumEndBlock) {
            $phi->addIncoming($enumDouble, $enumEndBlock);
        }
        if (null !== $plainObjectDouble && null !== $plainObjectEndBlock) {
            $phi->addIncoming($plainObjectDouble, $plainObjectEndBlock);
        }
        $phi->addIncoming($zero, $fallbackBlock);

        return $phi;
    }

    /**
     * Read a boxed bool from {@see __value__::value} (writeBool stores int8 at offset 0).
     * {@see __value__readLong} has no TYPE_NATIVE_BOOL arm (#1056 object_identity_compare).
     */
    public static function readBoolByteFromValueBox(Context $context, Value $valuePtr, LlvmType $targetTy): Value
    {
        $map = $context->structFieldMap['__value__'];
        $valueField = $context->builder->structGep($valuePtr, $map['value']);
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $firstByte = $context->builder->inBoundsGEP(
            $valueField,
            $i32->constInt(0, false),
            $i64->constInt(0, false)
        );
        $boolByte = $context->builder->load($firstByte);

        return $context->builder->zExt($boolByte, $targetTy);
    }

    /**
     * Zend string → long for (int) cast / SXE numeric cast (#5714, #22715).
     */
    public static function castStringToInt(Context $context, Value $strPtr): Value
    {
        return self::stringToInt($context, $strPtr);
    }

    /**
     * Zend string → double for (float) cast / SXE numeric cast (#5714, #22715).
     */
    public static function castStringToFloat(Context $context, Value $strPtr): Value
    {
        $ptr = self::stringDataPtr($context, $strPtr);
        $endPtr = $context->getTypeFromString('int8**')->constNull();
        // strtod(3) via LibcExtern::ensureStrtodDecl after always-on drop (#31997).
        LibcExtern::ensureStrtodDecl($context);

        return $context->builder->call($context->lookupFunction('strtod'), $ptr, $endPtr);
    }

    private static function stringToInt(Context $context, Value $strPtr): Value
    {
        $ptr = self::stringDataPtr($context, $strPtr);
        $endPtr = $context->getTypeFromString('int8**')->constNull();
        $i64 = $context->getTypeFromString('int64');
        $base = $context->builder->trunc($i64->constInt(10, false), $context->getTypeFromString('int32'));
        // strtol(3) via LibcExtern::ensureStrtolDecl after always-on drop (#31988).
        LibcExtern::ensureStrtolDecl($context);
        $raw = $context->builder->call($context->lookupFunction('strtol'), $ptr, $endPtr, $base);

        return $context->builder->trunc($raw, $i64);
    }

    private static function stringDataPtr(Context $context, Value $strPtr): Value
    {
        $off = $context->structFieldIndex($strPtr, 'value');

        return $context->builder->structGep($strPtr, $off);
    }
}
