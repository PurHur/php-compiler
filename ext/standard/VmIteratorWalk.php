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
    private const CLOSED_GENERATOR_ITERATOR_COUNT_ERROR = 'Cannot traverse an already closed generator';

    public static function isIterable(Variable $value, Context $ctx): bool
    {
        return VM\IterableCheck::isIterable($value, $ctx);
    }

    public static function assertTraversable(
        Variable $value,
        Context $ctx,
        string $funcName,
        string $paramName = 'iterator'
    ): Variable {
        $value = $value->resolveIndirect();
        if (!self::isIterable($value, $ctx)) {
            throw new \TypeError(
                "{$funcName}(): Argument #1 (\${$paramName}) must be of type ".VM\IterableCheck::TYPE_LABEL.', '
                .VM\IterableCheck::valueTypeName($value).' given'
            );
        }

        return $value;
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
            foreach ($iterable->toArray()->iterateKeyed(true) as [$key, $value]) {
                if (!self::invokeApplyCallback($frame, $callback, $value, $key, $params)) {
                    break;
                }
                ++$count;
            }

            return $count;
        }
        if (null !== $iterable->toObject()->generatorState) {
            $gen = $iterable->toObject()->generatorState;
            $gen->rewind();
            while ($vm->resumeGenerator($gen)) {
                $value = new Variable();
                $value->copyFrom($gen->currentValue);
                $key = new Variable();
                $key->copyFrom($gen->currentKey);
                if (!self::invokeApplyCallback($frame, $callback, $value, $key, $params)) {
                    break;
                }
                ++$count;
            }

            return $count;
        }

        $object = ForeachIterator::resolveTraversableObject($vm, $frame, $iterable);
        $vm->invokeForeachInstanceMethod($frame, $object, 'rewind');
        while ($vm->invokeForeachInstanceMethod($frame, $object, 'valid')->toBool()) {
            $value = $vm->invokeForeachInstanceMethod($frame, $object, 'current')->resolveIndirect();
            $key = $vm->invokeForeachInstanceMethod($frame, $object, 'key')->resolveIndirect();
            if (!self::invokeApplyCallback($frame, $callback, $value, $key, $params)) {
                break;
            }
            ++$count;
            $before = $value;
            $vm->invokeForeachInstanceMethod($frame, $object, 'next');
            $after = $vm->invokeForeachInstanceMethod($frame, $object, 'current')->resolveIndirect();
            if (self::vmValuesEqual($before, $after) && $count > 0) {
                break;
            }
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
        Variable $value,
        Variable $key,
        array $params
    ): bool {
        $callback = $callback->resolveIndirect();
        if (VmClosureCall::isClosure($callback)) {
            if (null === $frame->vmContext) {
                throw new \LogicException('iterator_apply() requires VM context in this compiler build');
            }
            $closure = VmClosureCall::resolve($callback);
            $result = VmClosureCall::invoke($frame->vmContext, $closure, $value, $key, ...$params);

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
            $result = VmInternalCall::invoke($fn, $value, $key, ...$params);
        } catch (\LogicException) {
            if (null === $frame->vmContext) {
                throw new \LogicException('iterator_apply() requires VM context in this compiler build');
            }
            $fn = VmUserCall::resolveStringCallback($frame->vmContext, $name);
            $result = VmUserCall::invokeArgs($frame->vmContext, $fn, $value, $key, ...$params);
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
        if ($gen->started) {
            throw new \Exception(self::CLOSED_GENERATOR_ITERATOR_COUNT_ERROR);
        }
        $gen->rewind();
        $count = 0;
        while ($vm->resumeGenerator($gen)) {
            ++$count;
        }

        return $count;
    }

    private static function countIteratorObject(VM $vm, Frame $frame, Variable $iterable): int
    {
        $object = ForeachIterator::resolveTraversableObject($vm, $frame, $iterable);
        $vm->invokeForeachInstanceMethod($frame, $object, 'rewind');
        $count = 0;
        while ($vm->invokeForeachInstanceMethod($frame, $object, 'valid')->toBool()) {
            $before = $vm->invokeForeachInstanceMethod($frame, $object, 'current')->resolveIndirect();
            ++$count;
            $vm->invokeForeachInstanceMethod($frame, $object, 'next');
            $after = $vm->invokeForeachInstanceMethod($frame, $object, 'current')->resolveIndirect();
            if (self::vmValuesEqual($before, $after) && $count > 0) {
                break;
            }
        }

        return $count;
    }

    private static function vmValuesEqual(Variable $a, Variable $b): bool
    {
        $a = $a->resolveIndirect();
        $b = $b->resolveIndirect();
        if ($a->type !== $b->type) {
            return false;
        }
        if (Variable::TYPE_INTEGER === $a->type) {
            return $a->toInt() === $b->toInt();
        }
        if (Variable::TYPE_STRING === $a->type) {
            return $a->toString() === $b->toString();
        }

        return false;
    }

}
