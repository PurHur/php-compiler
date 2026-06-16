<?php

declare(strict_types=1);

namespace PHPCompiler\Compiler;

use PHPCfg\Op;
use PHPCfg\Operand;
use PHPCfg\Operand\Temporary;
use PHPCfg\Type;
use PHPCompiler\VM\Variable;

/**
 * Fold compile-time class/trait constant scalar values for static checks (#8882, zend_compile.c).
 */
final class ClassConstValueFold
{
    public static function fold(Op\Terminal\Const_ $const): ?Variable
    {
        $vm = self::fromLiteralOperand($const->value);
        if (null !== $vm) {
            return $vm;
        }
        if (null === $const->valueBlock || [] === $const->valueBlock->children) {
            return null;
        }
        if (1 !== \count($const->valueBlock->children)) {
            return null;
        }
        $child = $const->valueBlock->children[0];
        if ($child instanceof Op\Expr\UnaryMinus) {
            $inner = self::fromLiteralOperand($child->expr);
            if (null !== $inner && $inner->is(Variable::TYPE_INTEGER)) {
                $neg = new Variable();
                $neg->int(-$inner->toInt());

                return $neg;
            }
            if (null !== $inner && $inner->is(Variable::TYPE_FLOAT)) {
                $neg = new Variable();
                $neg->float(-$inner->toFloat());

                return $neg;
            }
        }

        return self::fromLiteralOperand($child->result ?? null);
    }

    public static function identical(?Variable $left, ?Variable $right): bool
    {
        if (null === $left || null === $right) {
            return false;
        }
        $a = new Variable();
        $a->copyFrom($left);
        $b = new Variable();
        $b->copyFrom($right);

        return $a->identicalTo($b);
    }

    private static function fromLiteralOperand(?Operand $operand): ?Variable
    {
        if (null === $operand) {
            return null;
        }
        $literal = self::unwrapLiteralOperand($operand);
        if (null === $literal) {
            return null;
        }
        $mappedType = Variable::mapFromType($literal->type ?? Type::mixed());
        if (Variable::TYPE_UNDEFINED === $mappedType) {
            if (\is_int($literal->value)) {
                $mappedType = Variable::TYPE_INTEGER;
            } elseif (\is_float($literal->value)) {
                $mappedType = Variable::TYPE_FLOAT;
            } elseif (\is_string($literal->value)) {
                $mappedType = Variable::TYPE_STRING;
            } elseif (\is_bool($literal->value)) {
                $mappedType = Variable::TYPE_BOOLEAN;
            } elseif (null === $literal->value) {
                $mappedType = Variable::TYPE_NULL;
            }
        }
        $return = new Variable($mappedType);
        switch ($mappedType) {
            case Variable::TYPE_STRING:
                $return->string($literal->value);
                break;
            case Variable::TYPE_INTEGER:
                $return->int($literal->value);
                break;
            case Variable::TYPE_FLOAT:
                $return->float($literal->value);
                break;
            case Variable::TYPE_BOOLEAN:
                $return->bool($literal->value);
                break;
            case Variable::TYPE_NULL:
                break;
            default:
                return null;
        }

        return $return;
    }

    private static function unwrapLiteralOperand(Operand $operand): ?Operand\Literal
    {
        while ($operand instanceof Temporary && null !== $operand->original) {
            $operand = $operand->original;
        }

        return $operand instanceof Operand\Literal ? $operand : null;
    }
}
