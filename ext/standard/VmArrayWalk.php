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
            if (Variable::TYPE_NULL !== $result->type) {
                self::replaceAtKey($table, $key, $result);
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
            $result = VmInternalCall::invoke($fn, $value);
            if (Variable::TYPE_BOOLEAN === $result->type && !$result->toBool()) {
                return false;
            }
            if (Variable::TYPE_NULL !== $result->type) {
                self::replaceAtKey($table, $key, $result);
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
        $vm = $context->runtime->vm();
        $iterator = new ObjectPropertyIterator($object, $vm, $frame);
        $iterator->reset();
        while ($iterator->valid()) {
            $value = $iterator->currentValue(true);
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

    public static function walkObjectRecursiveString(
        ObjectEntry $object,
        Frame $frame,
        Internal $fn
    ): bool {
        $vm = $frame->vmContext->runtime->vm();
        $iterator = new ObjectPropertyIterator($object, $vm, $frame);
        $iterator->reset();
        while ($iterator->valid()) {
            $value = $iterator->currentValue(true);
            if (Variable::TYPE_ARRAY === $value->type) {
                $value->separateArrayForWrite();
                if (!self::walkArrayRecursiveString($value->toArray(), $fn)) {
                    return false;
                }
                continue;
            }
            $result = VmInternalCall::invoke($fn, $value);
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
            if (Variable::TYPE_NULL !== $result->type) {
                self::replaceAtKey($table, $key, $result);
            }
        }

        return true;
    }

    public static function walkObjectFlatClosure(
        \PHPCompiler\VM\Context $context,
        ObjectEntry $object,
        Frame $frame,
        \PHPCompiler\VM\ClosureState $closure,
        ?Variable $userdata
    ): bool {
        $vm = $context->runtime->vm();
        $iterator = new ObjectPropertyIterator($object, $vm, $frame);
        $iterator->reset();
        while ($iterator->valid()) {
            $keyCopy = $iterator->currentKey();
            $value = $iterator->currentValue(true);
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

    private static function replaceAtKey(HashTable $table, Variable $key, Variable $value): void
    {
        $copy = new Variable();
        $copy->copyFrom($value);
        if (Variable::TYPE_INTEGER === $key->type) {
            $table->updateIndex($key->toInt(), $copy);
        } else {
            $table->update($key->toString(), $copy);
        }
    }
}
