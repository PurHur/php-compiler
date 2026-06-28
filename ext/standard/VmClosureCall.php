<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\ClosureState;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\Variable;

/**
 * Invoke VM closures from builtin callbacks (array_map, array_filter, usort; issue #72).
 */
final class VmClosureCall
{
    public static function isClosure(Variable $callback): bool
    {
        $callback = $callback->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $callback->type) {
            return false;
        }

        return null !== $callback->toObject()->closureState;
    }

    public static function resolve(Variable $callback): ClosureState
    {
        $callback = $callback->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $callback->type) {
            throw new \LogicException(
                'Callback must be a closure object in this compiler build'
            );
        }
        $state = $callback->toObject()->closureState;
        if (null === $state) {
            throw new \LogicException(
                'Callback object is not invokable as a closure in this compiler build'
            );
        }

        return $state;
    }

    public static function invoke(Context $context, ClosureState $closure, Variable ...$args): Variable
    {
        $copies = [];
        foreach ($args as $arg) {
            $copy = new Variable();
            $copy->copyFrom($arg);
            $copies[] = $copy;
        }

        return $context->runtime->vm->invokeClosure($closure, ...$copies);
    }

    /**
     * Invoke a closure without copying arguments (array_walk &$value; issue #3627).
     */
    public static function invokeDirect(Context $context, ClosureState $closure, Variable ...$args): Variable
    {
        return $context->runtime->vm->invokeClosure($closure, ...$args);
    }

    public static function invokeOne(Context $context, ClosureState $closure, Variable $arg): Variable
    {
        return self::invoke($context, $closure, $arg);
    }

    public static function invokeTwo(Context $context, ClosureState $closure, Variable $a, Variable $b): int
    {
        // Deep-copy compare operands so usort/uasort/uksort callbacks cannot mutate bucket
        // storage (php-src php_array_u*sort; issues #10212, #10213).
        $copyA = new Variable();
        $copyA->duplicateFrom($a);
        $copyB = new Variable();
        $copyB->duplicateFrom($b);
        $result = self::invoke($context, $closure, $copyA, $copyB);

        return self::coerceUserSortCallbackResult($result);
    }

    /** php-src php_usort_compare — bool true→1, false→-1; int/float sign-normalized (#13029). */
    public static function coerceUserSortCallbackResult(Variable $result): int
    {
        $result = $result->resolveIndirect();
        if (Variable::TYPE_BOOLEAN === $result->type) {
            return $result->toBool() ? 1 : -1;
        }
        if (Variable::TYPE_INTEGER === $result->type) {
            $value = $result->toInt();

            return $value > 0 ? 1 : ($value < 0 ? -1 : 0);
        }
        if (Variable::TYPE_FLOAT === $result->type) {
            $value = $result->toFloat();
            if ($value > 0.0) {
                return 1;
            }
            if ($value < 0.0) {
                return -1;
            }

            return 0;
        }

        return $result->toInt();
    }

    /**
     * Sort packed Variable list in place using a closure comparator (usort subset).
     *
     * @param list<Variable> $values
     */
    public static function sortVariableValues(Context $context, array &$values, ClosureState $closure): void
    {
        $cmp = static fn (Variable $a, Variable $b): int => self::invokeTwo($context, $closure, $a, $b);
        ZendSort::sort($values, $cmp);
    }

    /**
     * Sort [key, value] pairs in place by key using a closure comparator (uksort subset).
     *
     * @param list<array{0: Variable, 1: Variable}> $pairs
     */
    public static function sortKeyedPairsByKey(Context $context, array &$pairs, ClosureState $closure): void
    {
        $cmp = static fn (array $a, array $b): int => self::invokeTwo($context, $closure, $a[0], $b[0]);
        ZendSort::sort($pairs, $cmp);
    }

    /**
     * Sort [key, value] pairs in place by value using a closure comparator (uasort subset).
     *
     * @param list<array{0: Variable, 1: Variable}> $pairs
     */
    public static function sortKeyedPairsByValue(Context $context, array &$pairs, ClosureState $closure): void
    {
        $cmp = static fn (array $a, array $b): int => self::invokeTwo($context, $closure, $a[1], $b[1]);
        ZendSort::sort($pairs, $cmp);
    }
}
