<?php

declare(strict_types=1);

/**
 * Strict equality between boxed {@see __value__} and native JIT operands.
 */

namespace PHPCompiler\JIT;

require_once __DIR__.'/../OpCodeNames.php';

use PHPCompiler\OpCode;
use function PHPCompiler\opcode_type_name;
use PHPLLVM\Builder;
use PHPLLVM\Value;

final class JitValueCompare
{
    public static function identicalToNative(
        Context $context,
        Variable $boxed,
        Variable $native
    ): Value {
        if (!JitValueBox::isValueOperand($boxed)) {
            throw new \LogicException('Expected boxed __value__ operand');
        }

        $valuePtr = JitValueBox::valuePtrFromVariable($context, $boxed);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $falseVal = $context->getTypeFromString('int1')->constInt(0, false);

        switch ($native->type) {
            case Variable::TYPE_NATIVE_BOOL:
                $nullTag = $i8->constInt(Variable::TYPE_NULL, false);
                $isNull = $context->builder->icmp(Builder::INT_EQ, $typeByte, $nullTag);
                $boolTag = $i8->constInt(Variable::TYPE_NATIVE_BOOL, false);
                $isBool = $context->builder->icmp(Builder::INT_EQ, $typeByte, $boolTag);
                $stored = $context->builder->call(
                    $context->lookupFunction('__value__readLong'),
                    $valuePtr
                );
                $nativeBool = $context->helper->loadValue($native);
                $matches = $context->builder->icmp(
                    Builder::INT_EQ,
                    $context->builder->zExt($stored, $nativeBool->typeOf()),
                    $nativeBool
                );

                return $context->builder->select(
                    $isNull,
                    $falseVal,
                    $context->builder->select($isBool, $matches, $falseVal)
                );
            case Variable::TYPE_NATIVE_LONG:
                $expectedType = $i8->constInt(Variable::TYPE_NATIVE_LONG, false);
                $sameType = $context->builder->icmp(Builder::INT_EQ, $typeByte, $expectedType);
                $stored = $context->builder->call(
                    $context->lookupFunction('__value__readLong'),
                    $valuePtr
                );
                $nativeLong = $context->helper->loadValue($native);
                $matches = $context->builder->icmp(Builder::INT_EQ, $stored, $nativeLong);

                return $context->builder->select($sameType, $matches, $falseVal);
            case Variable::TYPE_NULL:
                $nullTag = $i8->constInt(Variable::TYPE_NULL, false);

                return $context->builder->icmp(Builder::INT_EQ, $typeByte, $nullTag);
            default:
                return $falseVal;
        }
    }

    public static function identicalNativeToValue(
        Context $context,
        Variable $native,
        Variable $boxed
    ): Value {
        return self::identicalToNative($context, $boxed, $native);
    }

    public static function notIdenticalToNative(
        Context $context,
        Variable $boxed,
        Variable $native
    ): Value {
        $same = self::identicalToNative($context, $boxed, $native);
        $i1 = $context->getTypeFromString('int1');

        return $context->builder->icmp(Builder::INT_EQ, $same, $i1->constInt(0, false));
    }

    public static function notIdenticalNativeToValue(
        Context $context,
        Variable $native,
        Variable $boxed
    ): Value {
        return self::notIdenticalToNative($context, $boxed, $native);
    }

