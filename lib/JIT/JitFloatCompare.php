<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\OpCode;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Zend zend_operators.c parity for native double compares (#4712, #5084).
 *
 * IEEE ordered fcmp yields 0 for NaN <=> NaN; PHP returns 1. Relational ops with NaN are false.
 */
final class JitFloatCompare
{
    private static function eitherOperandIsNaN(Context $context, Value $left, Value $right): Value
    {
        $isnan = $context->lookupFunction('isnan');
        $i32 = $context->getTypeFromString('int32');
        $zero = $i32->constInt(0, false);
        $leftNan = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->call($isnan, $left),
            $zero
        );
        $rightNan = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->call($isnan, $right),
            $zero
        );

        return $context->builder->or($leftNan, $rightNan);
    }

    public static function relationalCompare(
        Context $context,
        int $opType,
        Value $left,
        Value $right
    ): Value {
        $builder = $context->builder;
        $i1 = $context->getTypeFromString('int1');
        $falseVal = $i1->constInt(0, false);

        switch ($opType) {
            case OpCode::TYPE_GREATER_OR_EQUAL:
                $ordered = $builder->fcmp(Builder::REAL_OGE, $left, $right);
                break;
            case OpCode::TYPE_SMALLER_OR_EQUAL:
                $ordered = $builder->fcmp(Builder::REAL_OLE, $left, $right);
                break;
            case OpCode::TYPE_GREATER:
                $ordered = $builder->fcmp(Builder::REAL_OGT, $left, $right);
                break;
            case OpCode::TYPE_SMALLER:
                $ordered = $builder->fcmp(Builder::REAL_OLT, $left, $right);
                break;
            case OpCode::TYPE_IDENTICAL:
            case OpCode::TYPE_EQUAL:
                $ordered = $builder->fcmp(Builder::REAL_OEQ, $left, $right);

                return $builder->select(self::eitherOperandIsNaN($context, $left, $right), $falseVal, $ordered);
            case OpCode::TYPE_NOT_IDENTICAL:
            case OpCode::TYPE_NOT_EQUAL:
                return $builder->fcmp(Builder::REAL_ONE, $left, $right);
            default:
                throw new \LogicException('Unsupported float relational opcode: '.$opType);
        }

        return $builder->select(self::eitherOperandIsNaN($context, $left, $right), $falseVal, $ordered);
    }

    public static function spaceship(Context $context, Value $left, Value $right): Value
    {
        $builder = $context->builder;
        $i64 = $context->getTypeFromString('int64');
        $negOne = $i64->constInt(-1, true);
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, true);
        $lt = $builder->fcmp(Builder::REAL_OLT, $left, $right);
        $gt = $builder->fcmp(Builder::REAL_OGT, $left, $right);
        $ordered = $builder->select($gt, $one, $builder->select($lt, $negOne, $zero));

        return $builder->select(self::eitherOperandIsNaN($context, $left, $right), $one, $ordered);
    }
}
