<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\Variable;

/**
 * Invoke binary stdlib Internal comparators from other VM builtins (string callbacks).
 */
final class VmInternalCompare
{
    /** @var array<string, class-string<Internal>> */
    private const STRING_CALLBACKS = [
        'strcmp' => strcmp::class,
        'strcasecmp' => strcasecmp::class,
        'strnatcmp' => strnatcmp::class,
        'strnatcasecmp' => strnatcasecmp::class,
    ];

    public static function resolveStringCallback(string $name): Internal
    {
        $lc = strtolower($name);
        if (!isset(self::STRING_CALLBACKS[$lc])) {
            throw new \LogicException(
                "String compare callback '{$name}' is not supported in this compiler build"
            );
        }

        $class = self::STRING_CALLBACKS[$lc];

        return new $class();
    }

    /** Resolve strcmp-family comparator from sort() flags (php-src php_array_sort). */
    public static function stringCompareForSortFlags(int $flags): Internal
    {
        return self::valueCompareForSortFlags($flags);
    }

    /** Resolve strcmp-family comparator for asort/arsort value operands (php-src php_array_sort). */
    public static function valueCompareForSortFlags(int $flags): Internal
    {
        $caseFlag = $flags & StdlibConstants::SORT_FLAG_CASE;
        $sortType = $flags & ~StdlibConstants::SORT_FLAG_CASE;

        return match ($sortType) {
            StdlibConstants::SORT_NATURAL => self::resolveStringCallback(
                0 !== $caseFlag ? 'strnatcasecmp' : 'strnatcmp'
            ),
            StdlibConstants::SORT_STRING => self::resolveStringCallback(
                0 !== $caseFlag ? 'strcasecmp' : 'strcmp'
            ),
            StdlibConstants::SORT_REGULAR,
            StdlibConstants::SORT_NUMERIC,
            StdlibConstants::SORT_LOCALE_STRING => self::resolveStringCallback(
                0 !== $caseFlag ? 'strcasecmp' : 'strcmp'
            ),
            default => self::resolveStringCallback(
                0 !== $caseFlag ? 'strcasecmp' : 'strcmp'
            ),
        };
    }

    /**
     * Parse optional sort_flags argument on ksort/asort family (php-src basic_functions.c).
     *
     * @throws \LogicException when flags are not an integer
     */
    public static function resolveFrameSortFlags(Frame $frame, string $function, int $argIndex = 1): int
    {
        $flagsArg = $frame->calledArgs[$argIndex]->resolveIndirect();
        if (Variable::TYPE_INTEGER !== $flagsArg->type) {
            throw new \LogicException($function.'() flags must be an integer in this compiler build');
        }

        return $flagsArg->toInt();
    }

    /** Compare array keys for ksort/krsort with php-src sort_type dispatch. */
    public static function compareKeysForSort(Variable $a, Variable $b, int $flags): int
    {
        $caseFlag = $flags & StdlibConstants::SORT_FLAG_CASE;
        $sortType = $flags & ~StdlibConstants::SORT_FLAG_CASE;

        if (StdlibConstants::SORT_NUMERIC === $sortType) {
            return self::compareNumericOperandsForSort($a, $b);
        }
        if (StdlibConstants::SORT_NATURAL === $sortType) {
            return self::invoke(
                self::resolveStringCallback(0 !== $caseFlag ? 'strnatcasecmp' : 'strnatcmp'),
                $a,
                $b
            );
        }
        if (
            StdlibConstants::SORT_STRING === $sortType
            || StdlibConstants::SORT_LOCALE_STRING === $sortType
        ) {
            return self::invoke(
                self::resolveStringCallback(0 !== $caseFlag ? 'strcasecmp' : 'strcmp'),
                $a,
                $b
            );
        }

        return self::compareKeys($a, $b);
    }

    /** Compare array values for asort/arsort packed lists with SORT_NUMERIC (php-src). */
    public static function compareValuesForSortFlags(Variable $a, Variable $b, int $flags): int
    {
        $sortType = $flags & ~StdlibConstants::SORT_FLAG_CASE;
        if (StdlibConstants::SORT_NUMERIC === $sortType) {
            return self::compareNumericOperandsForSort($a, $b);
        }
        if (
            StdlibConstants::SORT_STRING === $sortType
            || StdlibConstants::SORT_LOCALE_STRING === $sortType
            || StdlibConstants::SORT_NATURAL === $sortType
        ) {
            return self::invoke(self::valueCompareForSortFlags($flags), $a, $b);
        }

        return self::compareValuesForSort($a, $b);
    }

