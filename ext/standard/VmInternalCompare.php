<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
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
     * Sort [key, value] Variable pairs in place by key (no PHP closures — AOT self-host spine safe).
     *
     * @param list<array{0: Variable, 1: Variable}> $pairs
     */
    public static function sortKeyedPairsByKey(array &$pairs): void
    {
        $n = \count($pairs);
        for ($i = 1; $i < $n; ++$i) {
            $j = $i;
            while ($j > 0 && self::compareKeys($pairs[$j - 1][0], $pairs[$j][0]) > 0) {
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
    public static function sortKeyedPairsByKeyDesc(array &$pairs): void
    {
        $n = \count($pairs);
        for ($i = 1; $i < $n; ++$i) {
            $j = $i;
            while ($j > 0 && self::compareKeys($pairs[$j - 1][0], $pairs[$j][0]) < 0) {
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
}
