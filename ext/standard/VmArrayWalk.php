<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\ObjectPropertyIterator;
use PHPCompiler\VM\Variable;

/**
 * Shared walk helpers for array_walk() / array_walk_recursive() (php-src ext/standard/array.c; #9291, #11410).
 */
final class VmArrayWalk
{
    /**
     * Whether callback arg #0 is by-ref.
     *
     * Object walks must pass live property slots only for by-ref params; by-value callbacks
     * need copies or declared properties are cleared when mangled keys are passed (#23552).
     */
    private static function callbackValueByRef(\PHPCompiler\VM\ClosureState|\PHPCompiler\Func\PHP $callback): bool
    {
        if ($callback instanceof \PHPCompiler\VM\ClosureState) {
            return isset($callback->func->block->paramByRef[0]);
        }

        return isset($callback->block->paramByRef[0]);
    }

    private static function objectIterator(ObjectEntry $object, \PHPCompiler\VM $vm, Frame $frame): ObjectPropertyIterator
    {
        return new ObjectPropertyIterator(
            $object,
            $vm,
            $frame,
            ObjectPropertyIterator::PURPOSE_ARRAY_WALK
        );
    }

    public static function walkArrayRecursiveClosure(
        \PHPCompiler\VM\Context $context,
        HashTable $table,
        \PHPCompiler\VM\ClosureState $closure,
        ?Variable $userdata
    ): bool {
        foreach ($table->iterateKeyed(false) as [$key, $value]) {
            if (Variable::TYPE_ARRAY === $value->type) {
                $value->separateArrayForWrite();
                if (!self::walkArrayRecursiveClosure($context, $value->toArray(), $closure, $userdata)) {
                    return false;
                }
                continue;
            }
            $keyCopy = new Variable();
            $keyCopy->copyFrom($key);
            if (null !== $userdata) {
                $userdataCopy = new Variable();
                $userdataCopy->copyFrom($userdata);
                $result = VmClosureCall::invokeDirect($context, $closure, $value, $keyCopy, $userdataCopy);
            } else {
                $result = VmClosureCall::invokeDirect($context, $closure, $value, $keyCopy);
            }
            if (Variable::TYPE_BOOLEAN === $result->type && !$result->toBool()) {
                return false;
            }
        }

        return true;
    }

    public static function walkArrayRecursiveString(HashTable $table, Internal $fn): bool
    {
        foreach ($table->iterateKeyed(true) as [$key, $value]) {
            if (Variable::TYPE_ARRAY === $value->type) {
                $value->separateArrayForWrite();
                if (!self::walkArrayRecursiveString($value->toArray(), $fn)) {
                    return false;
                }
                continue;
            }
            $keyCopy = new Variable();
            $keyCopy->copyFrom($key);
            $result = VmInternalCall::invoke($fn, $value, $keyCopy);
            if (Variable::TYPE_BOOLEAN === $result->type && !$result->toBool()) {
                return false;
            }
        }

        return true;
    }

    public static function walkArrayRecursiveUserFunction(
        \PHPCompiler\VM\Context $context,
        HashTable $table,
        \PHPCompiler\Func\PHP $userFn,
        ?Variable $userdata
    ): bool {
        foreach ($table->iterateKeyed(false) as [$key, $value]) {
            if (Variable::TYPE_ARRAY === $value->type) {
                $value->separateArrayForWrite();
                if (!self::walkArrayRecursiveUserFunction($context, $value->toArray(), $userFn, $userdata)) {
                    return false;
                }
                continue;
            }
            $keyCopy = new Variable();
            $keyCopy->copyFrom($key);
            if (null !== $userdata) {
                $userdataCopy = new Variable();
                $userdataCopy->copyFrom($userdata);
                $result = VmUserCall::invokeDirect($context, $userFn, $value, $keyCopy, $userdataCopy);
            } else {
                $result = VmUserCall::invokeDirect($context, $userFn, $value, $keyCopy);
            }
            if (Variable::TYPE_BOOLEAN === $result->type && !$result->toBool()) {
                return false;
            }
        }

        return true;
    }

