<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * PHP 8.4 lazy proxy and ghost initialization (#3317, #4026).
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
        $object->lazyGhost = false;

        return $object;
    }

    public static function createGhost(ClassEntry $class, ClosureState $initializer): ObjectEntry
    {
        $object = new ObjectEntry($class);
        foreach ($object->getRawProperties() as $var) {
            $var->reset();
            $var->type = Variable::TYPE_UNDEFINED;
        }
        $object->constructed = false;
        $object->lazyInitializer = $initializer;
        $object->lazyPending = true;
        $object->lazyGhost = true;

        return $object;
    }

    public static function ensureInitialized(\PHPCompiler\VM $vm, ObjectEntry $object): void
    {
        if (!$object->lazyPending || null === $object->lazyInitializer) {
            return;
        }

        $initializer = $object->lazyInitializer;
        $object->lazyInitializer = null;

        if ($object->lazyGhost) {
            self::applyPropertyDefaults($object);
            $ghostArg = new Variable(Variable::TYPE_OBJECT);
            $ghostArg->object($object);
            $result = $vm->invokeClosure($initializer, $ghostArg);
            $result = $result->resolveIndirect();
            if (!$result->isUndefined() && Variable::TYPE_NULL !== $result->type) {
                throw new \LogicException('Lazy object initializer must return NULL or no value');
            }
            $object->constructed = true;
            $object->lazyPending = false;

            return;
        }

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

    private static function applyPropertyDefaults(ObjectEntry $object): void
    {
        foreach ($object->class->properties as $property) {
            if (!isset($object->getRawProperties()[$property->name])) {
                continue;
            }
            $var = $object->getProperty($property->name);
            if (!$var->isUndefined()) {
                continue;
            }
            if (null !== $property->default && !$property->hasRuntimeDefaultInit()) {
                $var->copyFrom($property->default);
            }
        }
    }
}
