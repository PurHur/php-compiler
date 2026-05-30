<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * PHP 8.4 lazy proxy initialization (#3317).
 *
 * @see Zend/zend_lazy_objects.c
 */
final class LazyObjectSupport
{
    public static function createProxy(ClassEntry $class, ClosureState $initializer): ObjectEntry
    {
        $object = new ObjectEntry($class);
        $object->constructed = false;
        $object->lazyInitializer = $initializer;
        $object->lazyPending = true;

        return $object;
    }

    public static function ensureInitialized(\PHPCompiler\VM $vm, ObjectEntry $object): void
    {
        if (!$object->lazyPending || null === $object->lazyInitializer) {
            return;
        }

        $initializer = $object->lazyInitializer;
        $object->lazyInitializer = null;

        $result = $vm->invokeClosure($initializer);
        $result = $result->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $result->type) {
            throw new \LogicException('Lazy object initializer must return an object');
        }
        $real = $result->toObject();
        if (strtolower($real->class->name) !== strtolower($object->class->name)) {
            throw new \LogicException(
                sprintf(
                    'Lazy object initializer returned %s, expected instance of %s',
                    $real->class->name,
                    $object->class->name
                )
            );
        }

        foreach ($real->getRawProperties() as $name => $value) {
            $object->getProperty($name)->copyFrom($value);
        }
        $object->constructed = true;
        $object->lazyPending = false;
    }
}
