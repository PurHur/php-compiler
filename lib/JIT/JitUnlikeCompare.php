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
        if (JitValueBox::isValueOperand($left) && JitValueBox::isValueOperand($right)) {
            $boxed = self::tryLowerBoxedPair($context, $opType, $left, $right);
            if (null !== $boxed) {
                return $boxed;
            }
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

    /**
     * Resolve {@see __object__*} for compare lowering — native object, boxed script
     * global, or LLVM slot already storing {@see __value__*} (#32540).
     */
    private static function loadObjectPtr(Context $context, Variable $obj): Value
    {
        if (JitValueBox::isValueOperand($obj)) {
            return $context->builder->call(
                $context->lookupFunction('__value__readObject'),
                JitValueBox::valuePtrFromVariable($context, $obj)
            );
        }
        if (Variable::TYPE_OBJECT !== $obj->type) {
            throw new \LogicException(
                'loadObjectPtr expected object operand: '.Variable::getStringType($obj->type)
            );
        }
        $slotTy = $context->getStringFromType($obj->value->typeOf());
        if ('__value__*' === $slotTy) {
            $ptr = Variable::KIND_VARIABLE === $obj->kind
                ? $context->builder->load($obj->value)
                : $obj->value;

            return $context->builder->call(
                $context->lookupFunction('__value__readObject'),
                JitValueBox::normalizeValuePtr($context, $ptr)
            );
        }
        if ('__value__' === $slotTy) {
            return $context->builder->call(
                $context->lookupFunction('__value__readObject'),
                JitValueBox::normalizeValuePtr(
                    $context,
                    JitValueBox::pointer($context, $obj->value)
                )
            );
        }

        return $context->helper->loadValue($obj);
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
            // Thin-AOT nullable TYPE_OBJECT slots (e.g. documentElement) store a null
            // pointer when unset — treat as PHP null for === / !== (#32736).
            if (Variable::TYPE_NULL === $otherType || $other->isNullConstant) {
                return self::objectPtrComparedToNull($context, $opType, $obj);
            }

            return self::fromIdenticalConst($context, $opType, false);
        }
        // php-cfg types `null` as a __value__ box (TYPE_VALUE 134) with isNullConstant,
        // not TYPE_NULL 0 — leftover of #32503 (#32514).
        if (Variable::TYPE_NULL === $otherType || $other->isNullConstant) {
            if (self::isLooseEqualOp($opType)) {
                return self::objectPtrComparedToNull($context, $opType, $obj);
            }
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
            $objPtr = self::loadObjectPtr($context, $obj);
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
            $objPtr = self::loadObjectPtr($context, $obj);
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
     * {@code zend_compare} object VALUE box vs string VALUE box (#32540).
     *
     * Defer {@see __value__readString} until a stringable class match — script-global
     * string boxes SIGSEGV on eager read before stdClass fallback (#32540).
     */
    private static function objectVsStringBox(
        Context $context,
        int $opType,
        Value $objBoxPtr,
        Value $strBoxPtr,
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

        $readObj = $context->lookupFunction('__value__readObject');
        $objNative = $context->builder->call(
            $readObj,
            $context->builder->pointerCast($objBoxPtr, $readObj->getParam(0)->typeOf())
        );
        $obj = new Variable($context, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $objNative);
        $map = $context->structFieldMap['__object__'];
        $classId = $context->builder->load(
            $context->builder->structGep($objNative, $map['class_id'])
        );
        $i64 = $context->getTypeFromString('int64');
        $tag = 'unlike_obj_strbox_'.spl_object_id($context);
        $done = BasicBlockHelper::append($context, $tag.'_done');
        $incoming = [];
        $ids = array_keys($stringable);
        $lastIdx = \count($ids) - 1;
        $fallbackBlock = BasicBlockHelper::append($context, $tag.'_fallback');
        $readStr = $context->lookupFunction('__value__readString');

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
                $strNative = $context->builder->call(
                    $readStr,
                    $context->builder->pointerCast($strBoxPtr, $readStr->getParam(0)->typeOf())
                );
                $otherStr = $strNative;
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

        $objPtr = self::loadObjectPtr($context, $obj);
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
        // Native hashtable ⊙ boxed hashtable: zend_compare_arrays, not array-greater
        // (#32538). Dim-write locals are TYPE_VALUE boxes; `$m <=> ['a'=>1]` hits this
        // path with the literal on the native side (arrayOnLeft false → greater=-1).
        if (Variable::TYPE_HASHTABLE !== $array->type) {
            $liveCmp = $context->builder->select($useTruthy, $oriented, $greater);
            $liveEnd = $context->builder->getInsertBlock();
            $context->builder->branch($doneBlock);

            $context->builder->positionAtEnd($doneBlock);
            $phi = $context->builder->phi($i64, $tag.'_phi');
            $phi->addIncoming($nullPtrCmp, $nullPtrEnd);
            $phi->addIncoming($liveCmp, $liveEnd);

            return self::fromSpaceshipValue($context, $opType, $phi);
        }

        $isHt = self::valuePtrIsHashtable($context, $ptr);
        $truthyBlock = BasicBlockHelper::append($context, $tag.'_truthy');
        $htOrGreater = BasicBlockHelper::append($context, $tag.'_ht_or_gt');
        $htCmpBlock = BasicBlockHelper::append($context, $tag.'_ht_cmp');
        $greaterBlock = BasicBlockHelper::append($context, $tag.'_greater');
        $context->builder->branchIf($useTruthy, $truthyBlock, $htOrGreater);

        $context->builder->positionAtEnd($truthyBlock);
        $truthyEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($htOrGreater);
        $context->builder->branchIf($isHt, $htCmpBlock, $greaterBlock);

        $context->builder->positionAtEnd($htCmpBlock);
        \PHPCompiler\JIT\Builtin\SpaceshipRuntime::ensureLinked($context);
        $nativeHt = $context->helper->loadValue($array);
        $readHt = $context->lookupFunction('__value__readHashtable');
        $boxedHt = $context->builder->call(
            $readHt,
            $context->builder->pointerCast($ptr, $readHt->getParam(0)->typeOf())
        );
        $htCmp = $arrayOnLeft
            ? \PHPCompiler\JIT\Builtin\SpaceshipRuntime::callHashtableCompareSpaceship(
                $context,
                $nativeHt,
                $boxedHt
            )
            : \PHPCompiler\JIT\Builtin\SpaceshipRuntime::callHashtableCompareSpaceship(
                $context,
                $boxedHt,
                $nativeHt
            );
        $htEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($greaterBlock);
        $greaterEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($i64, $tag.'_phi');
        $phi->addIncoming($nullPtrCmp, $nullPtrEnd);
        $phi->addIncoming($oriented, $truthyEnd);
        $phi->addIncoming($htCmp, $htEnd);
        $phi->addIncoming($greater, $greaterEnd);

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
        $i8 = $context->getTypeFromString('int8');
        $leftKind = self::valuePtrKind($context, $leftPtr);
        $rightKind = self::valuePtrKind($context, $rightPtr);
        $objKind = $i8->constInt(Variable::TYPE_OBJECT & 0x7f, false);
        $strKind = $i8->constInt(Variable::TYPE_STRING & 0x7f, false);
        $leftIsObj = $context->builder->icmp(Builder::INT_EQ, $leftKind, $objKind);
        $rightIsObj = $context->builder->icmp(Builder::INT_EQ, $rightKind, $objKind);
        $leftIsStr = $context->builder->icmp(Builder::INT_EQ, $leftKind, $strKind);
        $rightIsStr = $context->builder->icmp(Builder::INT_EQ, $rightKind, $strKind);
        $objStrUnlike = $context->builder->or(
            $context->builder->and($leftIsObj, $rightIsStr),
            $context->builder->and($leftIsStr, $rightIsObj)
        );
        $longKind = $i8->constInt(Variable::TYPE_NATIVE_LONG & 0x7f, false);
        $leftIsLong = $context->builder->icmp(Builder::INT_EQ, $leftKind, $longKind);
        $rightIsLong = $context->builder->icmp(Builder::INT_EQ, $rightKind, $longKind);
        $objIntUnlike = $context->builder->or(
            $context->builder->and($leftIsObj, $rightIsLong),
            $context->builder->and($rightIsObj, $leftIsLong)
        );
        $tag = 'two_vbox_'.spl_object_id($context);
        $htBlock = BasicBlockHelper::append($context, $tag.'_ht');
        $preGenBlock = BasicBlockHelper::append($context, $tag.'_pre_gen');
        $unlikeBlock = BasicBlockHelper::append($context, $tag.'_obj_str');
        $objIntBlock = BasicBlockHelper::append($context, $tag.'_obj_int');
        $objIntDispatchBlock = BasicBlockHelper::append($context, $tag.'_obj_int_dispatch');
        $objIntLeftBlock = BasicBlockHelper::append($context, $tag.'_obj_int_l');
        $objIntRightBlock = BasicBlockHelper::append($context, $tag.'_obj_int_r');
        $genBlock = BasicBlockHelper::append($context, $tag.'_gen');
        $doneBlock = BasicBlockHelper::append($context, $tag.'_done');
        $context->builder->branchIf($eitherHt, $htBlock, $preGenBlock);

        $context->builder->positionAtEnd($htBlock);
        // zend_compare_arrays when BOTH are hashtables (#32536). The #32528
        // arrGreater shortcut is only for unlike kinds (array vs null/bool/other).
        $bothHt = $context->builder->and($leftHt, $rightHt);
        $htCmpBlock = BasicBlockHelper::append($context, $tag.'_ht_cmp');
        $htUnlikeBlock = BasicBlockHelper::append($context, $tag.'_ht_unlike');
        $context->builder->branchIf($bothHt, $htCmpBlock, $htUnlikeBlock);

        $context->builder->positionAtEnd($htCmpBlock);
        \PHPCompiler\JIT\Builtin\SpaceshipRuntime::ensureLinked($context);
        $readHt = $context->lookupFunction('__value__readHashtable');
        $leftHtPtr = $context->builder->call(
            $readHt,
            $context->builder->pointerCast($leftPtr, $readHt->getParam(0)->typeOf())
        );
        $rightHtPtr = $context->builder->call(
            $readHt,
            $context->builder->pointerCast($rightPtr, $readHt->getParam(0)->typeOf())
        );
        $htCmp = \PHPCompiler\JIT\Builtin\SpaceshipRuntime::callHashtableCompareSpaceship(
            $context,
            $leftHtPtr,
            $rightHtPtr
        );
        $htCmpEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($htUnlikeBlock);
        $htUnlikeEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($preGenBlock);
        $context->builder->branchIf($objStrUnlike, $unlikeBlock, $objIntBlock);

        $context->builder->positionAtEnd($unlikeBlock);
        $objOnLeft = $context->builder->and($leftIsObj, $rightIsStr);
        $unlikeCmp = $context->builder->select(
            $objOnLeft,
            $i64->constInt(1, true),
            $i64->constInt(-1, true)
        );
        $unlikeEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($objIntBlock);
        $context->builder->branchIf($objIntUnlike, $objIntDispatchBlock, $genBlock);

        $context->builder->positionAtEnd($objIntDispatchBlock);
        $objOnLeftInt = $context->builder->and($leftIsObj, $rightIsLong);
        $context->builder->branchIf($objOnLeftInt, $objIntLeftBlock, $objIntRightBlock);

        $context->builder->positionAtEnd($objIntLeftBlock);
        $leftObjPtr = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $leftPtr
        );
        $leftCoerced = JitScalarTypeCoerce::emitPlainObjectToScalar(
            $context,
            $leftObjPtr,
            'int',
            ErrorReporter::E_NOTICE
        );
        $rightLong = $context->builder->call(
            $context->lookupFunction('__value__readLong'),
            $rightPtr
        );
        $objIntCmp = self::longSpaceshipI64($context, $leftCoerced, $rightLong);
        $objIntLeftEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($objIntRightBlock);
        $rightObjPtr = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $rightPtr
        );
        $rightCoerced = JitScalarTypeCoerce::emitPlainObjectToScalar(
            $context,
            $rightObjPtr,
            'int',
            ErrorReporter::E_NOTICE
        );
        $leftLong = $context->builder->call(
            $context->lookupFunction('__value__readLong'),
            $leftPtr
        );
        $objIntCmpRight = self::negateCmp(
            $context,
            self::longSpaceshipI64($context, $rightCoerced, $leftLong)
        );
        $objIntRightEnd = $context->builder->getInsertBlock();
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
        $phi->addIncoming($htCmp, $htCmpEnd);
        $phi->addIncoming($arrPair, $htUnlikeEnd);
        $phi->addIncoming($unlikeCmp, $unlikeEnd);
        $phi->addIncoming($objIntCmp, $objIntLeftEnd);
        $phi->addIncoming($objIntCmpRight, $objIntRightEnd);
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

    /**
     * TYPE_OBJECT slot may hold a null pointer (unset documentElement) — compare as PHP null (#32736).
     */
    private static function objectPtrComparedToNull(Context $context, int $opType, Variable $obj): Variable
    {
        $ptr = self::loadObjectPtr($context, $obj);
        $objPtrTy = $context->getTypeFromString('__object__*');
        $isNull = $context->builder->icmp(Builder::INT_EQ, $ptr, $objPtrTy->constNull());
        $i1 = $context->getTypeFromString('int1');
        $bit = (OpCode::TYPE_IDENTICAL === $opType || OpCode::TYPE_EQUAL === $opType)
            ? $isNull
            : $context->builder->xor($isNull, $i1->constInt(1, false));

        return new Variable(
            $context,
            Variable::TYPE_NATIVE_BOOL,
            Variable::KIND_VALUE,
            $bit
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

    /**
     * Script-global {@code $o} / {@code $s} are both TYPE_VALUE boxes — dispatch by
     * tag; object-vs-string uses {@see objectVsString}, not {@see __value__spaceship}
     * (runtime SIGSEGV on two boxed operands, #32540).
     */
    public static function tryLowerBoxedPair(
        Context $context,
        int $opType,
        Variable $left,
        Variable $right
    ): ?Variable {
        if (
            !self::isCompareOp($opType)
            && !self::isLooseEqualOp($opType)
            && !self::isIdenticalOp($opType)
        ) {
            return null;
        }
        $leftPtr = JitValueBox::valuePtrFromVariable($context, $left);
        $rightPtr = JitValueBox::valuePtrFromVariable($context, $right);
        $i8 = $context->getTypeFromString('int8');
        $leftTag = self::valuePtrKind($context, $leftPtr);
        $rightTag = self::valuePtrKind($context, $rightPtr);
        $objKind = $i8->constInt(Variable::TYPE_OBJECT & 0x7f, false);
        $strKind = $i8->constInt(Variable::TYPE_STRING & 0x7f, false);
        $longKind = $i8->constInt(Variable::TYPE_NATIVE_LONG, false);
        $leftIsObj = $context->builder->icmp(
            Builder::INT_EQ,
            $leftTag,
            $objKind
        );
        $rightIsObj = $context->builder->icmp(
            Builder::INT_EQ,
            $rightTag,
            $objKind
        );
        $leftIsStr = $context->builder->icmp(
            Builder::INT_EQ,
            $leftTag,
            $strKind
        );
        $rightIsStr = $context->builder->icmp(
            Builder::INT_EQ,
            $rightTag,
            $strKind
        );
        $objVsStr = $context->builder->or(
            $context->builder->and($leftIsObj, $rightIsStr),
            $context->builder->and($rightIsObj, $leftIsStr)
        );
        $leftIsLong = $context->builder->icmp(
            Builder::INT_EQ,
            $leftTag,
            $longKind
        );
        $rightIsLong = $context->builder->icmp(
            Builder::INT_EQ,
            $rightTag,
            $longKind
        );
        $objVsLong = $context->builder->or(
            $context->builder->and($leftIsObj, $rightIsLong),
            $context->builder->and($rightIsObj, $leftIsLong)
        );
        $needsUnlike = $context->builder->or($objVsStr, $objVsLong);
        $bothStr = $context->builder->and($leftIsStr, $rightIsStr);
        $strVsLong = $context->builder->or(
            $context->builder->and($leftIsStr, $rightIsLong),
            $context->builder->and($leftIsLong, $rightIsStr)
        );
        $tag = 'vbox_pair_'.spl_object_id($context);
        $unlikeBb = BasicBlockHelper::append($context, $tag.'_unlike');
        $preGenBb = BasicBlockHelper::append($context, $tag.'_pre_gen');
        $bothStrBb = BasicBlockHelper::append($context, $tag.'_both_str');
        $genBb = BasicBlockHelper::append($context, $tag.'_gen');
        $doneBb = BasicBlockHelper::append($context, $tag.'_done');
        $i64 = $context->getTypeFromString('int64');
        $i1 = $context->getTypeFromString('int1');
        $context->builder->branchIf($needsUnlike, $unlikeBb, $preGenBb);

        $bothStrEnd = null;
        $bothStrVal = null;
        $strLongEnd = null;
        $strLongVal = null;
        if (self::isLooseEqualOp($opType)) {
            // Two boxed IS_STRING operands: strcmp + numeric-string (Zend compare_function).
            // __value__spaceship can read stale after switch JUMPIF chains (#33800).
            // Boxed numeric-string == boxed long must not rely on __value__spaceship
            // (#35313) — use looseEqualStringToNativeLong (peer #35220 string×native long).
            $strLongCheck = BasicBlockHelper::append($context, $tag.'_str_long_check');
            $strLongBb = BasicBlockHelper::append($context, $tag.'_str_long');
            $context->builder->positionAtEnd($preGenBb);
            $context->builder->branchIf($bothStr, $bothStrBb, $strLongCheck);

            $context->builder->positionAtEnd($strLongCheck);
            $context->builder->branchIf($strVsLong, $strLongBb, $genBb);

            $context->builder->positionAtEnd($bothStrBb);
            $readStr = $context->lookupFunction('__value__readString');
            $leftStrNative = $context->builder->call(
                $readStr,
                $context->builder->pointerCast($leftPtr, $readStr->getParam(0)->typeOf())
            );
            $rightStrNative = $context->builder->call(
                $readStr,
                $context->builder->pointerCast($rightPtr, $readStr->getParam(0)->typeOf())
            );
            $eq = \PHPCompiler\VM\VmValueCompare::looseEqualStringToString(
                $context,
                $leftStrNative,
                $rightStrNative
            );
            $bothStrVal = OpCode::TYPE_NOT_EQUAL === $opType
                ? $context->builder->xor($eq, $i1->constInt(1, false))
                : $eq;
            $bothStrEnd = $context->builder->getInsertBlock();
            $context->builder->branch($doneBb);

            $context->builder->positionAtEnd($strLongBb);
            $readStrFn = $context->lookupFunction('__value__readString');
            $readLongFn = $context->lookupFunction('__value__readLong');
            $strLeftBb = BasicBlockHelper::append($context, $tag.'_eq_str_left');
            $strRightBb = BasicBlockHelper::append($context, $tag.'_eq_str_right');
            $strLongJoin = BasicBlockHelper::append($context, $tag.'_eq_str_long_join');
            $context->builder->branchIf($leftIsStr, $strLeftBb, $strRightBb);

            $context->builder->positionAtEnd($strLeftBb);
            $strL = $context->builder->call(
                $readStrFn,
                $context->builder->pointerCast($leftPtr, $readStrFn->getParam(0)->typeOf())
            );
            $longR = $context->builder->call(
                $readLongFn,
                $context->builder->pointerCast($rightPtr, $readLongFn->getParam(0)->typeOf())
            );
            $eqL = \PHPCompiler\VM\VmValueCompare::looseEqualStringToNativeLong($context, $strL, $longR);
            $strLeftEnd = $context->builder->getInsertBlock();
            $context->builder->branch($strLongJoin);

            $context->builder->positionAtEnd($strRightBb);
            $longL = $context->builder->call(
                $readLongFn,
                $context->builder->pointerCast($leftPtr, $readLongFn->getParam(0)->typeOf())
            );
            $strR = $context->builder->call(
                $readStrFn,
                $context->builder->pointerCast($rightPtr, $readStrFn->getParam(0)->typeOf())
            );
            $eqR = \PHPCompiler\VM\VmValueCompare::looseEqualStringToNativeLong($context, $strR, $longL);
            $strRightEnd = $context->builder->getInsertBlock();
            $context->builder->branch($strLongJoin);

            $context->builder->positionAtEnd($strLongJoin);
            $eqPhi = $context->builder->phi($i1, $tag.'_eq_str_long_phi');
            $eqPhi->addIncoming($eqL, $strLeftEnd);
            $eqPhi->addIncoming($eqR, $strRightEnd);
            $strLongVal = OpCode::TYPE_NOT_EQUAL === $opType
                ? $context->builder->xor($eqPhi, $i1->constInt(1, false))
                : $eqPhi;
            $strLongEnd = $context->builder->getInsertBlock();
            $context->builder->branch($doneBb);
        } elseif (OpCode::TYPE_SPACESHIP === $opType) {
            // Boxed numeric-string <=> boxed long — bypass NestedJIT spaceshipNumberString (#35317).
            $strLongBb = BasicBlockHelper::append($context, $tag.'_sp_str_long');
            $context->builder->positionAtEnd($preGenBb);
            $context->builder->branchIf($strVsLong, $strLongBb, $genBb);

            $context->builder->positionAtEnd($strLongBb);
            $readStrFn = $context->lookupFunction('__value__readString');
            $readLongFn = $context->lookupFunction('__value__readLong');
            $strLeftBb = BasicBlockHelper::append($context, $tag.'_sp_str_left');
            $strRightBb = BasicBlockHelper::append($context, $tag.'_sp_str_right');
            $strLongJoin = BasicBlockHelper::append($context, $tag.'_sp_str_long_join');
            $context->builder->branchIf($leftIsStr, $strLeftBb, $strRightBb);

            $context->builder->positionAtEnd($strLeftBb);
            $strL = $context->builder->call(
                $readStrFn,
                $context->builder->pointerCast($leftPtr, $readStrFn->getParam(0)->typeOf())
            );
            $longR = $context->builder->call(
                $readLongFn,
                $context->builder->pointerCast($rightPtr, $readLongFn->getParam(0)->typeOf())
            );
            $cmpL = \PHPCompiler\VM\VmValueCompare::spaceshipStringToNativeLong($context, $strL, $longR);
            $strLeftEnd = $context->builder->getInsertBlock();
            $context->builder->branch($strLongJoin);

            $context->builder->positionAtEnd($strRightBb);
            $longL = $context->builder->call(
                $readLongFn,
                $context->builder->pointerCast($leftPtr, $readLongFn->getParam(0)->typeOf())
            );
            $strR = $context->builder->call(
                $readStrFn,
                $context->builder->pointerCast($rightPtr, $readStrFn->getParam(0)->typeOf())
            );
            $cmpR = $context->builder->sub(
                $i64->constInt(0, false),
                \PHPCompiler\VM\VmValueCompare::spaceshipStringToNativeLong($context, $strR, $longL)
            );
            $strRightEnd = $context->builder->getInsertBlock();
            $context->builder->branch($strLongJoin);

            $context->builder->positionAtEnd($strLongJoin);
            $cmpPhi = $context->builder->phi($i64, $tag.'_sp_str_long_phi');
            $cmpPhi->addIncoming($cmpL, $strLeftEnd);
            $cmpPhi->addIncoming($cmpR, $strRightEnd);
            $strLongVal = $cmpPhi;
            $strLongEnd = $context->builder->getInsertBlock();
            $context->builder->branch($doneBb);
        } elseif (self::isCompareOp($opType)) {
            // Ordered < > <= >= boxed str×long — same numeric path as #35317 (#35320).
            $strLongBb = BasicBlockHelper::append($context, $tag.'_ord_str_long');
            $context->builder->positionAtEnd($preGenBb);
            $context->builder->branchIf($strVsLong, $strLongBb, $genBb);

            $context->builder->positionAtEnd($strLongBb);
            $readStrFn = $context->lookupFunction('__value__readString');
            $readLongFn = $context->lookupFunction('__value__readLong');
            $strLeftBb = BasicBlockHelper::append($context, $tag.'_ord_str_left');
            $strRightBb = BasicBlockHelper::append($context, $tag.'_ord_str_right');
            $strLongJoin = BasicBlockHelper::append($context, $tag.'_ord_str_long_join');
            $context->builder->branchIf($leftIsStr, $strLeftBb, $strRightBb);

            $context->builder->positionAtEnd($strLeftBb);
            $strL = $context->builder->call(
                $readStrFn,
                $context->builder->pointerCast($leftPtr, $readStrFn->getParam(0)->typeOf())
            );
            $longR = $context->builder->call(
                $readLongFn,
                $context->builder->pointerCast($rightPtr, $readLongFn->getParam(0)->typeOf())
            );
            $cmpL = \PHPCompiler\VM\VmValueCompare::spaceshipStringToNativeLong($context, $strL, $longR);
            $boolL = \PHPCompiler\VM\VmValueCompare::boolFromSpaceshipCmp($context, $opType, $cmpL);
            $strLeftEnd = $context->builder->getInsertBlock();
            $context->builder->branch($strLongJoin);

            $context->builder->positionAtEnd($strRightBb);
            $longL = $context->builder->call(
                $readLongFn,
                $context->builder->pointerCast($leftPtr, $readLongFn->getParam(0)->typeOf())
            );
            $strR = $context->builder->call(
                $readStrFn,
                $context->builder->pointerCast($rightPtr, $readStrFn->getParam(0)->typeOf())
            );
            $cmpR = $context->builder->sub(
                $i64->constInt(0, false),
                \PHPCompiler\VM\VmValueCompare::spaceshipStringToNativeLong($context, $strR, $longL)
            );
            $boolR = \PHPCompiler\VM\VmValueCompare::boolFromSpaceshipCmp($context, $opType, $cmpR);
            $strRightEnd = $context->builder->getInsertBlock();
            $context->builder->branch($strLongJoin);

            $context->builder->positionAtEnd($strLongJoin);
            $boolPhi = $context->builder->phi($i1, $tag.'_ord_str_long_phi');
            $boolPhi->addIncoming($boolL, $strLeftEnd);
            $boolPhi->addIncoming($boolR, $strRightEnd);
            $strLongVal = $boolPhi;
            $strLongEnd = $context->builder->getInsertBlock();
            $context->builder->branch($doneBb);
        } else {
            $context->builder->positionAtEnd($preGenBb);
            $context->builder->branch($genBb);
        }

        $context->builder->positionAtEnd($unlikeBb);
        if (self::isIdenticalOp($opType)) {
            $unlikeVal = self::fromIdenticalConst($context, $opType, false)->value;
        } else {
            $readObj = $context->lookupFunction('__value__readObject');
            $readLong = $context->lookupFunction('__value__readLong');
            $strCaseBb = BasicBlockHelper::append($context, $tag.'_obj_str');
            $longCaseBb = BasicBlockHelper::append($context, $tag.'_obj_long');
            $unlikeJoinBb = BasicBlockHelper::append($context, $tag.'_unlike_join');
            $joinTy = OpCode::TYPE_SPACESHIP === $opType ? $i64 : $i1;
            $context->builder->branchIf($objVsStr, $strCaseBb, $longCaseBb);

            $context->builder->positionAtEnd($strCaseBb);
            $strLeftBb = BasicBlockHelper::append($context, $tag.'_str_left');
            $strRightBb = BasicBlockHelper::append($context, $tag.'_str_right');
            $strJoinBb = BasicBlockHelper::append($context, $tag.'_str_join');
            $context->builder->branchIf($leftIsObj, $strLeftBb, $strRightBb);

            $context->builder->positionAtEnd($strLeftBb);
            $strLeftOutVar = self::objectVsStringBox(
                $context,
                $opType,
                $leftPtr,
                $rightPtr,
                true
            );
            $strLeftOut = $strLeftOutVar->value;
            $strLeftEnd = $context->builder->getInsertBlock();
            $context->builder->branch($strJoinBb);

            $context->builder->positionAtEnd($strRightBb);
            $strRightOutVar = self::objectVsStringBox(
                $context,
                $opType,
                $rightPtr,
                $leftPtr,
                false
            );
            $strRightOut = $strRightOutVar->value;
            $strRightEnd = $context->builder->getInsertBlock();
            $context->builder->branch($strJoinBb);

            $context->builder->positionAtEnd($strJoinBb);
            $strCasePhi = $context->builder->phi($joinTy, $tag.'_str_phi');
            $strCasePhi->addIncoming($strLeftOut, $strLeftEnd);
            $strCasePhi->addIncoming($strRightOut, $strRightEnd);
            $strCaseOut = $strCasePhi;
            $strCaseEnd = $context->builder->getInsertBlock();
            $context->builder->branch($unlikeJoinBb);

            $context->builder->positionAtEnd($longCaseBb);
            $longLeftBb = BasicBlockHelper::append($context, $tag.'_long_left');
            $longRightBb = BasicBlockHelper::append($context, $tag.'_long_right');
            $longJoinBb = BasicBlockHelper::append($context, $tag.'_long_join');
            $context->builder->branchIf($leftIsObj, $longLeftBb, $longRightBb);
            $context->builder->positionAtEnd($longLeftBb);
            $longLeftOut = self::objectVsOther(
                $context,
                $opType,
                new Variable(
                    $context,
                    Variable::TYPE_OBJECT,
                    Variable::KIND_VALUE,
                    $context->builder->call(
                        $readObj,
                        $context->builder->pointerCast($leftPtr, $readObj->getParam(0)->typeOf())
                    )
                ),
                new Variable(
                    $context,
                    Variable::TYPE_NATIVE_LONG,
                    Variable::KIND_VALUE,
                    $context->builder->call(
                        $readLong,
                        $context->builder->pointerCast($rightPtr, $readLong->getParam(0)->typeOf())
                    )
                ),
                true
            );
            if (null === $longLeftOut) {
                $longLeftOut = self::fromSpaceshipConst($context, $opType, 0);
            }
            $longLeftEnd = $context->builder->getInsertBlock();
            $context->builder->branch($longJoinBb);
            $context->builder->positionAtEnd($longRightBb);
            $longRightOut = self::objectVsOther(
                $context,
                $opType,
                new Variable(
                    $context,
                    Variable::TYPE_OBJECT,
                    Variable::KIND_VALUE,
                    $context->builder->call(
                        $readObj,
                        $context->builder->pointerCast($rightPtr, $readObj->getParam(0)->typeOf())
                    )
                ),
                new Variable(
                    $context,
                    Variable::TYPE_NATIVE_LONG,
                    Variable::KIND_VALUE,
                    $context->builder->call(
                        $readLong,
                        $context->builder->pointerCast($leftPtr, $readLong->getParam(0)->typeOf())
                    )
                ),
                false
            );
            if (null === $longRightOut) {
                $longRightOut = self::fromSpaceshipConst($context, $opType, 0);
            }
            $longRightEnd = $context->builder->getInsertBlock();
            $context->builder->branch($longJoinBb);
            $context->builder->positionAtEnd($longJoinBb);
            $longPhi = $context->builder->phi($joinTy, $tag.'_long_phi');
            $longPhi->addIncoming($longLeftOut->value, $longLeftEnd);
            $longPhi->addIncoming($longRightOut->value, $longRightEnd);
            $longCaseEnd = $context->builder->getInsertBlock();
            $context->builder->branch($unlikeJoinBb);

            $context->builder->positionAtEnd($unlikeJoinBb);
            $unlikePhi = $context->builder->phi($joinTy, $tag.'_unlike_phi');
            $unlikePhi->addIncoming($strCaseOut, $strCaseEnd);
            $unlikePhi->addIncoming($longPhi, $longCaseEnd);
            $unlikeVal = $unlikePhi;
        }
        $unlikeEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($genBb);
        \PHPCompiler\JIT\Builtin\SpaceshipRuntime::ensureLinked($context);
        $genericCmp = \PHPCompiler\JIT\Builtin\SpaceshipRuntime::callValueSpaceship(
            $context,
            $leftPtr,
            $rightPtr
        );
        if (OpCode::TYPE_SPACESHIP === $opType) {
            $genVal = $genericCmp;
        } elseif (self::isLooseEqualOp($opType)) {
            $zero = $genericCmp->typeOf()->constInt(0, false);
            $eq = $context->builder->icmp(Builder::INT_EQ, $genericCmp, $zero);
            $genVal = OpCode::TYPE_EQUAL === $opType
                ? $eq
                : $context->builder->xor($eq, $i1->constInt(1, false));
        } elseif (self::isCompareOp($opType)) {
            // Boxed hashtable < > <= >= : zend_compare_arrays / zend_is_true vs
            // null/bool (#32538 leftover of #32536). Do not use === (both
            // directions false) or raw __value__spaceship (array always greater
            // than null, so `[] > null` becomes true). Master #32543 used
            // boolFromSpaceshipCmp(__value__spaceship) which regresses `$e > $n`.
            $genVal = self::twoBoxedOrdered($context, $opType, $left, $right)->value;
        } else {
            $genVal = OpCode::TYPE_NOT_IDENTICAL === $opType
                ? JitValueCompare::notIdenticalValueToValue($context, $left, $right)
                : JitValueCompare::identicalValueToValue($context, $left, $right);
        }
        $genEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        if (OpCode::TYPE_SPACESHIP === $opType) {
            $phi = $context->builder->phi($i64, $tag.'_done_phi');
            $phi->addIncoming($unlikeVal, $unlikeEnd);
            if (null !== $strLongEnd && null !== $strLongVal) {
                $phi->addIncoming($strLongVal, $strLongEnd);
            }
            $phi->addIncoming($genVal, $genEnd);

            return new Variable($context, Variable::TYPE_NATIVE_LONG, Variable::KIND_VALUE, $phi);
        }
        $phi = $context->builder->phi($i1, $tag.'_done_phi');
        $phi->addIncoming($unlikeVal, $unlikeEnd);
        if (null !== $bothStrEnd && null !== $bothStrVal) {
            $phi->addIncoming($bothStrVal, $bothStrEnd);
        }
        if (null !== $strLongEnd && null !== $strLongVal) {
            $phi->addIncoming($strLongVal, $strLongEnd);
        }
        $phi->addIncoming($genVal, $genEnd);

        return new Variable($context, Variable::TYPE_NATIVE_BOOL, Variable::KIND_VALUE, $phi);
    }
}
