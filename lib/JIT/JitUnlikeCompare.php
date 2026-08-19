<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\ext\standard\JitScalarTypeCoerce;
use PHPCompiler\OpCode;
use PHPCompiler\VM\ErrorReporter;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * JIT/AOT zend_compare for native object/array vs scalar (#32503 leftover of #32477/#32346).
 *
 * php-src: Zend/zend_operators.c compare_function / zend_compare —
 * plain object vs number is E_NOTICE + legacy 1; array vs number/string is ±1;
 * array vs null/bool uses zend_is_true (#32520 leftover of #32503);
 * variable arrays / boxed null must not materialize or skip the branch (#32528).
 * Object vs string without {@code __toString} is object-greater (#32514);
 * {@code null} literals are TYPE_VALUE + isNullConstant, not TYPE_NULL.
 * Object vs string == / != uses the same spaceship (#32515 leftover of #32503).
 * Object vs non-object === / !== is never identical ({@code zend_is_identical}, #32523).
 * VM SSOT: {@see \PHPCompiler\VM\CompareUnlikeHelper::zendUnlikeValueSpaceship}
 */
final class JitUnlikeCompare
{
    public static function tryLower(
        Context $context,
        int $opType,
        Variable $left,
        Variable $right
    ): ?Variable {
        $ordered = self::isCompareOp($opType);
        $equal = self::isLooseEqualOp($opType);
        $identical = self::isIdenticalOp($opType);
        if (!$ordered && !$equal && !$identical) {
            return null;
        }
        $leftObj = Variable::TYPE_OBJECT === $left->type;
        $rightObj = Variable::TYPE_OBJECT === $right->type;
        $leftArr = self::isArrayOperand($left);
        $rightArr = self::isArrayOperand($right);
        if ($leftObj && $rightObj) {
            return null;
        }
        if ($leftArr && $rightArr) {
            return null;
        }
        if ($leftObj || $rightObj) {
            return self::objectVsOther($context, $opType, $left, $right, $leftObj);
        }
        // Array == bool uses zend_is_true later; ordered compare vs null/bool is #32520.
        if ($ordered && ($leftArr || $rightArr)) {
            return self::arrayVsScalar($context, $opType, $left, $right, $leftArr);
        }
        // `$e = []` boxes as TYPE_VALUE; literal `[]` stays native (#32528 leftover of #32520).
        if ($ordered && (JitValueBox::isValueOperand($left) || JitValueBox::isValueOperand($right))) {
            return self::valueBoxOrdered($context, $opType, $left, $right);
        }

        return null;
    }

    private static function isCompareOp(int $opType): bool
    {
        return OpCode::TYPE_SPACESHIP === $opType
            || OpCode::TYPE_GREATER === $opType
            || OpCode::TYPE_GREATER_OR_EQUAL === $opType
            || OpCode::TYPE_SMALLER === $opType
            || OpCode::TYPE_SMALLER_OR_EQUAL === $opType;
    }

    private static function isLooseEqualOp(int $opType): bool
    {
        return OpCode::TYPE_EQUAL === $opType
            || OpCode::TYPE_NOT_EQUAL === $opType;
    }

    private static function isIdenticalOp(int $opType): bool
    {
        return OpCode::TYPE_IDENTICAL === $opType
            || OpCode::TYPE_NOT_IDENTICAL === $opType;
    }

    private static function isArrayOperand(Variable $var): bool
    {
        return Variable::TYPE_HASHTABLE === $var->type
            || ArrayBuiltinHelper::isNativeArray($var->type);
    }

    private static function objectVsOther(
        Context $context,
        int $opType,
        Variable $left,
        Variable $right,
        bool $objectOnLeft
    ): ?Variable {
        $obj = $objectOnLeft ? $left : $right;
        $other = $objectOnLeft ? $right : $left;
        $otherType = $other->type;
        if (self::isIdenticalOp($opType)) {
            // zend_is_identical: unlike types are never identical. Do not reuse
            // zend_compare — object == true is true, object === true is false.
            // Boxed TYPE_VALUE may hold the same object (#3622 identicalValueBoxToObject).
            if (Variable::TYPE_VALUE === $otherType && !$other->isNullConstant) {
                return null;
            }

            return self::fromIdenticalConst($context, $opType, false);
        }
        // php-cfg types `null` as a __value__ box (TYPE_VALUE 134) with isNullConstant,
        // not TYPE_NULL 0 — leftover of #32503 (#32514).
        if (Variable::TYPE_NULL === $otherType || $other->isNullConstant) {
            $cmpConst = $objectOnLeft ? 1 : -1;

            return self::fromSpaceshipConst($context, $opType, $cmpConst);
        }
        if (Variable::TYPE_STRING === $otherType) {
            return self::objectVsString($context, $opType, $obj, $other, $objectOnLeft);
        }
        if (Variable::TYPE_NATIVE_BOOL === $otherType) {
            $i64 = $context->getTypeFromString('int64');
            $objLong = $i64->constInt(1, false);
            $otherLong = $context->builder->zExt(
                $context->helper->loadValue($other),
                $i64
            );
            $cmp = self::longSpaceshipI64($context, $objLong, $otherLong);

            return self::fromSpaceshipValue($context, $opType, $objectOnLeft ? $cmp : self::negateCmp($context, $cmp));
        }
        if (Variable::TYPE_NATIVE_DOUBLE === $otherType) {
            $objPtr = $context->helper->loadValue($obj);
            $objDouble = JitScalarTypeCoerce::emitPlainObjectToScalar(
                $context,
                $objPtr,
                'float',
                ErrorReporter::E_NOTICE
            );
            $otherDouble = $context->helper->loadValue($other);
            $cmp = self::doubleSpaceshipI64($context, $objDouble, $otherDouble);

            return self::fromSpaceshipValue($context, $opType, $objectOnLeft ? $cmp : self::negateCmp($context, $cmp));
        }
        if (Variable::TYPE_NATIVE_LONG === $otherType) {
            $objPtr = $context->helper->loadValue($obj);
            $objLong = JitScalarTypeCoerce::emitPlainObjectToScalar(
                $context,
                $objPtr,
                'int',
                ErrorReporter::E_NOTICE
            );
            $otherLong = $context->helper->loadValue($other);
            if ($otherLong->typeOf() !== $objLong->typeOf()) {
                $otherLong = $context->builder->intCast($otherLong, $objLong->typeOf());
            }
            $cmp = self::longSpaceshipI64($context, $objLong, $otherLong);

            return self::fromSpaceshipValue($context, $opType, $objectOnLeft ? $cmp : self::negateCmp($context, $cmp));
        }
        if (self::isArrayOperand($other)) {
            // zend_compare: object > array.
            $cmpConst = $objectOnLeft ? 1 : -1;

            return self::fromSpaceshipConst($context, $opType, $cmpConst);
        }

        return null;
    }

    /**
     * CompareStringableHelper: {@code __toString} then strcmp; else object is greater (#32514).
     */
    private static function objectVsString(
        Context $context,
        int $opType,
        Variable $obj,
        Variable $other,
        bool $objectOnLeft
    ): Variable {
        $fallback = $objectOnLeft ? 1 : -1;
        $objectBuiltin = $context->type->object;
        $stringable = [];
        foreach ($objectBuiltin->allClassNamesById() as $id => $name) {
            $lc = strtolower(ltrim((string) $name, '\\'));
            if ($objectBuiltin->classHasImplicitStringableLc($lc)) {
                $stringable[(int) $id] = ltrim((string) $name, '\\');
            }
        }
        if ([] === $stringable) {
            return self::fromSpaceshipConst($context, $opType, $fallback);
        }

        $objPtr = $context->helper->loadValue($obj);
        $map = $context->structFieldMap['__object__'];
        $classId = $context->builder->load(
            $context->builder->structGep($objPtr, $map['class_id'])
        );
        $i64 = $context->getTypeFromString('int64');
        $tag = 'unlike_obj_str_'.spl_object_id($context);
        $done = BasicBlockHelper::append($context, $tag.'_done');
        $incoming = [];
        $ids = array_keys($stringable);
        $lastIdx = \count($ids) - 1;
        $fallbackBlock = BasicBlockHelper::append($context, $tag.'_fallback');

        foreach ($ids as $idx => $id) {
            $matchBlock = BasicBlockHelper::append($context, $tag.'_match_'.$id);
            $nextBlock = $idx === $lastIdx
                ? $fallbackBlock
                : BasicBlockHelper::append($context, $tag.'_next_'.$id);
            $context->builder->branchIf(
                $context->builder->icmp(
                    Builder::INT_EQ,
                    $classId,
                    $i64->constInt($id, false)
                ),
                $matchBlock,
                $nextBlock
            );
            $context->builder->positionAtEnd($matchBlock);
            $coerced = MagicMethodDispatch::coerceObjectToString($context, $obj, $stringable[$id]);
            if (null === $coerced) {
                $cmp = $i64->constInt($fallback, true);
            } else {
                $objStr = $context->helper->loadValue($coerced);
                $otherStr = $context->helper->loadValue($other);
                $cmp = JitStringCompare::strcmp(
                    $context,
                    $objectOnLeft ? $objStr : $otherStr,
                    $objectOnLeft ? $otherStr : $objStr
                );
            }
            $incoming[] = [$cmp, $context->builder->getInsertBlock()];
            $context->builder->branch($done);
            $context->builder->positionAtEnd($nextBlock);
        }

        $context->builder->positionAtEnd($fallbackBlock);
        $incoming[] = [$i64->constInt($fallback, true), $context->builder->getInsertBlock()];
        $context->builder->branch($done);
        $context->builder->positionAtEnd($done);
        $phi = $context->builder->phi($i64, $tag.'_phi');
        foreach ($incoming as [$val, $block]) {
            $phi->addIncoming($val, $block);
        }

        return self::fromSpaceshipValue($context, $opType, $phi);
    }

    private static function arrayVsScalar(
        Context $context,
        int $opType,
        Variable $left,
        Variable $right,
        bool $arrayOnLeft
    ): Variable {
        $array = $arrayOnLeft ? $left : $right;
        $other = $arrayOnLeft ? $right : $left;
        // zend_compare IS_ARRAY vs IS_NULL / IS_FALSE / IS_TRUE uses zend_is_true
        // (empty array <=> null/false is 0; nonempty <=> true is 0). #32520 leftover of #32503.
        if (self::isNullOperand($other) || Variable::TYPE_NATIVE_BOOL === $other->type) {
            return self::fromArrayTruthySpaceship(
                $context,
                $opType,
                $array,
                self::isNullOperand($other)
                    ? null
                    : $context->helper->loadValue($other),
                $arrayOnLeft
            );
        }
        if (JitValueBox::isValueOperand($other)) {
            return self::arrayVsValueBox($context, $opType, $array, $other, $arrayOnLeft);
        }

        // zend_compare: IS_ARRAY vs number/string/resource → array is greater (#32503).
        return self::fromSpaceshipConst($context, $opType, $arrayOnLeft ? 1 : -1);
    }

    /**
     * Boxed null/bool (function return, assigned locals) are TYPE_VALUE, not
     * isNullConstant / TYPE_NATIVE_BOOL (#32528 leftover of #32520).
     *
     * Do not {@see __value__readLong} on a bool tag (#8555 SIGSEGV). Bool payload
     * is the first byte of {@code __value__.value} (see {@code __value__writeBool}).
     */
    private static function arrayVsValueBox(
        Context $context,
        int $opType,
        Variable $array,
        Variable $other,
        bool $arrayOnLeft
    ): Variable {
        $ptr = JitValueBox::valuePtrFromVariable($context, $other);
        $i64 = $context->getTypeFromString('int64');
        $arrayLong = self::arrayTruthyI64($context, $array);
        $tag = 'arr_vbox_'.spl_object_id($context).'_'.spl_object_id($other);
        $nullPtrBlock = BasicBlockHelper::append($context, $tag.'_nullptr');
        $liveBlock = BasicBlockHelper::append($context, $tag.'_live');
        $doneBlock = BasicBlockHelper::append($context, $tag.'_done');
        $isNullPtr = $context->builder->icmp(
            Builder::INT_EQ,
            $ptr,
            $ptr->typeOf()->constNull()
        );
        $context->builder->branchIf($isNullPtr, $nullPtrBlock, $liveBlock);

        $context->builder->positionAtEnd($nullPtrBlock);
        $nullPtrCmp = $arrayOnLeft
            ? self::longSpaceshipI64($context, $arrayLong, $i64->constInt(0, false))
            : self::negateCmp(
                $context,
                self::longSpaceshipI64($context, $arrayLong, $i64->constInt(0, false))
            );
        $nullPtrEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($liveBlock);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($ptr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $isNull = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NULL, false)
        );
        $isBool = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_BOOL, false)
        );
        $useTruthy = $context->builder->or($isNull, $isBool);
        $valueField = $context->builder->structGep($ptr, $map['value']);
        $boolByte = $context->builder->load(
            $context->builder->inBoundsGEP(
                $valueField,
                $i32->constInt(0, false),
                $i64->constInt(0, false)
            )
        );
        $boolLong = $context->builder->zExt($boolByte, $i64);
        $otherLong = $context->builder->select(
            $isNull,
            $i64->constInt(0, false),
            $boolLong
        );
        $truthyCmp = self::longSpaceshipI64($context, $arrayLong, $otherLong);
        $oriented = $arrayOnLeft ? $truthyCmp : self::negateCmp($context, $truthyCmp);
        $greater = $i64->constInt($arrayOnLeft ? 1 : -1, true);
        $liveCmp = $context->builder->select($useTruthy, $oriented, $greater);
        $liveEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($i64, $tag.'_phi');
        $phi->addIncoming($nullPtrCmp, $nullPtrEnd);
        $phi->addIncoming($liveCmp, $liveEnd);

        return self::fromSpaceshipValue($context, $opType, $phi);
    }

    private static function fromArrayTruthySpaceship(
        Context $context,
        int $opType,
        Variable $array,
        ?Value $boolI1,
        bool $arrayOnLeft
    ): Variable {
        $arrayLong = self::arrayTruthyI64($context, $array);
        $i64 = $arrayLong->typeOf();
        $otherLong = null === $boolI1
            ? $i64->constInt(0, false)
            : $context->builder->zExt($boolI1, $i64);
        $cmp = self::longSpaceshipI64($context, $arrayLong, $otherLong);

        return self::fromSpaceshipValue(
            $context,
            $opType,
            $arrayOnLeft ? $cmp : self::negateCmp($context, $cmp)
        );
    }

    private static function isNullOperand(Variable $var): bool
    {
        return Variable::TYPE_NULL === $var->type || $var->isNullConstant;
    }

    /**
     * zend_is_true(IS_ARRAY) as i64 0/1.
     *
     * Native packed arrays use compile-time {@see Variable::$nextFreeElement}
     * (same as {@see \PHPCompiler\VM\VmValueCompare::looseEqualArrayToNull}).
     * Hashtable locals load the pointer — do not
     * {@see HashTableWriteLlvm::materializeNativeArrayForCall} (GEP of
     * KIND_VARIABLE {@code ->value} SIGSEGVs, #32528).
     */
    private static function arrayTruthyI64(Context $context, Variable $array): Value
    {
        $i64 = $context->getTypeFromString('int64');
        if (ArrayBuiltinHelper::isNativeArray($array->type)) {
            $n = $i64->constInt(max(0, $array->nextFreeElement), false);
            $truth = $context->builder->icmp(
                Builder::INT_NE,
                $n,
                $i64->constInt(0, false)
            );

            return $context->builder->zExt($truth, $i64);
        }
        $ht = $context->helper->loadValue($array);
        $fn = $context->lookupFunction('__hashtable__ptrIsNonEmpty');
        $ht = $context->builder->pointerCast($ht, $fn->getParam(0)->typeOf());
        $truth = $context->builder->call($fn, $ht);

        return $context->builder->zExt($truth, $i64);
    }

    /**
     * Assigned `$e = []` / boxed null are TYPE_VALUE, not native array (#32528).
     */
    private static function valueBoxOrdered(
        Context $context,
        int $opType,
        Variable $left,
        Variable $right
    ): ?Variable {
        $leftBox = JitValueBox::isValueOperand($left);
        $rightBox = JitValueBox::isValueOperand($right);
        if ($leftBox && (Variable::TYPE_NATIVE_BOOL === $right->type || self::isNullOperand($right))) {
            return self::boxedArrayVsNativeScalar($context, $opType, $left, $right, true);
        }
        if ($rightBox && (Variable::TYPE_NATIVE_BOOL === $left->type || self::isNullOperand($left))) {
            return self::boxedArrayVsNativeScalar($context, $opType, $right, $left, false);
        }
        if ($leftBox && $rightBox) {
            return self::twoBoxedOrdered($context, $opType, $left, $right);
        }

        return null;
    }

    private static function boxedArrayVsNativeScalar(
        Context $context,
        int $opType,
        Variable $boxed,
        Variable $scalar,
        bool $arrayOnLeft
    ): Variable {
        $ptr = JitValueBox::valuePtrFromVariable($context, $boxed);
        $arrayLong = self::boxedPtrHashtableTruthyI64($context, $ptr);
        $i64 = $arrayLong->typeOf();
        $otherLong = self::isNullOperand($scalar)
            ? $i64->constInt(0, false)
            : $context->builder->zExt($context->helper->loadValue($scalar), $i64);
        $isHt = self::valuePtrIsHashtable($context, $ptr);
        $truthyCmp = self::longSpaceshipI64($context, $arrayLong, $otherLong);
        $oriented = $arrayOnLeft ? $truthyCmp : self::negateCmp($context, $truthyCmp);
        $greater = $i64->constInt($arrayOnLeft ? 1 : -1, true);
        $cmp = $context->builder->select($isHt, $oriented, $greater);

        return self::fromSpaceshipValue($context, $opType, $cmp);
    }

    private static function twoBoxedOrdered(
        Context $context,
        int $opType,
        Variable $left,
        Variable $right
    ): Variable {
        $leftPtr = JitValueBox::valuePtrFromVariable($context, $left);
        $rightPtr = JitValueBox::valuePtrFromVariable($context, $right);
        $i64 = $context->getTypeFromString('int64');
        $leftHt = self::valuePtrIsHashtable($context, $leftPtr);
        $rightHt = self::valuePtrIsHashtable($context, $rightPtr);
        $rightNullBool = self::valuePtrIsNullOrBool($context, $rightPtr);
        $leftNullBool = self::valuePtrIsNullOrBool($context, $leftPtr);
        $leftArrVsScalar = $context->builder->and($leftHt, $rightNullBool);
        $rightArrVsScalar = $context->builder->and($rightHt, $leftNullBool);
        $eitherHt = $context->builder->or($leftHt, $rightHt);

        $leftTruthy = self::boxedPtrHashtableTruthyI64($context, $leftPtr);
        $rightTruthy = self::boxedPtrHashtableTruthyI64($context, $rightPtr);
        $rightOther = self::valuePtrNullBoolLong($context, $rightPtr);
        $leftOther = self::valuePtrNullBoolLong($context, $leftPtr);
        $cmpLeft = self::longSpaceshipI64($context, $leftTruthy, $rightOther);
        $cmpRight = self::negateCmp(
            $context,
            self::longSpaceshipI64($context, $rightTruthy, $leftOther)
        );
        $arrGreater = $context->builder->select(
            $leftHt,
            $i64->constInt(1, true),
            $i64->constInt(-1, true)
        );
        $truthyCmp = $context->builder->select($leftArrVsScalar, $cmpLeft, $cmpRight);
        $arrPair = $context->builder->select(
            $context->builder->or($leftArrVsScalar, $rightArrVsScalar),
            $truthyCmp,
            $arrGreater
        );
        $tag = 'two_vbox_'.spl_object_id($context);
        $htBlock = BasicBlockHelper::append($context, $tag.'_ht');
        $genBlock = BasicBlockHelper::append($context, $tag.'_gen');
        $doneBlock = BasicBlockHelper::append($context, $tag.'_done');
        $context->builder->branchIf($eitherHt, $htBlock, $genBlock);

        $context->builder->positionAtEnd($htBlock);
        $htEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($genBlock);
        \PHPCompiler\JIT\Builtin\SpaceshipRuntime::ensureLinked($context);
        $generic = \PHPCompiler\JIT\Builtin\SpaceshipRuntime::callValueSpaceship(
            $context,
            $leftPtr,
            $rightPtr
        );
        $genEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($i64, $tag.'_phi');
        $phi->addIncoming($arrPair, $htEnd);
        $phi->addIncoming($generic, $genEnd);

        return self::fromSpaceshipValue($context, $opType, $phi);
    }

    private static function valuePtrKind(Context $context, Value $ptr): Value
    {
        $i8 = $context->getTypeFromString('int8');
        $typeByte = $context->builder->load(
            $context->builder->structGep($ptr, $context->structFieldMap['__value__']['type'])
        );

        return $context->builder->and($typeByte, $i8->constInt(0x7f, false));
    }

    private static function valuePtrIsHashtable(Context $context, Value $ptr): Value
    {
        $i8 = $context->getTypeFromString('int8');
        $kind = self::valuePtrKind($context, $ptr);

        return $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(Variable::TYPE_HASHTABLE & 0x7f, false)
        );
    }

    private static function valuePtrIsNullOrBool(Context $context, Value $ptr): Value
    {
        $i8 = $context->getTypeFromString('int8');
        $kind = self::valuePtrKind($context, $ptr);
        $isNull = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(Variable::TYPE_NULL, false)
        );
        $isBool = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(Variable::TYPE_NATIVE_BOOL, false)
        );

        return $context->builder->or($isNull, $isBool);
    }

    private static function valuePtrNullBoolLong(Context $context, Value $ptr): Value
    {
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $kind = self::valuePtrKind($context, $ptr);
        $isNull = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(Variable::TYPE_NULL, false)
        );
        $valueField = $context->builder->structGep(
            $ptr,
            $context->structFieldMap['__value__']['value']
        );
        $boolByte = $context->builder->load(
            $context->builder->inBoundsGEP(
                $valueField,
                $i32->constInt(0, false),
                $i64->constInt(0, false)
            )
        );

        return $context->builder->select(
            $isNull,
            $i64->constInt(0, false),
            $context->builder->zExt($boolByte, $i64)
        );
    }

    private static function boxedPtrHashtableTruthyI64(Context $context, Value $ptr): Value
    {
        $read = $context->lookupFunction('__value__readHashtable');
        $ht = $context->builder->call(
            $read,
            $context->builder->pointerCast($ptr, $read->getParam(0)->typeOf())
        );
        $fn = $context->lookupFunction('__hashtable__ptrIsNonEmpty');
        $ht = $context->builder->pointerCast($ht, $fn->getParam(0)->typeOf());
        $truth = $context->builder->call($fn, $ht);

        return $context->builder->zExt($truth, $context->getTypeFromString('int64'));
    }

    private static function fromIdenticalConst(Context $context, int $opType, bool $areIdentical): Variable
    {
        $bit = OpCode::TYPE_IDENTICAL === $opType ? $areIdentical : !$areIdentical;
        $i1 = $context->getTypeFromString('int1');

        return new Variable(
            $context,
            Variable::TYPE_NATIVE_BOOL,
            Variable::KIND_VALUE,
            $i1->constInt($bit ? 1 : 0, false)
        );
    }

    private static function fromSpaceshipConst(Context $context, int $opType, int $cmp): Variable
    {
        $i64 = $context->getTypeFromString('int64');
        $val = $i64->constInt($cmp, true);

        return self::fromSpaceshipValue($context, $opType, $val);
    }

    private static function fromSpaceshipValue(Context $context, int $opType, Value $cmp): Variable
    {
        if (OpCode::TYPE_SPACESHIP === $opType) {
            return new Variable($context, Variable::TYPE_NATIVE_LONG, Variable::KIND_VALUE, $cmp);
        }
        if (OpCode::TYPE_EQUAL === $opType || OpCode::TYPE_NOT_EQUAL === $opType) {
            $zero = $cmp->typeOf()->constInt(0, false);
            $eq = $context->builder->icmp(Builder::INT_EQ, $cmp, $zero);
            if (OpCode::TYPE_NOT_EQUAL === $opType) {
                $eq = $context->builder->xor(
                    $eq,
                    $context->getTypeFromString('int1')->constInt(1, false)
                );
            }

            return new Variable($context, Variable::TYPE_NATIVE_BOOL, Variable::KIND_VALUE, $eq);
        }

        return new Variable(
            $context,
            Variable::TYPE_NATIVE_BOOL,
            Variable::KIND_VALUE,
            JitValueCompare::boolFromSpaceshipCmp($context, $opType, $cmp)
        );
    }

    private static function longSpaceshipI64(Context $context, Value $left, Value $right): Value
    {
        $ty = $left->typeOf();
        $lt = $context->builder->icmp(Builder::INT_SLT, $left, $right);
        $gt = $context->builder->icmp(Builder::INT_SGT, $left, $right);

        return $context->builder->select(
            $gt,
            $ty->constInt(1, true),
            $context->builder->select($lt, $ty->constInt(-1, true), $ty->constInt(0, false))
        );
    }

    private static function doubleSpaceshipI64(Context $context, Value $left, Value $right): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $lt = $context->builder->fcmp(Builder::REAL_OLT, $left, $right);
        $gt = $context->builder->fcmp(Builder::REAL_OGT, $left, $right);

        return $context->builder->select(
            $gt,
            $i64->constInt(1, true),
            $context->builder->select($lt, $i64->constInt(-1, true), $i64->constInt(0, false))
        );
    }

    private static function negateCmp(Context $context, Value $cmp): Value
    {
        return $context->builder->negate($cmp);
    }
}
