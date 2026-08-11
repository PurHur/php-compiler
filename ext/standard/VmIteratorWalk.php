<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\VM;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ForeachIterator;
use PHPCompiler\VM\Variable;

/**
 * VM iterator_count() / iterator_apply() / is_iterable() helpers (issues #3313, php-src ext/spl/iterator.c).
 */
final class VmIteratorWalk
{
    /** Zend ext/spl/php_spl.c — iterator_count() on exhausted Generator (#5132). */
    private const CLOSED_GENERATOR_ITERATOR_COUNT_ERROR = VM\GeneratorState::CLOSED_TRAVERSE_ERROR;

    public static function isIterable(Variable $value, Context $ctx): bool
    {
        return VM\IterableCheck::isIterable($value, $ctx);
    }

    /**
     * iterator_count() / iterator_to_array() — Zend accepts Traversable|array.
     */
    public static function assertTraversable(
        Variable $value,
        Context $ctx,
        string $funcName,
        string $paramName = 'iterator'
    ): Variable {
        return self::assertIteratorOperand($value, $ctx, $funcName, $paramName, true);
    }

    /**
     * iterator_apply() — Zend Traversable only (arrays TypeError, #19839).
     */
    public static function assertTraversableOnly(
        Variable $value,
        Context $ctx,
        string $funcName,
        string $paramName = 'iterator'
    ): Variable {
        return self::assertIteratorOperand($value, $ctx, $funcName, $paramName, false);
    }

    private static function assertIteratorOperand(
        Variable $value,
        Context $ctx,
        string $funcName,
        string $paramName,
        bool $allowArray
    ): Variable {
        $value = $value->resolveIndirect();
        $ok = $allowArray
            ? self::isIterable($value, $ctx)
            : VM\IterableCheck::isTraversable($value, $ctx);
        if (!$ok) {
            $typeLabel = $allowArray
                ? VM\IterableCheck::TYPE_LABEL
                : VM\IterableCheck::TRAVERSABLE_TYPE_LABEL;
            throw new \TypeError(
                "{$funcName}(): Argument #1 (\${$paramName}) must be of type {$typeLabel}, "
                .VM\IterableCheck::valueTypeName($value).' given'
            );
        }

        return $value;
    }

    /**
     * Zend ext/spl/php_spl.c — iterator_to_array()/iterator_count() on started/closed Generator (#18582, #5132).
     *
     * Rewind is allowed while still on the opening yield (ZEND_GENERATOR_AT_FIRST_YIELD, #23713).
     */
    public static function assertGeneratorIterableForRewind(VM\GeneratorState $gen): void
    {
        if ($gen->done) {
            throw new \Exception(self::CLOSED_GENERATOR_ITERATOR_COUNT_ERROR);
        }
        if ($gen->started && !$gen->atFirstYield) {
            throw new \Exception(VM\GeneratorState::REWIND_ALREADY_RUN_ERROR);
        }
    }

    public static function count(VM $vm, Frame $frame, Variable $iterable): int
    {
        $iterable = $iterable->resolveIndirect();
        if (Variable::TYPE_ARRAY === $iterable->type) {
            return $iterable->toArray()->getNumElements();
        }
        if (null !== $iterable->toObject()->generatorState) {
            return self::countGenerator($vm, $iterable->toObject()->generatorState);
        }

        return self::countIteratorObject($vm, $frame, $iterable);
    }

    public static function apply(
        VM $vm,
        Frame $frame,
        Variable $iterable,
        Variable $callback,
        Variable $paramsArray
    ): int {
        $params = self::paramsList($paramsArray);
        $count = 0;
        $iterable = $iterable->resolveIndirect();
        if (Variable::TYPE_ARRAY === $iterable->type) {
            // php-src ext/spl/php_spl.c — count each invoked iteration before checking callback (#25326).
            foreach ($iterable->toArray()->iterateKeyed(true) as $pair) {
                ++$count;
                if (!self::invokeApplyCallback($frame, $callback, $params)) {
                    break;
                }
            }

            return $count;
        }
        if (null !== $iterable->toObject()->generatorState) {
            $gen = $iterable->toObject()->generatorState;
            $gen->rewind();
            while ($gen->hasCurrent && !$gen->done) {
                ++$count;
                if (!self::invokeApplyCallback($frame, $callback, $params)) {
                    break;
                }
                if (!$vm->resumeGenerator($gen)) {
                    break;
                }
            }

            return $count;
        }

        // php-src PHP_FUNCTION(iterator_apply) — walk while valid(); no current-equality
        // early exit (that undercounted LimitIterator(InfiniteIterator), #30237).
        $object = ForeachIterator::resolveTraversableObject($vm, $frame, $iterable);
        $vm->invokeForeachInstanceMethod($frame, $object, 'rewind');
        while ($vm->invokeForeachInstanceMethod($frame, $object, 'valid')->toBool()) {
            ++$count;
            if (!self::invokeApplyCallback($frame, $callback, $params)) {
                break;
            }
            $vm->invokeForeachInstanceMethod($frame, $object, 'next');
        }

        return $count;
    }