    public static function walkObjectRecursiveClosure(
        \PHPCompiler\VM\Context $context,
        ObjectEntry $object,
        Frame $frame,
        \PHPCompiler\VM\ClosureState $closure,
        ?Variable $userdata
    ): bool {
        $iterator = self::objectIterator($object, $context->runtime->vm(), $frame);
        $iterator->reset();
        $valueByRef = self::callbackValueByRef($closure);
        while ($iterator->valid()) {
            $value = $iterator->currentValue($valueByRef);
            if (Variable::TYPE_ARRAY === $value->type) {
                $value->separateArrayForWrite();
                if (!self::walkArrayRecursiveClosure($context, $value->toArray(), $closure, $userdata)) {
                    return false;
                }
                continue;
            }
            $keyCopy = $iterator->currentKey();
            if (null !== $userdata) {
                $userdataCopy = new Variable();
                $userdataCopy->copyFrom($userdata);
                $result = $valueByRef
                    ? VmClosureCall::invokeDirect($context, $closure, $value, $keyCopy, $userdataCopy)
                    : VmClosureCall::invoke($context, $closure, $value, $keyCopy, $userdataCopy);
            } else {
                $result = $valueByRef
                    ? VmClosureCall::invokeDirect($context, $closure, $value, $keyCopy)
                    : VmClosureCall::invoke($context, $closure, $value, $keyCopy);
            }
            if (Variable::TYPE_BOOLEAN === $result->type && !$result->toBool()) {
                return false;
            }
        }

        return true;
    }

    public static function walkObjectRecursiveString(
        ObjectEntry $object,
        Frame $frame,
        Internal $fn
    ): bool {
        $iterator = self::objectIterator($object, $frame->vmContext->runtime->vm(), $frame);
        $iterator->reset();
        while ($iterator->valid()) {
            $value = $iterator->currentValue(false);
            if (Variable::TYPE_ARRAY === $value->type) {
                $value->separateArrayForWrite();
                if (!self::walkArrayRecursiveString($value->toArray(), $fn)) {
                    return false;
                }
                continue;
            }
            $keyCopy = $iterator->currentKey();
            $result = VmInternalCall::invoke($fn, $value, $keyCopy);
            if (Variable::TYPE_BOOLEAN === $result->type && !$result->toBool()) {
                return false;
            }
        }

        return true;
    }

    public static function walkObjectRecursiveUserFunction(
        \PHPCompiler\VM\Context $context,
        ObjectEntry $object,
        Frame $frame,
        \PHPCompiler\Func\PHP $userFn,
        ?Variable $userdata
    ): bool {
        $iterator = self::objectIterator($object, $context->runtime->vm(), $frame);
        $iterator->reset();
        $valueByRef = self::callbackValueByRef($userFn);
        while ($iterator->valid()) {
            $value = $iterator->currentValue($valueByRef);
            if (Variable::TYPE_ARRAY === $value->type) {
                $value->separateArrayForWrite();
                if (!self::walkArrayRecursiveUserFunction($context, $value->toArray(), $userFn, $userdata)) {
                    return false;
                }
                continue;
            }
            $keyCopy = $iterator->currentKey();
            if (null !== $userdata) {
                $userdataCopy = new Variable();
                $userdataCopy->copyFrom($userdata);
                $result = VmUserCall::invokeDirect($context, $userFn, $value, $keyCopy, $userdataCopy);
            } else {
                $result = VmUserCall::invokeDirect($context, $userFn, $value, $keyCopy);
            }
            if (Variable::TYPE_BOOLEAN === $result->type && !$result->toBool()) {
                return false;
            }
        }

        return true;
    }