    /**
     * Loose == between boxed __value__ and native long (PHP scalar coercion rules).
     */
    public static function looseEqualValueToNativeLong(
        Context $context,
        Variable $boxed,
        Value $nativeLong
    ): Value {
        if (!JitValueBox::isValueOperand($boxed)) {
            throw new \LogicException('Expected boxed __value__ operand');
        }

        $valuePtr = JitValueBox::valuePtrFromVariable($context, $boxed);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');
        $falseVal = $context->getTypeFromString('int1')->constInt(0, false);
        $__native = $context->builder->intCast($nativeLong, $i64);

        $longTag = $i8->constInt(Variable::TYPE_NATIVE_LONG, false);
        $isLong = $context->builder->icmp(Builder::INT_EQ, $typeByte, $longTag);
        $stored = $context->builder->call(
            $context->lookupFunction('__value__readLong'),
            $valuePtr
        );
        $longMatches = $context->builder->icmp(Builder::INT_EQ, $stored, $__native);

        $boolTag = $i8->constInt(Variable::TYPE_NATIVE_BOOL, false);
        $isBool = $context->builder->icmp(Builder::INT_EQ, $typeByte, $boolTag);
        $boolMatches = $context->builder->icmp(
            Builder::INT_EQ,
            $context->builder->zExt($stored, $i64),
            $__native
        );

        $nullTag = $i8->constInt(Variable::TYPE_NULL, false);
        $isNull = $context->builder->icmp(Builder::INT_EQ, $typeByte, $nullTag);
        $nullMatches = $context->builder->icmp(Builder::INT_EQ, $__native, $i64->constInt(0, false));

        return $context->builder->select(
            $isLong,
            $longMatches,
            $context->builder->select(
                $isBool,
                $boolMatches,
                $context->builder->select($isNull, $nullMatches, $falseVal)
            )
        );
    }

    public static function looseEqualNativeLongToValue(
        Context $context,
        Value $nativeLong,
        Variable $boxed
    ): Value {
        return self::looseEqualValueToNativeLong($context, $boxed, $nativeLong);
    }

    public static function notLooseEqualValueToNativeLong(
        Context $context,
        Variable $boxed,
        Value $nativeLong
    ): Value {
        $same = self::looseEqualValueToNativeLong($context, $boxed, $nativeLong);
        $i1 = $context->getTypeFromString('int1');

        return $context->builder->icmp(Builder::INT_EQ, $same, $i1->constInt(0, false));
    }

    public static function notLooseEqualNativeLongToValue(
        Context $context,
        Value $nativeLong,
        Variable $boxed
    ): Value {
        return self::notLooseEqualValueToNativeLong($context, $boxed, $nativeLong);
    }

    /**
     * Loose == between {@see __hashtable__*} and native bool (Zend: empty array == false).
     */
    public static function looseEqualHashtableToBool(
        Context $context,
        Value $hashtable,
        Value $bool
    ): Value {
        $num = $context->builder->call(
            $context->lookupFunction('__hashtable__getNumElements'),
            $hashtable
        );
        $sizeT = $context->getTypeFromString('size_t');
        $isEmpty = $context->builder->icmp(
            Builder::INT_EQ,
            $num,
            $sizeT->constInt(0, false)
        );
        $i1 = $context->getTypeFromString('int1');
        $falseVal = $i1->constInt(0, false);
        $trueVal = $i1->constInt(1, false);
        $notBool = $context->builder->select($bool, $falseVal, $trueVal);

        return $context->builder->select($isEmpty, $notBool, $bool);
    }

    /**
     * Loose == between a JIT array operand (native or hashtable) and native bool.
     */
    public static function looseEqualArrayToBool(
        Context $context,
        Variable $array,
        Value $bool
    ): Value {
        if (ArrayBuiltinHelper::isNativeArray($array->type)) {
            $i1 = $context->getTypeFromString('int1');
            $falseVal = $i1->constInt(0, false);
            $trueVal = $i1->constInt(1, false);
            if (0 === $array->nextFreeElement) {
                return $context->builder->select($bool, $falseVal, $trueVal);
            }

            return $bool;
        }
        $ht = ArrayBuiltinHelper::loadHashTable($context, $array);

        return self::looseEqualHashtableToBool($context, $ht, $bool);
    }

