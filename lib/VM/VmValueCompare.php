<?php

declare(strict_types=1);

/**
 * SSOT for JIT boxed __value__ compare lowering (Zend zend_operators.c, #9972).
 *
 * php-src: Zend/zend_operators.c — compare_function, zend_compare
 *
 * JIT trampoline: {@see \PHPCompiler\JIT\JitValueCompare}
 */

namespace PHPCompiler\VM;

require_once __DIR__.'/../OpCodeNames.php';

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\SpaceshipRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringCompare;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\LibcExtern;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\JIT\Variable;
use PHPCompiler\OpCode;
use PHPCompiler\VM\Variable as VmVariable;
use function PHPCompiler\opcode_type_name;
use PHPLLVM\Builder;
use PHPLLVM\Value;

final class VmValueCompare
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
                return self::identicalValueToNativeBool($context, $valuePtr, $typeByte, $native);
            case Variable::TYPE_NATIVE_LONG:
                return self::identicalValueToNativeLong($context, $valuePtr, $typeByte, $native);
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

        // Zend: enum case loose == with backing scalar is false (#5798, #5819/#5835/#8880 switch labels).
        $enumCaseTag = $i8->constInt(VmVariable::TYPE_ENUM_CASE, false);
        $isEnumCase = $context->builder->icmp(Builder::INT_EQ, $typeByte, $enumCaseTag);
        $objectTag = $i8->constInt(VmVariable::TYPE_OBJECT, false);
        $isObject = $context->builder->icmp(Builder::INT_EQ, $typeByte, $objectTag);
        $isEnumOrObject = $context->builder->or($isEnumCase, $isObject);

        $longTag = $i8->constInt(Variable::TYPE_NATIVE_LONG, false);
        $isLong = $context->builder->icmp(Builder::INT_EQ, $typeByte, $longTag);
        $stored = $context->builder->call(
            $context->lookupFunction('__value__readLong'),
            $valuePtr
        );
        $isResource = self::nativeLongIsResource($context, $stored);
        $longMatches = self::nativeLongEqualWithResourceIdentity($context, $stored, $__native);

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

        $scalarMatches = $context->builder->select(
            $isLong,
            $context->builder->select($isResource, $falseVal, $longMatches),
            $context->builder->select(
                $isBool,
                $boolMatches,
                $context->builder->select($isNull, $nullMatches, $falseVal)
            )
        );

        return $context->builder->select($isEnumOrObject, $falseVal, $scalarMatches);
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
     * Loose == between boxed __value__ and native double (Zend zend_operators.c).
     */
    public static function looseEqualValueToNativeDouble(
        Context $context,
        Variable $boxed,
        Value $nativeDouble
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
        $double = $context->getTypeFromString('double');
        $falseVal = $context->getTypeFromString('int1')->constInt(0, false);

        $enumCaseTag = $i8->constInt(VmVariable::TYPE_ENUM_CASE, false);
        $isEnumCase = $context->builder->icmp(Builder::INT_EQ, $typeByte, $enumCaseTag);
        $objectTag = $i8->constInt(VmVariable::TYPE_OBJECT, false);
        $isObject = $context->builder->icmp(Builder::INT_EQ, $typeByte, $objectTag);
        $isEnumOrObject = $context->builder->or($isEnumCase, $isObject);

        $doubleTag = $i8->constInt(Variable::TYPE_NATIVE_DOUBLE, false);
        $isDouble = $context->builder->icmp(Builder::INT_EQ, $typeByte, $doubleTag);
        $storedDouble = $context->builder->call(
            $context->lookupFunction('__value__readDouble'),
            $valuePtr
        );
        $doubleMatches = VmFloatCompare::relationalCompare(
            $context,
            OpCode::TYPE_EQUAL,
            $storedDouble,
            $nativeDouble
        );

        $longTag = $i8->constInt(Variable::TYPE_NATIVE_LONG, false);
        $isLong = $context->builder->icmp(Builder::INT_EQ, $typeByte, $longTag);
        $storedLong = $context->builder->call(
            $context->lookupFunction('__value__readLong'),
            $valuePtr
        );
        $longMatches = VmFloatCompare::relationalCompare(
            $context,
            OpCode::TYPE_EQUAL,
            $context->builder->sitofp($storedLong, $double),
            $nativeDouble
        );

        $boolTag = $i8->constInt(Variable::TYPE_NATIVE_BOOL, false);
        $isBool = $context->builder->icmp(Builder::INT_EQ, $typeByte, $boolTag);
        $boolMatches = VmFloatCompare::relationalCompare(
            $context,
            OpCode::TYPE_EQUAL,
            $context->builder->uitofp(
                self::readBoolBoxedAsLong($context, $valuePtr),
                $double
            ),
            $nativeDouble
        );

        $nullTag = $i8->constInt(Variable::TYPE_NULL, false);
        $isNull = $context->builder->icmp(Builder::INT_EQ, $typeByte, $nullTag);
        $nullMatches = VmFloatCompare::relationalCompare(
            $context,
            OpCode::TYPE_EQUAL,
            $nativeDouble,
            $context->constantFromFloat(0.0)
        );

        $stringTag = $i8->constInt(Variable::TYPE_STRING, false);
        $isString = $context->builder->icmp(Builder::INT_EQ, $typeByte, $stringTag);
        $storedStr = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $valuePtr
        );
        $stringMatches = $context->builder->and(
            self::stringIsNumeric($context, $storedStr),
            VmFloatCompare::relationalCompare(
                $context,
                OpCode::TYPE_EQUAL,
                self::stringToDouble($context, $storedStr),
                $nativeDouble
            )
        );

        $scalarMatches = $context->builder->select(
            $isDouble,
            $doubleMatches,
            $context->builder->select(
                $isLong,
                $longMatches,
                $context->builder->select(
                    $isBool,
                    $boolMatches,
                    $context->builder->select(
                        $isNull,
                        $nullMatches,
                        $context->builder->select($isString, $stringMatches, $falseVal)
                    )
                )
            )
        );

        return $context->builder->select($isEnumOrObject, $falseVal, $scalarMatches);
    }

    public static function looseEqualNativeDoubleToValue(
        Context $context,
        Value $nativeDouble,
        Variable $boxed
    ): Value {
        return self::looseEqualValueToNativeDouble($context, $boxed, $nativeDouble);
    }

    public static function notLooseEqualValueToNativeDouble(
        Context $context,
        Variable $boxed,
        Value $nativeDouble
    ): Value {
        $same = self::looseEqualValueToNativeDouble($context, $boxed, $nativeDouble);
        $i1 = $context->getTypeFromString('int1');

        return $context->builder->icmp(Builder::INT_EQ, $same, $i1->constInt(0, false));
    }

    public static function notLooseEqualNativeDoubleToValue(
        Context $context,
        Value $nativeDouble,
        Variable $boxed
    ): Value {
        return self::notLooseEqualValueToNativeDouble($context, $boxed, $nativeDouble);
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
        // strcmp(3) via LibcExtern::ensureStrcmpDecl after always-on drop (#31971).
        LibcExtern::ensureStrcmpDecl($context);
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
        $longMatch = self::nativeLongEqualWithResourceIdentity($context, $leftLong, $rightLong);
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

    /**
     * Loose == for operand pair (#4766): native {@see __object__*} or boxed {@see __value__}.
     */
    public static function looseEqualOperands(Context $context, Variable $left, Variable $right): Value
    {
        if (Variable::TYPE_OBJECT === $left->type && Variable::TYPE_OBJECT === $right->type) {
            return self::looseEqualObjectPair(
                $context,
                $context->helper->loadValue($left),
                $context->helper->loadValue($right)
            );
        }

        return self::looseEqualValueToValue($context, $left, $right);
    }

    /**
     * Loose == on two boxed {@see __value__} operands (#4766, Zend compare_objects).
     */
    public static function looseEqualValueToValue(
        Context $context,
        Variable $left,
        Variable $right
    ): Value {
        if (!JitValueBox::isValueOperand($left) || !JitValueBox::isValueOperand($right)) {
            throw new \LogicException('Expected two boxed __value__ operands');
        }
        SpaceshipRuntime::ensureLinked($context);
        $readFn = $context->lookupFunction('__value__readObject');
        $readTy = $readFn->getParam(0)->typeOf();
        $leftObj = $context->builder->call(
            $readFn,
            $context->builder->pointerCast(self::runtimeValuePtr($context, $left), $readTy)
        );
        $rightObj = $context->builder->call(
            $readFn,
            $context->builder->pointerCast(self::runtimeValuePtr($context, $right), $readTy)
        );
        $cmpFn = $context->lookupFunction('__object__compareSpaceship');
        $cmp = $context->builder->call(
            $cmpFn,
            $context->builder->pointerCast($leftObj, $cmpFn->getParam(0)->typeOf()),
            $context->builder->pointerCast($rightObj, $cmpFn->getParam(1)->typeOf())
        );
        $zero = $cmp->typeOf()->constInt(0, false);

        return $context->builder->icmp(Builder::INT_EQ, $cmp, $zero);
    }

    /**
     * Loose == on two {@see __object__*} handles (#4766, {@see __object__compareSpaceship}).
     */
    public static function looseEqualObjectPair(Context $context, Value $leftObj, Value $rightObj): Value
    {
        SpaceshipRuntime::ensureLinked($context);
        $cmp = $context->builder->call(
            $context->lookupFunction('__object__compareSpaceship'),
            $leftObj,
            $rightObj
        );
        $zero = $cmp->typeOf()->constInt(0, false);

        return $context->builder->icmp(Builder::INT_EQ, $cmp, $zero);
    }

    /**
     * Loose == on two compile-time native array literals (#5033, Zend zend_compare_arrays).
     */
    public static function looseEqualNativeArrayPair(
        Context $context,
        Variable $left,
        Variable $right
    ): Value {
        $i1 = $context->getTypeFromString('int1');
        $falseVal = $i1->constInt(0, false);
        if ($left->nextFreeElement !== $right->nextFreeElement) {
            return $falseVal;
        }
        if (0 === $left->nextFreeElement) {
            return $i1->constInt(1, false);
        }
        $elemType = $left->type & ~Variable::IS_NATIVE_ARRAY;
        $result = $i1->constInt(1, false);
        for ($i = 0; $i < $left->nextFreeElement; ++$i) {
            $idx = Variable::fromConstantInt($context, $i);
            $lElem = $left->dimFetch($idx);
            $rElem = $right->dimFetch($idx);
            if (Variable::TYPE_NATIVE_LONG === $elemType) {
                $eq = $context->builder->icmp(
                    Builder::INT_EQ,
                    $context->helper->loadValue($lElem),
                    $context->helper->loadValue($rElem)
                );
            } elseif (Variable::TYPE_NATIVE_BOOL === $elemType) {
                $eq = $context->builder->icmp(
                    Builder::INT_EQ,
                    $context->helper->loadValue($lElem),
                    $context->helper->loadValue($rElem)
                );
            } elseif (Variable::TYPE_NATIVE_DOUBLE === $elemType) {
                $eq = $context->builder->fcmp(
                    Builder::REAL_OEQ,
                    $context->helper->loadValue($lElem),
                    $context->helper->loadValue($rElem)
                );
            } else {
                return $falseVal;
            }
            $result = $context->builder->and($result, $eq);
        }

        return $result;
    }

    /**
     * Loose == on two {@see __hashtable__*} operands (#5033, Zend zend_compare_arrays / compare_function).
     */
    public static function looseEqualHashtablePair(Context $context, Value $leftHt, Value $rightHt): Value
    {
        SpaceshipRuntime::ensureLinked($context);
        $fn = $context->lookupFunction('__hashtable__compareSpaceship');
        $cmp = $context->builder->call(
            $fn,
            $context->builder->pointerCast($leftHt, $fn->getParam(0)->typeOf()),
            $context->builder->pointerCast($rightHt, $fn->getParam(1)->typeOf())
        );
        $zero = $cmp->typeOf()->constInt(0, false);

        return $context->builder->icmp(Builder::INT_EQ, $cmp, $zero);
    }

    /** {@see __value__*} with types accepted by linked runtime bitcode (#4766). */
    public static function runtimeValuePtr(Context $context, Variable $var): Value
    {
        if (Variable::KIND_VARIABLE === $var->kind) {
            $ty = $context->getStringFromType($var->value->typeOf());
            if ('__value__' === $ty) {
                return JitValueBox::pointer($context, $var->value);
            }
        }

        return JitValueBox::normalizeValuePtr(
            $context,
            JitValueBox::valuePtrFromVariable($context, $var)
        );
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

    public static function orderedValueToNativeDouble(
        Context $context,
        int $opcodeType,
        Variable $boxed,
        Value $nativeDouble
    ): Value {
        if (!JitValueBox::isValueOperand($boxed)) {
            throw new \LogicException('Expected boxed __value__ operand');
        }
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $boxed);
        $leftDouble = $context->builder->call(
            $context->lookupFunction('__value__readDouble'),
            $valuePtr
        );

        return VmFloatCompare::relationalCompare(
            $context,
            $opcodeType,
            $leftDouble,
            $nativeDouble
        );
    }

    public static function orderedNativeDoubleToValue(
        Context $context,
        int $opcodeType,
        Value $nativeDouble,
        Variable $boxed
    ): Value {
        if (!JitValueBox::isValueOperand($boxed)) {
            throw new \LogicException('Expected boxed __value__ operand');
        }
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $boxed);
        $rightDouble = $context->builder->call(
            $context->lookupFunction('__value__readDouble'),
            $valuePtr
        );

        return VmFloatCompare::relationalCompare(
            $context,
            $opcodeType,
            $nativeDouble,
            $rightDouble
        );
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
        $orderedLong = self::orderedLongCompare($context, $opcodeType, $leftLong, $rightLong);

        // Zend compare_function object branch → zend_compare_objects (#25241).
        $objectTag = $i8->constInt(Variable::TYPE_OBJECT, false);
        $bothObj = $context->builder->and(
            $context->builder->icmp(Builder::INT_EQ, $leftType, $objectTag),
            $context->builder->icmp(Builder::INT_EQ, $rightType, $objectTag)
        );
        SpaceshipRuntime::ensureLinked($context);
        $leftObj = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $leftPtr
        );
        $rightObj = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $rightPtr
        );
        $objCmp = SpaceshipRuntime::callObjectCompareSpaceship($context, $leftObj, $rightObj);
        $orderedObj = self::boolFromSpaceshipCmp($context, $opcodeType, $objCmp);

        $notLong = $context->builder->select($bothObj, $orderedObj, $falseVal);

        return $context->builder->select($bothLong, $orderedLong, $notLong);
    }

    /**
     * Map zend_compare / spaceship i64 (-1/0/1) to relational bool (#25241).
     */
    public static function boolFromSpaceshipCmp(
        Context $context,
        int $opcodeType,
        Value $cmp
    ): Value {
        $zero = $cmp->typeOf()->constInt(0, false);

        return match ($opcodeType) {
            OpCode::TYPE_SMALLER => $context->builder->icmp(Builder::INT_SLT, $cmp, $zero),
            OpCode::TYPE_GREATER => $context->builder->icmp(Builder::INT_SGT, $cmp, $zero),
            OpCode::TYPE_SMALLER_OR_EQUAL => $context->builder->icmp(Builder::INT_SLE, $cmp, $zero),
            OpCode::TYPE_GREATER_OR_EQUAL => $context->builder->icmp(Builder::INT_SGE, $cmp, $zero),
            default => throw new \LogicException(
                'Ordered compare opcode not implemented for spaceship result: '.opcode_type_name($opcodeType)
            ),
        };
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
     * Loose == between native {@see __string__} and native long (#4035, Zend zend_operators.c).
     */
    public static function looseEqualStringToNativeLong(
        Context $context,
        Value $strPtr,
        Value $nativeLong
    ): Value {
        $map = $context->structFieldMap['__string__'];
        $len = $context->builder->load(
            $context->builder->structGep($strPtr, $map['length'])
        );
        $i64 = $context->getTypeFromString('int64');
        $double = $context->getTypeFromString('double');
        $i1 = $context->getTypeFromString('int1');
        $falseVal = $i1->constInt(0, false);
        $zeroLen = $context->builder->icmp(Builder::INT_EQ, $len, $len->typeOf()->constInt(0, false));
        $__native = $context->builder->intCast($nativeLong, $i64);

        $isIntegerNumeric = self::stringIsIntegerNumeric($context, $strPtr);
        $isNumeric = self::stringIsNumeric($context, $strPtr);
        $isFloatNumeric = $context->builder->and($isNumeric, $context->builder->not($isIntegerNumeric));
        $charPtr = $context->builder->structGep($strPtr, $map['value']);
        $i8p = $context->getTypeFromString('int8*');
        $endPtrSlot = $context->builder->alloca($i8p, 1, 'loose_strlong_strtol_end');
        $nullEnd = $i8p->constNull();
        $context->builder->store($nullEnd, $endPtrSlot);
        // strtol(3) via LibcExtern::ensureStrtolDecl after always-on drop (#31988).
        LibcExtern::ensureStrtolDecl($context);
        $parsed = $context->builder->call(
            $context->lookupFunction('strtol'),
            $charPtr,
            $endPtrSlot,
            $context->getTypeFromString('int32')->constInt(10, false)
        );
        $parsedI64 = $parsed->typeOf() === $i64 ? $parsed : $context->builder->zExt($parsed, $i64);
        $intMatch = $context->builder->and(
            $isIntegerNumeric,
            $context->builder->icmp(Builder::INT_EQ, $parsedI64, $__native)
        );
        $strDouble = self::stringToDouble($context, $strPtr);
        $nativeDouble = $context->builder->sitofp($__native, $double);
        $floatMatch = $context->builder->and(
            $isFloatNumeric,
            $context->builder->fcmp(Builder::REAL_OEQ, $strDouble, $nativeDouble)
        );

        $matched = $context->builder->or($intMatch, $floatMatch);

        return $context->builder->select($zeroLen, $falseVal, $matched);
    }

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

    public static function stringIsNumeric(Context $context, Value $strPtr): Value
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
        // strtod(3) via LibcExtern::ensureStrtodDecl after always-on drop (#31997).
        LibcExtern::ensureStrtodDecl($context);
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

    /**
     * True when strtol(base 10) consumes no bytes — string has no leading integer prefix (#3644, #5427).
     */
    public static function stringHasNoLeadingIntegerPrefix(Context $context, Value $strPtr): Value
    {
        return self::stringStrtolConsumedNothing($context, $strPtr);
    }

    /**
     * True when strtol(base 10) consumes no bytes — string has no leading integer prefix (#3644).
     */
    private static function stringStrtolConsumedNothing(Context $context, Value $strPtr): Value
    {
        $map = $context->structFieldMap['__string__'];
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $charPtr = $context->builder->structGep($strPtr, $map['value']);
        $endPtrSlot = $context->builder->alloca($i8p, 1, 'loose_str_strtol_prefix_end');
        $nullEnd = $i8p->constNull();
        $context->builder->store($nullEnd, $endPtrSlot);
        // strtol(3) via LibcExtern::ensureStrtolDecl after always-on drop (#31988).
        LibcExtern::ensureStrtolDecl($context);
        $context->builder->call(
            $context->lookupFunction('strtol'),
            $charPtr,
            $endPtrSlot,
            $context->getTypeFromString('int32')->constInt(10, false)
        );
        $endPtr = $context->builder->load($endPtrSlot);
        $endOffset = $context->builder->sub(
            $context->builder->ptrToInt($endPtr, $i64),
            $context->builder->ptrToInt($charPtr, $i64)
        );

        return $context->builder->icmp(
            Builder::INT_EQ,
            $endOffset,
            $i64->constInt(0, false)
        );
    }

    /**
     * True when the entire string is an integer numeric string (strtol consumes all bytes).
     */
    private static function stringIsIntegerNumeric(Context $context, Value $strPtr): Value
    {
        $map = $context->structFieldMap['__string__'];
        $len = $context->builder->load(
            $context->builder->structGep($strPtr, $map['length'])
        );
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $len, $len->typeOf()->constInt(0, false));

        $charPtr = $context->builder->structGep($strPtr, $map['value']);
        $endPtrSlot = $context->builder->alloca($i8p, 1, 'loose_str_is_int_end');
        $nullEnd = $i8p->constNull();
        $context->builder->store($nullEnd, $endPtrSlot);
        // strtol(3) via LibcExtern::ensureStrtolDecl after always-on drop (#31988).
        LibcExtern::ensureStrtolDecl($context);
        $context->builder->call(
            $context->lookupFunction('strtol'),
            $charPtr,
            $endPtrSlot,
            $context->getTypeFromString('int32')->constInt(10, false)
        );
        $endPtr = $context->builder->load($endPtrSlot);
        $endOffset = $context->builder->sub(
            $context->builder->ptrToInt($endPtr, $i64),
            $context->builder->ptrToInt($charPtr, $i64)
        );
        $consumedAll = $context->builder->icmp(Builder::INT_EQ, $endOffset, $len);

        return $context->builder->select($isEmpty, $context->constantFromBool(false), $consumedAll);
    }

    public static function stringToDouble(Context $context, Value $strPtr): Value
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
        // strtod(3) via LibcExtern::ensureStrtodDecl after always-on drop (#31997).
        LibcExtern::ensureStrtodDecl($context);

        return $context->builder->call($context->lookupFunction('strtod'), $charPtr, $endPtrSlot);
    }

    /** True when a native handle id is registered in the stream/dir tables (#4699). */
    public static function nativeLongIsResource(Context $context, Value $handleLong): Value
    {
        if (NestedJitCompileScope::isActive()) {
            return $context->getTypeFromString('int1')->constInt(0, false);
        }
        $i32 = $context->getTypeFromString('int32');
        $ret = $context->builder->call(
            $context->lookupFunction('__compiler_is_resource'),
            $handleLong
        );

        return $context->builder->icmp(Builder::INT_NE, $ret, $i32->constInt(0, false));
    }

    /**
     * == / === for native long operands: resources compare by handle id; plain ints ignore resource slots (#4699).
     */
    public static function nativeLongEqualWithResourceIdentity(
        Context $context,
        Value $leftLong,
        Value $rightLong
    ): Value {
        $i1 = $context->getTypeFromString('int1');
        $falseVal = $i1->constInt(0, false);
        $leftRes = self::nativeLongIsResource($context, $leftLong);
        $rightRes = self::nativeLongIsResource($context, $rightLong);
        $sameResKind = $context->builder->icmp(Builder::INT_EQ, $leftRes, $rightRes);
        $sameId = $context->builder->icmp(Builder::INT_EQ, $leftLong, $rightLong);
        $bothRes = $context->builder->and($leftRes, $rightRes);
        $plainMatch = $context->builder->and(
            $context->builder->not($leftRes),
            $context->builder->and($context->builder->not($rightRes), $sameId)
        );
        $resourceMatch = $context->builder->and($bothRes, $sameId);
        $match = $context->builder->or($plainMatch, $resourceMatch);

        return $context->builder->select($sameResKind, $match, $falseVal);
    }

    /** Avoid eager {@see __value__readLong} under LLVM select on non-bool tags (#8555). */
    private static function identicalValueToNativeBool(
        Context $context,
        Value $valuePtr,
        Value $typeByte,
        Variable $native
    ): Value {
        $i8 = $context->getTypeFromString('int8');
        $i1 = $context->getTypeFromString('int1');
        $falseVal = $i1->constInt(0, false);
        $nullTag = $i8->constInt(Variable::TYPE_NULL, false);
        $boolTag = $i8->constInt(Variable::TYPE_NATIVE_BOOL, false);
        $isNull = $context->builder->icmp(Builder::INT_EQ, $typeByte, $nullTag);
        $isBool = $context->builder->icmp(Builder::INT_EQ, $typeByte, $boolTag);

        $falseBlock = BasicBlockHelper::append($context, 'identical_value_native_bool_false');
        $boolBlock = BasicBlockHelper::append($context, 'identical_value_native_bool_match');
        $afterNullBlock = BasicBlockHelper::append($context, 'identical_value_native_bool_after_null');
        $doneBlock = BasicBlockHelper::append($context, 'identical_value_native_bool_done');

        $context->builder->branchIf($isNull, $falseBlock, $afterNullBlock);
        $context->builder->positionAtEnd($afterNullBlock);
        $context->builder->branchIf($isBool, $boolBlock, $falseBlock);

        $context->builder->positionAtEnd($falseBlock);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($boolBlock);
        $stored = self::readBoolBoxedAsLong($context, $valuePtr);
        $nativeBool = $context->helper->loadValue($native);
        $expectedStored = $context->builder->zExt($nativeBool, $context->getTypeFromString('int64'));
        $matches = $context->builder->icmp(Builder::INT_EQ, $stored, $expectedStored);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($i1, 'identical_value_native_bool_phi');
        $phi->addIncoming($falseVal, $falseBlock);
        $phi->addIncoming($matches, $boolBlock);

        return $phi;
    }

    /** Avoid eager {@see __value__readLong} under LLVM select on non-long tags (#8555). */
    private static function identicalValueToNativeLong(
        Context $context,
        Value $valuePtr,
        Value $typeByte,
        Variable $native
    ): Value {
        $i8 = $context->getTypeFromString('int8');
        $i1 = $context->getTypeFromString('int1');
        $falseVal = $i1->constInt(0, false);
        $longTag = $i8->constInt(Variable::TYPE_NATIVE_LONG, false);
        $isLong = $context->builder->icmp(Builder::INT_EQ, $typeByte, $longTag);

        $falseBlock = BasicBlockHelper::append($context, 'identical_value_native_long_false');
        $longBlock = BasicBlockHelper::append($context, 'identical_value_native_long_match');
        $doneBlock = BasicBlockHelper::append($context, 'identical_value_native_long_done');

        $context->builder->branchIf($isLong, $longBlock, $falseBlock);

        $context->builder->positionAtEnd($falseBlock);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($longBlock);
        $stored = $context->builder->call(
            $context->lookupFunction('__value__readLong'),
            $valuePtr
        );
        $nativeLong = $context->helper->loadValue($native);
        $isResource = self::nativeLongIsResource($context, $stored);
        $matches = self::nativeLongEqualWithResourceIdentity($context, $stored, $nativeLong);
        $longResult = $context->builder->select($isResource, $falseVal, $matches);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($i1, 'identical_value_native_long_phi');
        $phi->addIncoming($falseVal, $falseBlock);
        $phi->addIncoming($longResult, $longBlock);

        return $phi;
    }

    private static function readBoolBoxedAsLong(Context $context, Value $valuePtr): Value
    {
        $map = $context->structFieldMap['__value__'];
        $valueField = $context->builder->structGep($valuePtr, $map['value']);
        $firstBytePtr = $context->builder->inBoundsGEP(
            $valueField,
            $context->getTypeFromString('int32')->constInt(0, false),
            $context->getTypeFromString('int64')->constInt(0, false)
        );
        $firstByte = $context->builder->load($firstBytePtr);
        $truthy = $context->builder->icmp(
            Builder::INT_NE,
            $firstByte,
            $context->getTypeFromString('int8')->constInt(0, false)
        );

        return $context->builder->zExt($truthy, $context->getTypeFromString('int64'));
    }
}
