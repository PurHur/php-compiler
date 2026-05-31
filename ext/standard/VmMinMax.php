<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/**
 * min()/max() — array and variadic scalar paths (php-src ext/standard/array.c php_min_max).
 */
final class VmMinMax
{
    public static function min(Frame $frame): void
    {
        self::reduce($frame, 'min', true);
    }

    public static function max(Frame $frame): void
    {
        self::reduce($frame, 'max', false);
    }

    private static function reduce(Frame $frame, string $name, bool $pickMin): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1) {
            throw new \ArgumentCountError($name.'() expects at least 1 argument, 0 given');
        }

        $values = self::collectValues($frame, $name, $argc);
        if ([] === $values) {
            throw new \ValueError(
                $name.'(): Argument #1 ($value) must contain at least one element'
            );
        }

        if (null === $frame->returnVar) {
            return;
        }

        $bestInt = null;
        $bestFloat = null;
        $useFloat = false;

        foreach ($values as $v) {
            if (Variable::TYPE_INTEGER === $v->type) {
                if ($useFloat) {
                    $f = (float) $v->toInt();
                    if (null === $bestFloat || self::isBetterFloat($f, $bestFloat, $pickMin)) {
                        $bestFloat = $f;
                    }
                    continue;
                }
                $i = $v->toInt();
                if (null === $bestInt || self::isBetterInt($i, $bestInt, $pickMin)) {
                    $bestInt = $i;
                }
                continue;
            }
            if (Variable::TYPE_FLOAT === $v->type) {
                if (!$useFloat) {
                    $useFloat = true;
                    $bestFloat = null === $bestInt
                        ? $v->toFloat()
                        : self::pickFloat((float) $bestInt, $v->toFloat(), $pickMin);
                } else {
                    $f = $v->toFloat();
                    if (null === $bestFloat || self::isBetterFloat($f, $bestFloat, $pickMin)) {
                        $bestFloat = $f;
                    }
                }
                continue;
            }
            throw new \LogicException(
                $name.'() only supports integer and float values in this compiler build'
            );
        }

        if ($useFloat) {
            $frame->returnVar->float($bestFloat ?? 0.0);
        } else {
            $frame->returnVar->int($bestInt ?? 0);
        }
    }

    /**
     * @return list<Variable>
     */
    private static function collectValues(Frame $frame, string $name, int $argc): array
    {
        if (1 === $argc) {
            $arg = $frame->calledArgs[0]->resolveIndirect();
            if (Variable::TYPE_ARRAY !== $arg->type) {
                throw new \TypeError(
                    $name.'(): Argument #1 ($value) must be of type array, '
                    .self::valueTypeName($arg).' given'
                );
            }
            $values = [];
            foreach ($arg->toArray()->iterate(true) as $value) {
                $values[] = $value->resolveIndirect();
            }

            return $values;
        }

        $values = [];
        foreach ($frame->calledArgs as $calledArg) {
            $values[] = $calledArg->resolveIndirect();
        }

        return $values;
    }

    private static function isBetterInt(int $candidate, int $best, bool $pickMin): bool
    {
        return $pickMin ? $candidate < $best : $candidate > $best;
    }

    private static function isBetterFloat(float $candidate, float $best, bool $pickMin): bool
    {
        return $pickMin ? $candidate < $best : $candidate > $best;
    }

    private static function pickFloat(float $a, float $b, bool $pickMin): float
    {
        return $pickMin ? ($a < $b ? $a : $b) : ($a > $b ? $a : $b);
    }

    private static function valueTypeName(Variable $value): string
    {
        switch ($value->type) {
            case Variable::TYPE_INTEGER:
                return 'int';
            case Variable::TYPE_FLOAT:
                return 'float';
            case Variable::TYPE_BOOLEAN:
                return 'bool';
            case Variable::TYPE_STRING:
                return 'string';
            case Variable::TYPE_NULL:
                return 'null';
            case Variable::TYPE_ARRAY:
                return 'array';
            case Variable::TYPE_OBJECT:
                return 'object';
            case Variable::TYPE_RESOURCE:
                return 'resource';
            default:
                return 'mixed';
        }
    }
}