    /** php-src zend_compare numeric sort — non-numeric strings compare as 0. */
    private static function compareNumericOperandsForSort(Variable $a, Variable $b): int
    {
        $av = self::numericSortScalar($a);
        $bv = self::numericSortScalar($b);
        if (\is_float($av) || \is_float($bv)) {
            return (float) $av <=> (float) $bv;
        }

        return (int) $av <=> (int) $bv;
    }

    private static function numericSortScalar(Variable $value): int|float
    {
        $value = $value->resolveIndirect();
        if (Variable::TYPE_INTEGER === $value->type) {
            return $value->toInt();
        }
        if (Variable::TYPE_DOUBLE === $value->type) {
            return $value->toFloat();
        }
        if (Variable::TYPE_STRING === $value->type) {
            $s = $value->toString();
            if ('' === $s || !\is_numeric($s)) {
                return 0;
            }

            return str_contains($s, '.') ? (float) $s : (int) $s;
        }
        if (Variable::TYPE_NULL === $value->type || Variable::TYPE_FALSE === $value->type) {
            return 0;
        }
        if (Variable::TYPE_TRUE === $value->type) {
            return 1;
        }

        return 0;
    }

    public static function invoke(Internal $fn, Variable $a, Variable $b): int
    {
        $frame = new Frame($fn, null, null);
        $frame->calledArgs = [$a, $b];
        $out = new Variable();
        $frame->returnVar = $out;
        $fn->execute($frame);

        return $out->resolveIndirect()->toInt();
    }

    /**
     * Sort packed Variable list using {@see Variable::compareSpaceship()} (php-src zend_compare).
     *
     * @param list<Variable> $values
     */
    public static function sortVariableValuesBySpaceship(array &$values): void
    {
        $n = \count($values);
        for ($i = 1; $i < $n; ++$i) {
            $j = $i;
            while ($j > 0 && self::compareValuesForSort($values[$j - 1], $values[$j]) > 0) {
                $tmp = $values[$j - 1];
                $values[$j - 1] = $values[$j];
                $values[$j] = $tmp;
                --$j;
            }
        }
    }

    /**
     * Sort packed Variable list descending via {@see compareValuesForSort()} (rsort/arsort enum arrays, #6150).
     *
     * @param list<Variable> $values
     */
    public static function sortVariableValuesBySpaceshipDesc(array &$values): void
    {
        $n = \count($values);
        for ($i = 1; $i < $n; ++$i) {
            $j = $i;
            while ($j > 0 && self::compareValuesForSort($values[$j - 1], $values[$j]) < 0) {
                $tmp = $values[$j - 1];
                $values[$j - 1] = $values[$j];
                $values[$j] = $tmp;
                --$j;
            }
        }
    }

    /**
     * Sort packed Variable list in place (no PHP closures — AOT self-host spine safe).
     *
     * @param list<Variable> $values
     */
    public static function sortVariableValues(array &$values, Internal $compare): void
    {
        $n = \count($values);
        for ($i = 1; $i < $n; ++$i) {
            $j = $i;
            while ($j > 0 && self::invoke($compare, $values[$j - 1], $values[$j]) > 0) {
                $tmp = $values[$j - 1];
                $values[$j - 1] = $values[$j];
                $values[$j] = $tmp;
                --$j;
            }
        }
    }

    /**
     * Sort packed Variable list in place descending (arsort packed lists).
     *
     * @param list<Variable> $values
     */
    public static function sortVariableValuesDesc(array &$values, Internal $compare): void
    {
        $n = \count($values);
        for ($i = 1; $i < $n; ++$i) {
            $j = $i;
            while ($j > 0 && self::invoke($compare, $values[$j - 1], $values[$j]) < 0) {
                $tmp = $values[$j - 1];
                $values[$j - 1] = $values[$j];
                $values[$j] = $tmp;
                --$j;
            }
        }
    }