    /**
     * Loose == between a JIT array operand and null (Zend: only empty array == null).
     */
    public static function looseEqualArrayToNull(
        Context $context,
        Variable $array
    ): Value {
        if (ArrayBuiltinHelper::isNativeArray($array->type)) {
            return $context->constantFromBool(0 === $array->nextFreeElement);
        }
        $ht = ArrayBuiltinHelper::loadHashTable($context, $array);
        $num = $context->builder->call(
            $context->lookupFunction('__hashtable__getNumElements'),
            $ht
        );
        $sizeT = $context->getTypeFromString('size_t');
        $i1 = $context->getTypeFromString('int1');

        return $context->builder->icmp(
            Builder::INT_EQ,
            $num,
            $sizeT->constInt(0, false)
        );
    }


    /** True when a boxed operand is unset: null {@see __value__*} or a null-tagged box (#1086). */
    public static function valueBoxIsNull(Context $context, Variable $boxed): Value
    {
        if (!JitValueBox::isValueOperand($boxed)) {
            return $context->getTypeFromString('int1')->constInt(0, false);
        }
        $ptr = JitValueBox::valuePtrFromVariable($context, $boxed);
        $ptrTy = $ptr->typeOf();
        $i1 = $context->getTypeFromString('int1');
        $trueVal = $i1->constInt(1, false);
        $entry = $context->builder->getInsertBlock();
        $isNullPtr = $context->builder->icmp(
            Builder::INT_EQ,
            $ptr,
            $ptrTy->constNull()
        );
        $checkTag = BasicBlockHelper::append($context, 'value_box_null_check_tag');
        $done = BasicBlockHelper::append($context, 'value_box_null_done');
        $context->builder->branchIf($isNullPtr, $done, $checkTag);

        $context->builder->positionAtEnd($checkTag);
        $tag = $context->builder->load(
            $context->builder->structGep($ptr, $context->structFieldMap['__value__']['type'])
        );
        $nullTag = $context->getTypeFromString('int8')->constInt(Variable::TYPE_NULL, false);
        $isNullTag = $context->builder->icmp(Builder::INT_EQ, $tag, $nullTag);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
        $result = $context->builder->phi($i1);
        $result->addIncoming($trueVal, $entry);
        $result->addIncoming($isNullTag, $checkTag);

        return $result;
    }

