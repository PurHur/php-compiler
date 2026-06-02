<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\VM;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ForeachIterator;
use PHPCompiler\VM\InterfaceCheck;
use PHPCompiler\VM\Variable;

/**
 * VM iterator_count() / iterator_apply() / is_iterable() helpers (issues #3313, php-src ext/spl/iterator.c).
 */
final class VmIteratorWalk
{
    private const TRAVERSABLE_IFACES = ['traversable', 'iterator', 'iteratoraggregate'];

    public static function isIterable(Variable $value, Context $ctx): bool
    {
        $value = $value->resolveIndirect();
        if (Variable::TYPE_ARRAY === $value->type) {
            return true;
        }
        if (Variable::TYPE_OBJECT !== $value->type) {
            return false;
        }
        $entry = $value->toObject();
        if (null !== $entry->generatorState) {
            return true;
        }
        $class = $entry->class;
        foreach (self::TRAVERSABLE_IFACES as $ifaceLc) {
            if (InterfaceCheck::entryImplements($class, $ifaceLc, $ctx)) {
                return true;
            }
        }

        return ForeachIterator::entryImplementsIteratorProtocol($class, $ctx);
    }

    public static function assertTraversable(Variable $value, Context $ctx, string $funcName): Variable
    {
        $value = $value->resolveIndirect();
        if (!self::isIterable($value, $ctx)) {
            throw new \TypeError(
                "{$funcName}(): Argument #1 must be of type Traversable|array, "
                .self::valueTypeName($value).' given'
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
        foreach ($paramsArray->toArray()->iterateKeyed(true) as [, $value]) {
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
            $args = [$value, $key, ...$params];
            $result = VmClosureCall::invoke($frame->vmContext, $closure, ...$args);

            return self::applyCallbackTruthy($result);
        }
        if (Variable::TYPE_STRING !== $callback->type) {
            throw new \TypeError(
                'iterator_apply(): Argument #2 must be a valid callback in this compiler build'
            );
        }
        $fn = VmInternalCall::resolveStringCallback($callback->toString());
        $args = [$value, $key, ...$params];
        $result = VmInternalCall::invoke($fn, ...$args);

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

    private static function valueTypeName(Variable $value): string
    {
        return match ($value->type) {
            Variable::TYPE_INTEGER => 'int',
            Variable::TYPE_FLOAT => 'float',
            Variable::TYPE_BOOLEAN => 'bool',
            Variable::TYPE_STRING => 'string',
            Variable::TYPE_NULL => 'null',
            Variable::TYPE_ARRAY => 'array',
            Variable::TYPE_OBJECT => 'object',
            default => 'mixed',
        };
    }
}