    /**
     * Sort [key, value] Variable pairs in place by value (no PHP closures — AOT self-host spine safe).
     *
     * @param list<array{0: Variable, 1: Variable}> $pairs
     */
    public static function sortKeyedPairsByValue(array &$pairs, Internal $compare): void
    {
        $n = \count($pairs);
        for ($i = 1; $i < $n; ++$i) {
            $j = $i;
            while ($j > 0 && self::invoke($compare, $pairs[$j - 1][1], $pairs[$j][1]) > 0) {
                $tmp = $pairs[$j - 1];
                $pairs[$j - 1] = $pairs[$j];
                $pairs[$j] = $tmp;
                --$j;
            }
        }
    }

    /**
     * Sort [key, value] Variable pairs in place by value descending (arsort).
     *
     * @param list<array{0: Variable, 1: Variable}> $pairs
     */
    public static function sortKeyedPairsByValueDesc(array &$pairs, Internal $compare): void
    {
        $n = \count($pairs);
        for ($i = 1; $i < $n; ++$i) {
            $j = $i;
            while ($j > 0 && self::invoke($compare, $pairs[$j - 1][1], $pairs[$j][1]) < 0) {
                $tmp = $pairs[$j - 1];
                $pairs[$j - 1] = $pairs[$j];
                $pairs[$j] = $tmp;
                --$j;
            }
        }
    }

    /**
     * Sort [key, value] pairs ascending by enum/object value spaceship (#5546, #6150).
     *
     * @param list<array{0: Variable, 1: Variable}> $pairs
     */
    public static function sortKeyedPairsByValueSpaceship(array &$pairs): void
    {
        $n = \count($pairs);
        for ($i = 1; $i < $n; ++$i) {
            $j = $i;
            while ($j > 0 && self::compareValuesForSort($pairs[$j - 1][1], $pairs[$j][1]) > 0) {
                $tmp = $pairs[$j - 1];
                $pairs[$j - 1] = $pairs[$j];
                $pairs[$j] = $tmp;
                --$j;
            }
        }
    }

    /**
     * Sort [key, value] pairs descending by enum/object value spaceship (#6150).
     *
     * @param list<array{0: Variable, 1: Variable}> $pairs
     */
    public static function sortKeyedPairsByValueSpaceshipDesc(array &$pairs): void
    {
        $n = \count($pairs);
        for ($i = 1; $i < $n; ++$i) {
            $j = $i;
            while ($j > 0 && self::compareValuesForSort($pairs[$j - 1][1], $pairs[$j][1]) < 0) {
                $tmp = $pairs[$j - 1];
                $pairs[$j - 1] = $pairs[$j];
                $pairs[$j] = $tmp;
                --$j;
            }
        }
    }

    /**
     * Sort [key, value] pairs in place by integer value descending (arsort subset).
     *
     * @param list<array{0: Variable, 1: Variable}> $pairs
     */
    public static function sortKeyedPairsByValueIntDesc(array &$pairs): void
    {
        $n = \count($pairs);
        for ($i = 1; $i < $n; ++$i) {
            $j = $i;
            while ($j > 0) {
                $a = $pairs[$j - 1][1]->resolveIndirect();
                $b = $pairs[$j][1]->resolveIndirect();
                if (Variable::TYPE_INTEGER !== $a->type || Variable::TYPE_INTEGER !== $b->type) {
                    throw new \LogicException(
                        'arsort() only supports homogeneous string or integer values in this compiler build'
                    );
                }
                if ($a->toInt() >= $b->toInt()) {
                    break;
                }
                $tmp = $pairs[$j - 1];
                $pairs[$j - 1] = $pairs[$j];
                $pairs[$j] = $tmp;
                --$j;
            }
        }
    }

    /**
     * Sort [key, value] pairs in place by integer value ascending (asort subset).
     *
     * @param list<array{0: Variable, 1: Variable}> $pairs
     */
    public static function sortKeyedPairsByValueInt(array &$pairs): void
    {
        $n = \count($pairs);
        for ($i = 1; $i < $n; ++$i) {
            $j = $i;
            while ($j > 0) {
                $a = $pairs[$j - 1][1]->resolveIndirect();
                $b = $pairs[$j][1]->resolveIndirect();
                if (Variable::TYPE_INTEGER !== $a->type || Variable::TYPE_INTEGER !== $b->type) {
                    throw new \LogicException(
                        'asort() only supports homogeneous string or integer values in this compiler build'
                    );
                }
                if ($a->toInt() <= $b->toInt()) {
                    break;
                }
                $tmp = $pairs[$j - 1];
                $pairs[$j - 1] = $pairs[$j];
                $pairs[$j] = $tmp;
                --$j;
            }
        }
    }