    /**
     * @return list<Variable>
     */
    private static function paramsList(Variable $paramsArray): array
    {
        $paramsArray = $paramsArray->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $paramsArray->type) {
            throw new \TypeError('iterator_apply(): Argument #3 must be of type array');
        }
        $out = [];
        // Preserve ref slots — iterateKeyed(true) would resolve before isIndirect() (#4547).
        foreach ($paramsArray->toArray()->iterateKeyed(false) as [, $value]) {
            if ($value->isIndirect()) {
                $out[] = $value;

                continue;
            }
            $copy = new Variable();
            $copy->copyFrom($value);
            $out[] = $copy;
        }

        return $out;
    }

    /**
     * @param list<Variable> $params
     */
    private static function invokeApplyCallback(
        Frame $frame,
        Variable $callback,
        array $params
    ): bool {
        $callback = $callback->resolveIndirect();
        if (VmClosureCall::isClosure($callback)) {
            if (null === $frame->vmContext) {
                throw new \LogicException('iterator_apply() requires VM context in this compiler build');
            }
            $closure = VmClosureCall::resolve($callback);
            $result = VmClosureCall::invoke($frame->vmContext, $closure, ...$params);

            return self::applyCallbackTruthy($result);
        }
        if (Variable::TYPE_STRING !== $callback->type) {
            throw new \TypeError(
                'iterator_apply(): Argument #2 must be a valid callback in this compiler build'
            );
        }
        $name = $callback->toString();
        try {
            $fn = VmInternalCall::resolveStringCallback($name);
            $result = VmInternalCall::invoke($fn, ...$params);
        } catch (\LogicException) {
            if (null === $frame->vmContext) {
                throw new \LogicException('iterator_apply() requires VM context in this compiler build');
            }
            $fn = VmUserCall::resolveStringCallback($frame->vmContext, $name);
            $result = VmUserCall::invokeArgs($frame->vmContext, $fn, ...$params);
        }

        return self::applyCallbackTruthy($result);
    }

    private static function applyCallbackTruthy(Variable $result): bool
    {
        $result = $result->resolveIndirect();
        if (Variable::TYPE_BOOLEAN === $result->type) {
            return $result->toBool();
        }
        if (Variable::TYPE_NULL === $result->type) {
            return false;
        }

        return true;
    }

    private static function countGenerator(VM $vm, VM\GeneratorState $gen): int
    {
        self::assertGeneratorIterableForRewind($gen);
        $gen->rewind();
        $count = 0;
        // Collect the opening yield before advancing (#23713 / zend_generator_rewind).
        while ($gen->hasCurrent && !$gen->done) {
            ++$count;
            if (!$vm->resumeGenerator($gen)) {
                break;
            }
        }

        return $count;
    }

    private static function countIteratorObject(VM $vm, Frame $frame, Variable $iterable): int
    {
        // php-src PHP_FUNCTION(iterator_count) — while (valid) { count++; next; }.
        // Do not break when current() is unchanged: InfiniteIterator yields the same
        // value forever, and LimitIterator still terminates via valid() (#30237).
        $object = ForeachIterator::resolveTraversableObject($vm, $frame, $iterable);
        $vm->invokeForeachInstanceMethod($frame, $object, 'rewind');
        $count = 0;
        while ($vm->invokeForeachInstanceMethod($frame, $object, 'valid')->toBool()) {
            ++$count;
            $vm->invokeForeachInstanceMethod($frame, $object, 'next');
        }

        return $count;
    }

}
