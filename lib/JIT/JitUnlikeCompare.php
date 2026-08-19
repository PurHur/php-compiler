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
        if (Variable::TYPE_NULL === $otherType) {
            $cmpConst = $objectOnLeft ? 1 : -1;

            return self::fromSpaceshipConst($context, $opType, $cmpConst);
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
