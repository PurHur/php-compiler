<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\VM;

/**
 * VM object refcount + __destruct() scheduling (Zend zend_objects_destroy_object subset, #3144).
 *
 * @see https://github.com/php/php-src/blob/master/Zend/zend_objects_API.c zend_objects_destroy_object
 */
final class ObjectLifetime
{
    private static ?VM $vm = null;

    public static function setVm(VM $vm): void
    {
        self::$vm = $vm;
    }

    public static function clearVm(): void
    {
        self::$vm = null;
    }

    public static function addRef(ObjectEntry $object): void
    {
        ++$object->refCount;
    }

    public static function releaseRef(ObjectEntry $object): void
    {
        if ($object->refCount <= 0) {
            return;
        }
        --$object->refCount;
        if ($object->refCount > 0) {
            return;
        }
        $vm = self::$vm;
        if (null !== $vm && !$object->destructorInvoked) {
            $vm->invokeUserDestructor($object);
        }
        if (isset(ObjectRegistry::snapshot()[$object->id])) {
            ObjectRegistry::release($object);
        }
    }

    /** Request shutdown: destructors for all objects still registered (reverse creation order). */
    public static function runShutdownDestructors(): void
    {
        $vm = self::$vm;
        if (null === $vm) {
            return;
        }
        $remaining = ObjectRegistry::snapshot();
        if ([] === $remaining) {
            return;
        }
        krsort($remaining, SORT_NUMERIC);
        foreach ($remaining as $object) {
            if (!$object->destructorInvoked) {
                $vm->invokeUserDestructor($object);
            }
            if (isset(ObjectRegistry::snapshot()[$object->id])) {
                ObjectRegistry::release($object);
            }
        }
    }

    public static function releaseDirectObject(Variable $var): void
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $var->type) {
            return;
        }
        try {
            self::releaseRef($var->toObject());
        } catch (DestructorThrowCatchSignal $signal) {
            throw $signal;
        } catch (\LogicException) {
        }
    }

    /**
     * unset($var) on an owned binding: run __destruct before storage is cleared (#4096, #6456).
     *
     * @see https://github.com/php/php-src/blob/master/Zend/zend_objects.c zend_objects_destroy_object
     */
    /** Invoke __destruct before gc_collect_cycles() frees an unreachable object (#6519, zend_gc.c). */
    public static function invokeDestructorBeforeGcRelease(ObjectEntry $object): void
    {
        $vm = self::$vm;
        if (null !== $vm && !$object->destructorInvoked) {
            $vm->invokeUserDestructor($object);
        }
    }

    public static function invokeUnsetDestructor(VM $vm, Variable $var): void
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $var->type) {
            return;
        }
        try {
            $object = $var->toObject();
        } catch (\LogicException) {
            return;
        }
        if (!$object->destructorInvoked) {
            $vm->invokeUserDestructor($object);
        }
    }
}
