<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\VM\EnumCaseSupport;
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

    /**
     * Two-argument fast path for enum case operands (#5570).
     */
    public static function tryReduceEnumCasesTwoArg(Frame $frame, bool $pickMin): bool
    {
        if (2 !== \count($frame->calledArgs)) {
            return false;
        }
        $values = [
            $frame->calledArgs[0]->resolveIndirect(),
            $frame->calledArgs[1]->resolveIndirect(),
        ];

        return self::finishEnumCaseReduce($frame, $values, $pickMin, false);
    }

    /**
     * Two-argument scalar path — int/float/numeric-string (php-src php_min_max, #4347).
     */
    public static function tryReduceScalarsTwoArg(Frame $frame, bool $pickMin): bool
    {
        if (2 !== \count($frame->calledArgs) || null === $frame->returnVar) {
            return false;
        }

        $a = $frame->calledArgs[0]->resolveIndirect();
        $b = $frame->calledArgs[1]->resolveIndirect();
        if (!self::isNumericScalar($a) || !self::isNumericScalar($b)) {
            return false;
        }

        // php-src ZEND_FRAMELESS min/max double path: min uses `>`, max uses `>=` (#10776).
        $best = $pickMin
            ? (self::numericGreaterThan($a, $b) ? $b : $a)
            : (self::numericGreaterOrEqual($a, $b) ? $a : $b);
        $frame->returnVar->copyFrom($best);

        return true;
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

        if (self::finishEnumCaseReduce($frame, $values, $pickMin, 1 === $argc)) {
            return;
        }

        $best = $values[0];
        self::assertNumericScalar($best, $name);
        foreach (\array_slice($values, 1) as $candidate) {
            self::assertNumericScalar($candidate, $name);
            // zend_hash_minmax + php_data_compare (zend_compare) ordering (#10776).
            $cmp = Variable::spaceshipCompare($best, $candidate);
            if ($pickMin ? $cmp > 0 : $cmp < 0) {
                $best = $candidate;
            }
        }

        if (null === $frame->returnVar) {
            return;
        }

        $frame->returnVar->copyFrom($best);
    }

    /**
     * @param list<Variable> $values
     */
    private static function finishEnumCaseReduce(
        Frame $frame,
        array $values,
        bool $pickMin,
        bool $arrayForm
    ): bool {
        $context = $frame->vmContext;
        if (null === $context) {
            return false;
        }

        $enumClass = null;
        $normalized = [];
        foreach ($values as $raw) {
            $case = EnumCaseSupport::normalizeEnumCaseOperand($raw, $context, $enumClass);
            if (null === $case) {
                return false;
            }
            $class = EnumCaseSupport::enumClassForCaseVariable($case);
            if (null === $class) {
                return false;
            }
            if (null !== $enumClass && $enumClass !== $class) {
                return false;
            }
            $enumClass = $class;
            $normalized[] = $case;
        }

        if ($arrayForm) {
            // zend_hash_minmax + php_data_compare: max keeps first, min keeps last (#5707).
            $best = $normalized[$pickMin ? \count($normalized) - 1 : 0];
            if (null !== $frame->returnVar) {
                $frame->returnVar->copyFrom($best);
            }

            return true;
        }

        $best = $normalized[0];
        foreach (\array_slice($normalized, 1) as $candidate) {
            $cmp = EnumCaseSupport::compareEnumCasesForMinMax($candidate, $best);
            if ($pickMin ? $cmp < 0 : $cmp > 0) {
                $best = $candidate;
            }
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->copyFrom($best);
        }

        return true;
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

    private static function isNumericScalar(Variable $value): bool
    {
        if (Variable::TYPE_INTEGER === $value->type || Variable::TYPE_FLOAT === $value->type) {
            return true;
        }
        if (Variable::TYPE_STRING !== $value->type) {
            return false;
        }
        $s = $value->toString();

        return '' !== $s && \is_numeric($s);
    }

    private static function assertNumericScalar(Variable $value, string $name): void
    {
        if (self::isNumericScalar($value)) {
            return;
        }

        throw new \TypeError(
            $name.'(): Argument #1 ($value) must be of type array, '
            .self::valueTypeName($value).' given'
        );
    }

    private static function numericGreaterThan(Variable $a, Variable $b): bool
    {
        $av = self::numericCompareValue($a);
        $bv = self::numericCompareValue($b);
        if (self::operandsUseFloatCompare($a, $b, $av, $bv)) {
            $af = \is_float($av) ? $av : (float) $av;
            $bf = \is_float($bv) ? $bv : (float) $bv;

            return $af > $bf;
        }

        return (int) $av > (int) $bv;
    }

    private static function numericGreaterOrEqual(Variable $a, Variable $b): bool
    {
        $av = self::numericCompareValue($a);
        $bv = self::numericCompareValue($b);
        if (self::operandsUseFloatCompare($a, $b, $av, $bv)) {
            $af = \is_float($av) ? $av : (float) $av;
            $bf = \is_float($bv) ? $bv : (float) $bv;

            return $af >= $bf;
        }

        return (int) $av >= (int) $bv;
    }

    private static function operandsUseFloatCompare(
        Variable $a,
        Variable $b,
        int|float $av,
        int|float $bv
    ): bool {
        return Variable::TYPE_FLOAT === $a->type
            || Variable::TYPE_FLOAT === $b->type
            || \is_float($av)
            || \is_float($bv);
    }

    private static function numericCompareValue(Variable $value): int|float
    {
        if (Variable::TYPE_INTEGER === $value->type) {
            return $value->toInt();
        }
        if (Variable::TYPE_FLOAT === $value->type) {
            return $value->toFloat();
        }
        if (Variable::TYPE_STRING === $value->type) {
            $s = $value->toString();
            if ('' === $s || !\is_numeric($s)) {
                throw new \TypeError('Unsupported operand types: string');
            }

            return $value->toNumeric();
        }

        throw new \TypeError('Unsupported operand types: '.self::operandZendTypeName($value));
    }

    private static function operandZendTypeName(Variable $value): string
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

    private static function valueTypeName(Variable $value): string
    {
        return self::operandZendTypeName($value);
    }
}
