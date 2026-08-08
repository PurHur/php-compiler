<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
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
            // Preserve call_user_func_array([&$x]) / HT reference elements (#28793).
            if ($arg->isIndirect()) {
                $copies[] = $arg;
                continue;
            }
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

    /**
     * php-src php_array_user_compare_unstable / php_array_user_key_compare_unstable (#11219).
     */
    public static function invokeTwoForUserCompare(
        Context $context,
        ClosureState $closure,
        Variable $a,
        Variable $b
    ): int {
        return self::invokeUserCompareWith(
            static fn (Variable ...$args): Variable => self::invoke($context, $closure, ...$args),
            $a,
            $b
        );
    }

    /**
     * Same unstable user-compare rules for any VmCallable (invokable/array/user-string; #23551).
     * Optional $scopeFrame keeps in-scope private/protected comparators visible (#25736).
     */
    public static function invokeVariableForUserCompare(
        Context $context,
        Variable $callback,
        Variable $a,
        Variable $b,
        ?Frame $scopeFrame = null,
        string $function = 'call_user_func'
    ): int {
        return self::invokeUserCompareWith(
            static function (Variable ...$args) use ($context, $callback, $scopeFrame, $function): Variable {
                if (null !== $scopeFrame) {
                    return VmCallable::invokeAsWithScope(
                        $function,
                        $context,
                        $scopeFrame,
                        $callback,
                        ...$args
                    );
                }

                return VmCallable::invoke($context, $callback, ...$args);
            },
            $a,
            $b
        );
    }

    /**
     * @param callable(Variable...): Variable $invoke
     */
    private static function invokeUserCompareWith(
        callable $invoke,
        Variable $a,
        Variable $b
    ): int {
        $copyA = new Variable();
        $copyA->duplicateFrom($a);
        $copyB = new Variable();
        $copyB->duplicateFrom($b);
        $result = $invoke($copyA, $copyB);
        $result = $result->resolveIndirect();
        if (Variable::TYPE_BOOLEAN === $result->type) {
            if (!$result->toBool()) {
                $swapA = new Variable();
                $swapA->duplicateFrom($b);
                $swapB = new Variable();
                $swapB->duplicateFrom($a);
                $retry = $invoke($swapA, $swapB);

                return -self::normalizeCompareSign(self::compareCallbackScalar($retry));
            }
        }

        return self::normalizeCompareSign(self::compareCallbackScalar($result));
    }

    private static function compareCallbackScalar(Variable $result): int
    {
        $result = $result->resolveIndirect();
        if (Variable::TYPE_BOOLEAN === $result->type) {
            return $result->toBool() ? 1 : 0;
        }
        if (Variable::TYPE_INTEGER === $result->type) {
            return $result->toInt();
        }
        if (Variable::TYPE_FLOAT === $result->type) {
            return (int) $result->toFloat();
        }

        return $result->toInt();
    }

    private static function normalizeCompareSign(int $value): int
    {
        return $value > 0 ? 1 : ($value < 0 ? -1 : 0);
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
    public static function sortVariableValues(
        Context $context,
        array &$values,
        ClosureState $closure,
        bool $descending = false
    ): void {
        $cmp = static function (Variable $a, Variable $b) use ($context, $closure, $descending): int {
            $result = self::invokeTwo($context, $closure, $a, $b);

            return $descending ? -$result : $result;
        };
        ZendSort::sort($values, $cmp);
    }

    /**
     * Thin-AOT NestedJIT sort — Closure Variable, no ClosureState (#24156).
     *
     * @param list<Variable> $values
     */
    public static function sortVariableValuesViaTarget(
        array &$values,
        Variable $closure,
        bool $descending = false
    ): void {
        $cmp = static function (Variable $a, Variable $b) use ($closure, $descending): int {
            $result = VmClosureInvoke::invokeVariableTwo($closure, $a, $b);

            return $descending ? -$result : $result;
        };
        ZendSort::sort($values, $cmp);
    }

    /**
     * @param list<array{0: Variable, 1: Variable}> $pairs
     */
    public static function sortKeyedPairsByKey(
        Context $context,
        array &$pairs,
        ClosureState $closure,
        bool $descending = false
    ): void {
        $cmp = static function (array $a, array $b) use ($context, $closure, $descending): int {
            $result = self::invokeTwo($context, $closure, $a[0], $b[0]);

            return $descending ? -$result : $result;
        };
        ZendSort::sort($pairs, $cmp);
    }

    /**
     * @param list<array{0: Variable, 1: Variable}> $pairs
     */
    public static function sortKeyedPairsByKeyViaTarget(
        array &$pairs,
        Variable $closure,
        bool $descending = false
    ): void {
        $cmp = static function (array $a, array $b) use ($closure, $descending): int {
            $result = VmClosureInvoke::invokeVariableTwo($closure, $a[0], $b[0]);

            return $descending ? -$result : $result;
        };
        ZendSort::sort($pairs, $cmp);
    }

    /**
     * @param list<array{0: Variable, 1: Variable}> $pairs
     */
    public static function sortKeyedPairsByValue(
        Context $context,
        array &$pairs,
        ClosureState $closure,
        bool $descending = false
    ): void {
        $cmp = static function (array $a, array $b) use ($context, $closure, $descending): int {
            $result = self::invokeTwo($context, $closure, $a[1], $b[1]);

            return $descending ? -$result : $result;
        };
        ZendSort::sort($pairs, $cmp);
    }

    /**
     * @param list<array{0: Variable, 1: Variable}> $pairs
     */
    public static function sortKeyedPairsByValueViaTarget(
        array &$pairs,
        Variable $closure,
        bool $descending = false
    ): void {
        $cmp = static function (array $a, array $b) use ($closure, $descending): int {
            $result = VmClosureInvoke::invokeVariableTwo($closure, $a[1], $b[1]);

            return $descending ? -$result : $result;
        };
        ZendSort::sort($pairs, $cmp);
    }
}