    public static function walkArrayFlatClosure(
        \PHPCompiler\VM\Context $context,
        HashTable $table,
        \PHPCompiler\VM\ClosureState $closure,
        ?Variable $userdata
    ): bool {
        foreach ($table->iterateKeyed(false) as [$key, $value]) {
            $keyCopy = new Variable();
            $keyCopy->copyFrom($key);
            if (null !== $userdata) {
                $userdataCopy = new Variable();
                $userdataCopy->copyFrom($userdata);
                $result = VmClosureCall::invokeDirect($context, $closure, $value, $keyCopy, $userdataCopy);
            } else {
                $result = VmClosureCall::invokeDirect($context, $closure, $value, $keyCopy);
            }
            if (Variable::TYPE_BOOLEAN === $result->type && !$result->toBool()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Object-array / invokable callbacks with caller-frame visibility (#25764).
     * Passes live value slots so by-ref &$value mutates in place (php_array_walk).
     */
    public static function walkArrayFlatVmCallable(
        Frame $frame,
        HashTable $table,
        Variable $callback,
        ?Variable $userdata,
        string $function = 'array_walk'
    ): bool {
        if (null === $frame->vmContext) {
            throw new \LogicException($function.'() requires VM context in this compiler build');
        }
        self::requireVmCallable($frame, $callback, $function);
        $context = $frame->vmContext;
        foreach ($table->iterateKeyed(false) as [$key, $value]) {
            $keyCopy = new Variable();
            $keyCopy->copyFrom($key);
            if (null !== $userdata) {
                $userdataCopy = new Variable();
                $userdataCopy->copyFrom($userdata);
                $result = VmCallable::invokeAsWithScope(
                    $function,
                    $context,
                    $frame,
                    $callback,
                    $value,
                    $keyCopy,
                    $userdataCopy
                );
            } else {
                $result = VmCallable::invokeAsWithScope(
                    $function,
                    $context,
                    $frame,
                    $callback,
                    $value,
                    $keyCopy
                );
            }
            if (Variable::TYPE_BOOLEAN === $result->type && !$result->toBool()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Recursive object-array / invokable walk (#25764).
     */
    public static function walkArrayRecursiveVmCallable(
        Frame $frame,
        HashTable $table,
        Variable $callback,
        ?Variable $userdata,
        string $function = 'array_walk_recursive'
    ): bool {
        if (null === $frame->vmContext) {
            throw new \LogicException($function.'() requires VM context in this compiler build');
        }
        self::requireVmCallable($frame, $callback, $function);
        $context = $frame->vmContext;
        foreach ($table->iterateKeyed(false) as [$key, $value]) {
            if (Variable::TYPE_ARRAY === $value->type) {
                $value->separateArrayForWrite();
                if (!self::walkArrayRecursiveVmCallable(
                    $frame,
                    $value->toArray(),
                    $callback,
                    $userdata,
                    $function
                )) {
                    return false;
                }
                continue;
            }
            $keyCopy = new Variable();
            $keyCopy->copyFrom($key);
            if (null !== $userdata) {
                $userdataCopy = new Variable();
                $userdataCopy->copyFrom($userdata);
                $result = VmCallable::invokeAsWithScope(
                    $function,
                    $context,
                    $frame,
                    $callback,
                    $value,
                    $keyCopy,
                    $userdataCopy
                );
            } else {
                $result = VmCallable::invokeAsWithScope(
                    $function,
                    $context,
                    $frame,
                    $callback,
                    $value,
                    $keyCopy
                );
            }
            if (Variable::TYPE_BOOLEAN === $result->type && !$result->toBool()) {
                return false;
            }
        }

        return true;
    }

    public static function requireVmCallable(Frame $frame, Variable $callback, string $function): void
    {
        $callback = $callback->resolveIndirect();
        if (null === $frame->vmContext) {
            throw new \LogicException($function.'() requires VM context in this compiler build');
        }
        if (Variable::TYPE_ARRAY !== $callback->type) {
            if (Variable::TYPE_OBJECT === $callback->type
                && VmCallable::isCallable($frame->vmContext, $callback, false, null, $frame)
            ) {
                return;
            }
            throw new \TypeError(
                $function.'(): Argument #2 ($callback) must be a valid callback, no array or string given'
            );
        }
        if (VmCallable::isCallable($frame->vmContext, $callback, false, null, $frame)) {
            return;
        }
        VmCallable::throwIfInaccessibleMethodCallback(
            $frame->vmContext,
            $callback,
            $function,
            2,
            $frame
        );
        throw new \TypeError(
            $function.'(): Argument #2 ($callback) must be a valid callback, no array or string given'
        );
    }

    public static function isGeneralVmCallable(Variable $callback): bool
    {
        $callback = $callback->resolveIndirect();

        return Variable::TYPE_ARRAY === $callback->type
            || (Variable::TYPE_OBJECT === $callback->type && !VmClosureCall::isClosure($callback));
    }

    public static function walkObjectFlatClosure(
        \PHPCompiler\VM\Context $context,
        ObjectEntry $object,
        Frame $frame,
        \PHPCompiler\VM\ClosureState $closure,
        ?Variable $userdata
    ): bool {
        $iterator = self::objectIterator($object, $context->runtime->vm(), $frame);
        $iterator->reset();
        $valueByRef = self::callbackValueByRef($closure);
        while ($iterator->valid()) {
            $keyCopy = $iterator->currentKey();
            $value = $iterator->currentValue($valueByRef);
            if (null !== $userdata) {
                $userdataCopy = new Variable();
                $userdataCopy->copyFrom($userdata);
                $result = $valueByRef
                    ? VmClosureCall::invokeDirect($context, $closure, $value, $keyCopy, $userdataCopy)
                    : VmClosureCall::invoke($context, $closure, $value, $keyCopy, $userdataCopy);
            } else {
                $result = $valueByRef
                    ? VmClosureCall::invokeDirect($context, $closure, $value, $keyCopy)
                    : VmClosureCall::invoke($context, $closure, $value, $keyCopy);
            }
            if (Variable::TYPE_BOOLEAN === $result->type && !$result->toBool()) {
                return false;
            }
        }

        return true;
    }
}