    /**
     * Sort [key, value] Variable pairs in place by key using a string builtin comparator (uksort subset).
     *
     * @param list<array{0: Variable, 1: Variable}> $pairs
     */
    public static function sortKeyedPairsByKeyWithCompare(array &$pairs, Internal $compare): void
    {
        $n = \count($pairs);
        for ($i = 1; $i < $n; ++$i) {
            $j = $i;
            while ($j > 0 && self::invoke($compare, $pairs[$j - 1][0], $pairs[$j][0]) > 0) {
                $tmp = $pairs[$j - 1];
                $pairs[$j - 1] = $pairs[$j];
                $pairs[$j] = $tmp;
                --$j;
            }
        }
    }

    /**
     * Sort [key, value] Variable pairs in place by key (no PHP closures — AOT self-host spine safe).
     *
     * @param list<array{0: Variable, 1: Variable}> $pairs
     */
    public static function sortKeyedPairsByKey(array &$pairs, int $flags = StdlibConstants::SORT_REGULAR): void
    {
        $n = \count($pairs);
        for ($i = 1; $i < $n; ++$i) {
            $j = $i;
            while ($j > 0 && self::compareKeysForSort($pairs[$j - 1][0], $pairs[$j][0], $flags) > 0) {
                $tmp = $pairs[$j - 1];
                $pairs[$j - 1] = $pairs[$j];
                $pairs[$j] = $tmp;
                --$j;
            }
        }
    }

    /**
     * Sort [key, value] Variable pairs in place by key descending (krsort).
     *
     * @param list<array{0: Variable, 1: Variable}> $pairs
     */
    public static function sortKeyedPairsByKeyDesc(array &$pairs, int $flags = StdlibConstants::SORT_REGULAR): void
    {
        $n = \count($pairs);
        for ($i = 1; $i < $n; ++$i) {
            $j = $i;
            while ($j > 0 && self::compareKeysForSort($pairs[$j - 1][0], $pairs[$j][0], $flags) < 0) {
                $tmp = $pairs[$j - 1];
                $pairs[$j - 1] = $pairs[$j];
                $pairs[$j] = $tmp;
                --$j;
            }
        }
    }

    public static function compareKeys(Variable $a, Variable $b): int
    {
        $a = $a->resolveIndirect();
        $b = $b->resolveIndirect();
        if (Variable::TYPE_STRING === $a->type && Variable::TYPE_STRING === $b->type) {
            return self::invoke(self::resolveStringCallback('strcmp'), $a, $b);
        }
        if (Variable::TYPE_INTEGER === $a->type && Variable::TYPE_INTEGER === $b->type) {
            return $a->toInt() <=> $b->toInt();
        }

        throw new \LogicException(
            'ksort() only supports homogeneous string or integer keys in this compiler build'
        );
    }

    /** Compare packed array values for sort()/array_multisort() enum and object operands (#5624). */
    public static function comparePackedValuesForSort(Variable $left, Variable $right): int
    {
        return self::compareValuesForSort($left, $right);
    }

    private static function compareValuesForSort(Variable $left, Variable $right): int
    {
        if (EnumCaseSupport::isEnumCaseVariable($left) && EnumCaseSupport::isEnumCaseVariable($right)) {
            return EnumCaseSupport::compareEnumCasesForSort($left, $right);
        }

        return Variable::compareSpaceship($left, $right);
    }

    /**
     * @param list<Variable> $values
     */
    public static function assertHomogeneousEnumOrObjectValues(array $values, string $function): void
    {
        foreach ($values as $value) {
            $resolved = $value->resolveIndirect();
            if (EnumCaseSupport::isEnumCaseVariable($resolved)) {
                continue;
            }
            if (Variable::TYPE_OBJECT !== $resolved->type) {
                throw new \LogicException(
                    $function.' only supports homogeneous object arrays in this compiler build'
                );
            }
        }
    }
}
