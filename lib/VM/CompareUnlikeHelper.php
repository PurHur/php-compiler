<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Zend zend_compare() for unlike kinds involving array/object/null (#12033).
 *
 * Kept out of {@see CompareJitHelper} so NestedJIT spaceship compile (#9381 / #21109)
 * does not lower this VM-only path into the JIT module.
 *
 * php-src: Zend/zend_operators.c — compare_function
 * SSOT consumers: {@see Variable::spaceshipOp()}
 */
final class CompareUnlikeHelper
{
    /**
     * VM SSOT for relational < > <= >= and spaceship when {@see Variable::spaceshipMixedScalars()}
     * would coerce through toNumeric() and throw on array/object operands.
     */
    public static function zendUnlikeValueSpaceship(
        Variable $left,
        Variable $right,
        ?\PHPCompiler\VM $vm = null
    ): int {
        $left = $left->resolveIndirect();
        $right = $right->resolveIndirect();

        if (Variable::TYPE_ARRAY === $left->type && Variable::TYPE_OBJECT === $right->type) {
            return -1;
        }
        if (Variable::TYPE_OBJECT === $left->type && Variable::TYPE_ARRAY === $right->type) {
            return 1;
        }

        if (Variable::TYPE_OBJECT === $left->type && Variable::TYPE_NULL === $right->type) {
            return 1;
        }
        if (Variable::TYPE_NULL === $left->type && Variable::TYPE_OBJECT === $right->type) {
            return -1;
        }

        if (Variable::TYPE_OBJECT === $left->type || Variable::TYPE_OBJECT === $right->type) {
            if (Variable::TYPE_BOOLEAN === $left->type || Variable::TYPE_BOOLEAN === $right->type) {
                $object = Variable::TYPE_OBJECT === $left->type ? $left : $right;
                $bool = Variable::TYPE_BOOLEAN === $left->type ? $left : $right;
                $casted = (int) $object->toBool();
                $cmp = CompareJitHelper::longSpaceship($casted, (int) $bool->bool);

                return Variable::TYPE_OBJECT === $left->type ? $cmp : -$cmp;
            }
            if (Variable::TYPE_STRING === $left->type || Variable::TYPE_STRING === $right->type) {
                $stringableCmp = CompareStringableHelper::spaceship($vm, $left, $right);
                if (null !== $stringableCmp) {
                    return $stringableCmp;
                }
            }

            return Variable::TYPE_OBJECT === $left->type ? 1 : -1;
        }

        if (Variable::TYPE_ARRAY === $left->type || Variable::TYPE_ARRAY === $right->type) {
            if (Variable::TYPE_NULL === $left->type || Variable::TYPE_NULL === $right->type) {
                $array = Variable::TYPE_ARRAY === $left->type ? $left : $right;
                $nullOnLeft = Variable::TYPE_NULL === $left->type;

                return self::zendIsTrue($array) ? ($nullOnLeft ? -1 : 1) : 0;
            }
            if (Variable::TYPE_BOOLEAN === $left->type || Variable::TYPE_BOOLEAN === $right->type) {
                $array = Variable::TYPE_ARRAY === $left->type ? $left : $right;
                $bool = Variable::TYPE_BOOLEAN === $left->type ? $left : $right;
                $arrayTruthy = self::zendIsTrue($array);
                $boolVal = (int) $bool->bool;
                if ($arrayTruthy === (bool) $boolVal) {
                    return 0;
                }

                return Variable::TYPE_ARRAY === $left->type
                    ? ($arrayTruthy ? 1 : -1)
                    : ($arrayTruthy ? -1 : 1);
            }

            return Variable::TYPE_ARRAY === $left->type ? 1 : -1;
        }

        throw new \LogicException('zendUnlikeValueSpaceship requires array/object/null unlike-kind operands');
    }

    /** Zend zend_is_true() for compare tail — empty array is false (#12033). */
    private static function zendIsTrue(Variable $var): bool
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_ARRAY === $var->type) {
            return 0 !== $var->toArray()->getNumElements();
        }

        return $var->toBool();
    }
}
