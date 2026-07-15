<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Lowered into JIT/AOT modules for spaceship (<=>) on runtime values (#9381, #9476, php-in-PHP).
 *
 * php-src: Zend/zend_operators.c — compare_function, zend_compare_arrays, zend_compare_objects
 * SSOT: {@see Variable::spaceshipCompare()}, {@see ObjectEntry::compareSpaceship()}, {@see HashTable::compareSpaceship()}
 */
final class CompareJitHelper
{
    /** @return int -1, 0, or 1 (LLVM i64 ABI) */
    public static function longSpaceship(int $left, int $right): int
    {
        return self::spaceshipNumeric($left, $right);
    }

    /** @return int -1, 0, or 1 (LLVM i64 ABI) */
    public static function doubleSpaceship(float $left, float $right): int
    {
        return self::spaceshipNumeric($left, $right);
    }

    /** @return int -1, 0, or 1 (LLVM i64 ABI) */
    public static function stringSpaceship(string $left, string $right): int
    {
        $cmp = strcmp($left, $right);

        return $cmp < 0 ? -1 : ($cmp > 0 ? 1 : 0);
    }

    /** @param int $numOnLeft 1 when the numeric operand is on the left, 0 otherwise */
    public static function spaceshipNumberString(float $num, string $str, int $numOnLeft): int
    {
        if ('' === $str) {
            return 0 !== $numOnLeft ? 1 : -1;
        }
        if (is_numeric($str)) {
            $parsed = str_contains($str, '.') ? (float) $str : (int) $str;
            $cmp = self::spaceshipNumeric($num, $parsed);

            return 0 !== $numOnLeft ? $cmp : -$cmp;
        }

        return 0 !== $numOnLeft ? -1 : 1;
    }

    /** Compare boxed-value type tags when kinds differ (Zend type ordering). */
    public static function kindSpaceship(int $leftKind, int $rightKind): int
    {
        return self::longSpaceship($leftKind, $rightKind);
    }

    /** @return int -1, 0, or 1 (LLVM i64 ABI) */
    public static function objectSpaceship(ObjectEntry $left, ObjectEntry $right): int
    {
        if (EnumCaseSupport::isEnumCase($left) && EnumCaseSupport::isEnumCase($right)) {
            return EnumCaseSupport::compareSpaceship($left, $right);
        }
        if ($left === $right) {
            return 0;
        }
        if ($left->class->name !== $right->class->name) {
            return 1;
        }
        $names = array_keys($left->properties);
        foreach (array_keys($right->properties) as $name) {
            if (!\in_array($name, $names, true)) {
                $names[] = $name;
            }
        }
        foreach ($names as $name) {
            $leftVar = isset($left->properties[$name])
                ? $left->properties[$name]->resolveIndirect()
                : new Variable(Variable::TYPE_NULL);
            $rightVar = isset($right->properties[$name])
                ? $right->properties[$name]->resolveIndirect()
                : new Variable(Variable::TYPE_NULL);
            $cmp = self::boxedSpaceship($leftVar, $rightVar);
            if (0 !== $cmp) {
                return $cmp;
            }
        }

        return 0;
    }

    /** @return int -1, 0, or 1 (LLVM i64 ABI) */
    public static function hashtableSpaceship(HashTable $left, HashTable $right): int
    {
        $leftCount = $left->getNumElements();
        $rightCount = $right->getNumElements();
        if ($leftCount > $rightCount) {
            return 1;
        }
        if ($leftCount < $rightCount) {
            return -1;
        }

        $leftItems = iterator_to_array($left->iterateKeyed(true));
        $rightItems = iterator_to_array($right->iterateKeyed(true));
        for ($i = 0, $n = \count($leftItems); $i < $n; ++$i) {
            [$leftKey, $leftVal] = $leftItems[$i];
            [$rightKey, $rightVal] = $rightItems[$i];
            $keyCmp = self::boxedSpaceship($leftKey->resolveIndirect(), $rightKey->resolveIndirect());
            if (0 !== $keyCmp) {
                return $keyCmp;
            }
            $valCmp = self::boxedSpaceship($leftVal->resolveIndirect(), $rightVal->resolveIndirect());
            if (0 !== $valCmp) {
                return $valCmp;
            }
        }

        return 0;
    }

    /**
     * Spaceship on resolved Variables without instance compareSpaceship() calls (#19048 nested JIT).
     */
    private static function boxedSpaceship(Variable $left, Variable $right): int
    {
        $left = $left->resolveIndirect();
        $right = $right->resolveIndirect();
        if ($left->type === $right->type) {
            if (Variable::TYPE_OBJECT === $left->type) {
                return self::objectSpaceship($left->toObject(), $right->toObject());
            }
            if (Variable::TYPE_ARRAY === $left->type) {
                return self::hashtableSpaceship($left->toArray(), $right->toArray());
            }
            if (Variable::TYPE_INTEGER === $left->type) {
                return self::longSpaceship($left->toInt(), $right->toInt());
            }
            if (Variable::TYPE_FLOAT === $left->type) {
                return self::doubleSpaceship($left->toFloat(), $right->toFloat());
            }
            if (Variable::TYPE_STRING === $left->type) {
                return self::stringSpaceship($left->toString(), $right->toString());
            }
            if (Variable::TYPE_BOOLEAN === $left->type) {
                return self::longSpaceship((int) $left->toBool(), (int) $right->toBool());
            }
            if (Variable::TYPE_NULL === $left->type) {
                return 0;
            }
        }

        return self::zendUnlikeValueSpaceship($left, $right);
    }

    /**
     * Zend zend_compare() for unlike kinds involving array/object/null (#12033, zend_operators.c).
     *
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
                $cmp = self::longSpaceship($casted, (int) $bool->bool);

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

    /** @param int|float $left */
    private static function spaceshipNumeric(int|float $left, int|float $right): int
    {
        if ((\is_float($left) && \is_nan($left)) || (\is_float($right) && \is_nan($right))) {
            return 1;
        }
        if ($left < $right) {
            return -1;
        }
        if ($left > $right) {
            return 1;
        }

        return 0;
    }
}