    /** Strict identity between a boxed CFG handle and a native {@see __object__*} (#1056). */
    public static function identicalValueBoxToObject(
        Context $context,
        Variable $boxed,
        Variable $object
    ): Value {
        $falseVal = $context->getTypeFromString('int1')->constInt(0, false);
        if (!JitValueBox::isValueOperand($boxed) || Variable::TYPE_OBJECT !== $object->type) {
            return $falseVal;
        }
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $boxed);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $isObject = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_OBJECT, false)
        );
        $boxedObj = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $valuePtr
        );
        $nativeObj = $context->helper->loadValue($object);
        $voidp = $context->getTypeFromString('void')->pointerType(0);
        $sizeT = $context->getTypeFromString('size_t');
        $leftPtr = $context->builder->ptrToInt(
            $context->builder->pointerCast($boxedObj, $voidp),
            $sizeT
        );
        $rightPtr = $context->builder->ptrToInt(
            $context->builder->pointerCast($nativeObj, $voidp),
            $sizeT
        );
        $same = $context->builder->icmp(Builder::INT_EQ, $leftPtr, $rightPtr);

        return $context->builder->and($isObject, $same);
    }

    public static function identicalValueToValue(
        Context $context,
        Variable $left,
        Variable $right
    ): Value {
        if (!JitValueBox::isValueOperand($left) || !JitValueBox::isValueOperand($right)) {
            throw new \LogicException('Expected two boxed __value__ operands');
        }

        $leftPtr = JitValueBox::normalizeValuePtr(
            $context,
            JitValueBox::valuePtrFromVariable($context, $left)
        );
        $rightPtr = JitValueBox::normalizeValuePtr(
            $context,
            JitValueBox::valuePtrFromVariable($context, $right)
        );
        $map = $context->structFieldMap['__value__'];
        $leftType = $context->builder->load($context->builder->structGep($leftPtr, $map['type']));
        $rightType = $context->builder->load($context->builder->structGep($rightPtr, $map['type']));
        $i8 = $context->getTypeFromString('int8');
        $falseVal = $context->getTypeFromString('int1')->constInt(0, false);
        $sameType = $context->builder->icmp(Builder::INT_EQ, $leftType, $rightType);

        $nullTag = $i8->constInt(Variable::TYPE_NULL, false);
        $bothNull = $context->builder->and(
            $context->builder->icmp(Builder::INT_EQ, $leftType, $nullTag),
            $context->builder->icmp(Builder::INT_EQ, $rightType, $nullTag)
        );

        $entry = $context->builder->getInsertBlock();
        $i1 = $context->getTypeFromString('int1');
        $trueVal = $i1->constInt(1, false);
        $mergeBlock = BasicBlockHelper::append($context, 'identical_value_merge');
        $typedBlock = BasicBlockHelper::append($context, 'identical_value_typed');

        $context->builder->branchIf($bothNull, $mergeBlock, $typedBlock);

        $context->builder->positionAtEnd($typedBlock);
        [$typedMatch, $typedDone] = self::identicalValueToValueTyped(
            $context,
            $leftPtr,
            $rightPtr,
            $leftType,
            $rightType,
            $mergeBlock
        );

        $context->builder->positionAtEnd($mergeBlock);
        $matchPhi = $context->builder->phi($i1);
        $matchPhi->addIncoming($trueVal, $entry);
        $matchPhi->addIncoming($typedMatch, $typedDone);

        return $context->builder->and($sameType, $matchPhi);
    }

    /**
     * Compare two boxed values of the same non-null type tag (caller skips dual-null).
     */
    private static function identicalValueToValueTyped(
        Context $context,
        Value $leftPtr,
        Value $rightPtr,
        Value $leftType,
        Value $rightType,
        \PHPLLVM\BasicBlock $exitBlock
    ): array {
        $i8 = $context->getTypeFromString('int8');
        $falseVal = $context->getTypeFromString('int1')->constInt(0, false);

        $stringTag = $i8->constInt(Variable::TYPE_STRING, false);
        $bothString = $context->builder->and(
            $context->builder->icmp(Builder::INT_EQ, $leftType, $stringTag),
            $context->builder->icmp(Builder::INT_EQ, $rightType, $stringTag)
        );

        $longTag = $i8->constInt(Variable::TYPE_NATIVE_LONG, false);
        $bothLong = $context->builder->and(
            $context->builder->icmp(Builder::INT_EQ, $leftType, $longTag),
            $context->builder->icmp(Builder::INT_EQ, $rightType, $longTag)
        );

        $objectTag = $i8->constInt(Variable::TYPE_OBJECT, false);
        $bothObject = $context->builder->and(
            $context->builder->icmp(Builder::INT_EQ, $leftType, $objectTag),
            $context->builder->icmp(Builder::INT_EQ, $rightType, $objectTag)
        );

        $entry = $context->builder->getInsertBlock();
        $i1 = $context->getTypeFromString('int1');
        $stringBlock = BasicBlockHelper::append($context, 'identical_value_string');
        $longCheckBlock = BasicBlockHelper::append($context, 'identical_value_long_check');
        $longBlock = BasicBlockHelper::append($context, 'identical_value_long');
        $objectCheckBlock = BasicBlockHelper::append($context, 'identical_value_object_check');
        $objectBlock = BasicBlockHelper::append($context, 'identical_value_object');
        $typedFalseBlock = BasicBlockHelper::append($context, 'identical_value_typed_false');
        $doneBlock = BasicBlockHelper::append($context, 'identical_value_typed_done');

        $context->builder->branchIf($bothString, $stringBlock, $longCheckBlock);

        $context->builder->positionAtEnd($stringBlock);
        $leftStr = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $leftPtr
        );
        $rightStr = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $rightPtr
        );
        $stringMap = $context->structFieldMap['__string__'];
        $cmp = $context->builder->call(
            $context->lookupFunction('strcmp'),
            $context->builder->structGep($leftStr, $stringMap['value']),
            $context->builder->structGep($rightStr, $stringMap['value'])
        );
        $stringsMatch = $context->builder->icmp(Builder::INT_EQ, $cmp, $cmp->typeOf()->constInt(0, false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($longCheckBlock);
        $context->builder->branchIf($bothLong, $longBlock, $objectCheckBlock);

        $context->builder->positionAtEnd($longBlock);
        $leftLong = $context->builder->call($context->lookupFunction('__value__readLong'), $leftPtr);
        $rightLong = $context->builder->call($context->lookupFunction('__value__readLong'), $rightPtr);
        $longMatch = $context->builder->icmp(Builder::INT_EQ, $leftLong, $rightLong);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($objectCheckBlock);
        $context->builder->branchIf($bothObject, $objectBlock, $typedFalseBlock);

        $context->builder->positionAtEnd($objectBlock);
        $leftObj = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $leftPtr
        );
        $rightObj = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $rightPtr
        );
        $voidp = $context->getTypeFromString('void')->pointerType(0);
        $sizeT = $context->getTypeFromString('size_t');
        $leftHandle = $context->builder->ptrToInt(
            $context->builder->pointerCast($leftObj, $voidp),
            $sizeT
        );
        $rightHandle = $context->builder->ptrToInt(
            $context->builder->pointerCast($rightObj, $voidp),
            $sizeT
        );
        $objectMatch = $context->builder->icmp(Builder::INT_EQ, $leftHandle, $rightHandle);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($typedFalseBlock);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($i1);
        $phi->addIncoming($stringsMatch, $stringBlock);
        $phi->addIncoming($longMatch, $longBlock);
        $phi->addIncoming($objectMatch, $objectBlock);
        $phi->addIncoming($falseVal, $typedFalseBlock);
        $context->builder->branch($exitBlock);

        return [$phi, $doneBlock];
    }

    public static function notIdenticalValueToValue(
        Context $context,
        Variable $left,
        Variable $right
    ): Value {
        $same = self::identicalValueToValue($context, $left, $right);
        $i1 = $context->getTypeFromString('int1');

        return $context->builder->icmp(Builder::INT_EQ, $same, $i1->constInt(0, false));
    }

    public static function orderedValueToNativeLong(
        Context $context,
        int $opcodeType,
        Variable $boxed,
        Value $nativeLong
    ): Value {
        if (!JitValueBox::isValueOperand($boxed)) {
            throw new \LogicException('Expected boxed __value__ operand');
        }
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $boxed);
        $leftLong = $context->builder->call(
            $context->lookupFunction('__value__readLong'),
            $valuePtr
        );
        $__right = $context->builder->intCast($nativeLong, $leftLong->typeOf());

        return self::orderedLongCompare($context, $opcodeType, $leftLong, $__right);
    }

    public static function orderedNativeLongToValue(
        Context $context,
        int $opcodeType,
        Value $nativeLong,
        Variable $boxed
    ): Value {
        if (!JitValueBox::isValueOperand($boxed)) {
            throw new \LogicException('Expected boxed __value__ operand');
        }
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $boxed);
        $rightLong = $context->builder->call(
            $context->lookupFunction('__value__readLong'),
            $valuePtr
        );
        $__left = $context->builder->intCast($nativeLong, $rightLong->typeOf());

        return self::orderedLongCompare($context, $opcodeType, $__left, $rightLong);
    }

    public static function orderedValueToValue(
        Context $context,
        int $opcodeType,
        Variable $left,
        Variable $right
    ): Value {
        if (Variable::TYPE_VALUE !== $left->type || Variable::TYPE_VALUE !== $right->type) {
            throw new \LogicException('Expected two boxed __value__ operands');
        }
        $leftPtr = Variable::KIND_VARIABLE === $left->kind
            ? $left->value
            : $context->helper->loadValue($left);
        $rightPtr = Variable::KIND_VARIABLE === $right->kind
            ? $right->value
            : $context->helper->loadValue($right);
        $map = $context->structFieldMap['__value__'];
        $i8 = $context->getTypeFromString('int8');
        $falseVal = $context->getTypeFromString('int1')->constInt(0, false);
        $leftType = $context->builder->load($context->builder->structGep($leftPtr, $map['type']));
        $rightType = $context->builder->load($context->builder->structGep($rightPtr, $map['type']));
        $longTag = $i8->constInt(Variable::TYPE_NATIVE_LONG, false);
        $bothLong = $context->builder->and(
            $context->builder->icmp(Builder::INT_EQ, $leftType, $longTag),
            $context->builder->icmp(Builder::INT_EQ, $rightType, $longTag)
        );
        $leftLong = $context->builder->call(
            $context->lookupFunction('__value__readLong'),
            $leftPtr
        );
        $rightLong = $context->builder->call(
            $context->lookupFunction('__value__readLong'),
            $rightPtr
        );
        $ordered = self::orderedLongCompare($context, $opcodeType, $leftLong, $rightLong);

        return $context->builder->select($bothLong, $ordered, $falseVal);
    }

    private static function orderedLongCompare(
        Context $context,
        int $opcodeType,
        Value $leftLong,
        Value $rightLong
    ): Value {
        switch ($opcodeType) {
            case OpCode::TYPE_GREATER:
                return $context->builder->icmp(Builder::INT_SGT, $leftLong, $rightLong);
            case OpCode::TYPE_GREATER_OR_EQUAL:
                return $context->builder->icmp(Builder::INT_SGE, $leftLong, $rightLong);
            case OpCode::TYPE_SMALLER:
                return $context->builder->icmp(Builder::INT_SLT, $leftLong, $rightLong);
            case OpCode::TYPE_SMALLER_OR_EQUAL:
                return $context->builder->icmp(Builder::INT_SLE, $leftLong, $rightLong);
            default:
                throw new \LogicException(
                    'Ordered compare opcode not implemented for boxed long: '.opcode_type_name($opcodeType)
                );
        }
    }

    /**
     * Loose == between two native {@see __string__} operands (Zend zendi_smart_strcmp parity).
     */
    public static function looseEqualStringToString(
        Context $context,
        Value $leftStr,
        Value $rightStr
    ): Value {
        $identical = JitStringCompare::identical($context, $leftStr, $rightStr);
        $leftNumeric = self::stringIsNumeric($context, $leftStr);
        $rightNumeric = self::stringIsNumeric($context, $rightStr);
        $bothNumeric = $context->builder->and($leftNumeric, $rightNumeric);
        $leftDouble = self::stringToDouble($context, $leftStr);
        $rightDouble = self::stringToDouble($context, $rightStr);
        $numericEq = $context->builder->fcmp(Builder::REAL_OEQ, $leftDouble, $rightDouble);
        $numericMatch = $context->builder->and($bothNumeric, $numericEq);

        return $context->builder->or($identical, $numericMatch);
    }

    private static function stringIsNumeric(Context $context, Value $strPtr): Value
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
            'loose_str_is_numeric_end'
        );
        $nullEnd = $context->getTypeFromString('int8*')->constNull();
        $context->builder->store($nullEnd, $endPtrSlot);
        $context->builder->call($context->lookupFunction('strtod'), $charPtr, $endPtrSlot);
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

    private static function stringToDouble(Context $context, Value $strPtr): Value
    {
        $structName = $strPtr->typeOf()->getElementType()->getName();
        $map = $context->structFieldMap[$structName];
        $charPtr = $context->builder->structGep($strPtr, $map['value']);
        $endPtrSlot = $context->builder->alloca(
            $context->getTypeFromString('int8*'),
            1,
            'loose_str_strtod_end'
        );
        $nullEnd = $context->getTypeFromString('int8*')->constNull();
        $context->builder->store($nullEnd, $endPtrSlot);

        return $context->builder->call($context->lookupFunction('strtod'), $charPtr, $endPtrSlot);
    }
}
