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
 * plain object vs number is E_NOTICE + legacy 1; array vs non-array is ±1.
 * Object vs string without {@code __toString} is object-greater (#32514);
 * {@code null} literals are TYPE_VALUE + isNullConstant, not TYPE_NULL.
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
        if (!self::isCompareOp($opType)) {
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
        if ($leftArr || $rightArr) {
            return self::arrayVsScalar($context, $opType, $leftArr);
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

    private static function arrayVsScalar(Context $context, int $opType, bool $arrayOnLeft): Variable
    {
        // zend_compare: IS_ARRAY vs non-array → array is greater (#32503).
        return self::fromSpaceshipConst($context, $opType, $arrayOnLeft ? 1 : -1);
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
