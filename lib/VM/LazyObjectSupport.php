<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\TypeCheck;

/**
 * PHP 8.4 lazy proxy and ghost initialization (#3317, #4026, #5318, #6708).
 *
 * VM stores lazy state on {@see ObjectEntry}; JIT/AOT use matching {@code __object__} header fields.
 *
 * @see Zend/zend_lazy_objects.c
 */
final class LazyObjectSupport
{
    public static function classUsesLazyGhostTrait(ClassEntry $class): bool
    {
        return $class->usesLazyGhostTrait;
    }

    /**
     * createLazyGhost()/createLazyProxy()/ReflectionClass lazy factories (#6708).
     */
    public static function resolveClassForLazyFactory(
        Context $ctx,
        string $className,
        string $functionName,
        bool $proxy = false
    ): ClassEntry {
        $entry = $ctx->classes[strtolower($className)] ?? null;
        if (null === $entry) {
            throw new \ValueError(
                $functionName.'(): Argument #1 ($class) must be a valid class name, '
                .var_export($className, true).' given'
            );
        }
        if ($entry->isTrait || $entry->isEnum) {
            $kind = $proxy ? 'proxy' : 'ghost';

            throw new \LogicException('Cannot create lazy '.$kind.' of '.$className);
        }
        if ($entry->isInterface && !$proxy) {
            throw new \LogicException('Cannot create lazy ghost of '.$className);
        }

        return $entry;
    }

    public static function extractRequiredCallable(
        Variable $arg,
        string $functionName,
        int $argNum,
        string $paramName
    ): ClosureState {
        $arg = $arg->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $arg->type) {
            throw new \TypeError(
                $functionName.'(): Argument #'.$argNum.' ($'.$paramName.') must be of type callable, '
                .EnumCaseSupport::typeNameForVariable($arg).' given'
            );
        }
        $initObject = $arg->toObject();
        if (null === $initObject->closureState) {
            throw new \TypeError(
                $functionName.'(): Argument #'.$argNum.' ($'.$paramName.') must be of type callable, '
                .$initObject->class->name.' given'
            );
        }

        return $initObject->closureState;
    }

    public static function extractOptionalCallable(
        Variable $arg,
        string $functionName,
        int $argNum,
        string $paramName
    ): ?ClosureState {
        $arg = $arg->resolveIndirect();
        if ($arg->isUndefined() || Variable::TYPE_NULL === $arg->type) {
            return null;
        }

        return self::extractRequiredCallable($arg, $functionName, $argNum, $paramName);
    }

    public static function createProxy(ClassEntry $class, ClosureState $initializer): ObjectEntry
    {
        $object = new ObjectEntry($class);
        $object->constructed = false;
        $object->lazyInitializer = $initializer;
        $object->lazyPending = true;
        $object->lazyGhost = false;
        $object->lazyResetInitializer = $initializer;
        self::clearLazyRawInitializedProperties($object);

        return $object;
    }

    public static function createGhost(ClassEntry $class, ?ClosureState $initializer): ObjectEntry
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
        $object->lazyResetInitializer = $initializer;
        self::clearLazyRawInitializedProperties($object);

        return $object;
    }

    public static function ensureInitialized(\PHPCompiler\VM $vm, ObjectEntry $object): void
    {
        if (!$object->lazyPending) {
            return;
        }
        $ctx = $vm->context;
        $prevLazyInit = $ctx->lazyInitializingObject;
        $ctx->lazyInitializingObject = $object;
        try {
            self::ensureInitializedInner($vm, $object);
        } finally {
            $ctx->lazyInitializingObject = $prevLazyInit;
        }
    }

    /**
     * Persist initializer Throwable for ReflectionClass::getLazyInitializationException() (#6514).
     *
     * @see Zend/zend_lazy_objects.c — stored init exception on lazy object
     */
    public static function captureLazyInitException(ObjectEntry $object, Variable $thrown): void
    {
        if (null !== $object->lazyInitException) {
            return;
        }
        $resolved = $thrown->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $resolved->type) {
            return;
        }
        $object->lazyInitException = $resolved->toObject();
        if (null !== $object->lazyResetInitializer) {
            $object->lazyInitializer = $object->lazyResetInitializer;
        }
        $object->lazyPending = true;
        $object->constructed = false;
    }

    public static function isLazyObject(ObjectEntry $object): bool
    {
        return $object->lazyPending
            || null !== $object->lazyResetInitializer
            || null !== $object->lazyInitException;
    }

    /** ReflectionClass::getLazyInitializationException() — VM (#6514). */
    public static function getLazyInitializationException(ObjectEntry $object): Variable
    {
        $out = new Variable();
        if (null === $object->lazyInitException) {
            $out->null();

            return $out;
        }
        $out->object($object->lazyInitException);

        return $out;
    }

    private static function ensureInitializedInner(\PHPCompiler\VM $vm, ObjectEntry $object): void
    {
        if (!$object->lazyPending) {
            return;
        }
        if (null === $object->lazyInitializer) {
            self::markAsInitialized($object);

            return;
        }

        $initializer = $object->lazyInitializer;
        self::archiveResetInitializer($object, $initializer);
        $object->lazyInitializer = null;

        if ($object->lazyGhost) {
            self::applyPropertyDefaults($object);
            $ghostArg = new Variable(Variable::TYPE_OBJECT);
            $ghostArg->object($object);
            $ctx = $vm->context;
            $prev = $ctx->lazyGhostInitializing;
            $ctx->lazyGhostInitializing = $object;
            try {
                $result = $vm->invokeClosure($initializer, $ghostArg);
            } finally {
                $ctx->lazyGhostInitializing = $prev;
            }
            $result = $result->resolveIndirect();
            if (!$result->isUndefined() && Variable::TYPE_NULL !== $result->type) {
                // php-src ignores object returns from ghost initializers (#12309, zend_lazy_objects.c).
                if (Variable::TYPE_OBJECT !== $result->type) {
                    throw new \LogicException('Lazy object initializer must return NULL or no value');
                }
            }
            $object->constructed = true;
            $object->lazyPending = false;
            $object->lazyInitException = null;
            self::clearLazyRawInitializedProperties($object);

            return;
        }

        $proxyArg = new Variable(Variable::TYPE_OBJECT);
        $proxyArg->object($object);
        $result = $vm->invokeClosure($initializer, $proxyArg);
        $result = $result->resolveIndirect();
        if ($result->isUndefined() || Variable::TYPE_NULL === $result->type) {
            // Concrete lazy proxies may mutate the placeholder in place (#12310, zend_lazy_objects.c ghost parity).
            if ($object->class->isInterface) {
                throw new \TypeError(
                    'Lazy proxy factory must return an instance of a class compatible with '
                    .$object->class->name.', null returned'
                );
            }
            $object->constructed = true;
            $object->lazyPending = false;
            $object->lazyInitException = null;
            self::clearLazyRawInitializedProperties($object);

            return;
        }
        if (Variable::TYPE_OBJECT !== $result->type) {
            throw new \LogicException('Lazy object initializer must return an object');
        }
        $real = $result->toObject();
        if ($object->class->isInterface) {
            if (!InterfaceCheck::entryImplements($real->class, strtolower($object->class->name), $vm->context)) {
                throw new \LogicException(
                    sprintf(
                        'Lazy object initializer returned %s, expected instance of %s',
                        $real->class->name,
                        $object->class->name
                    )
                );
            }
            $object->lazyInterfaceProxyTarget = $real;
        } else {
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
        }
        $object->constructed = true;
        $object->lazyPending = false;
        $object->lazyInitException = null;
        self::clearLazyRawInitializedProperties($object);
    }

    public static function getInitializer(ObjectEntry $object): ?ClosureState
    {
        if ($object->lazyPending && null !== $object->lazyInitializer) {
            return $object->lazyInitializer;
        }

        return null;
    }

    /** ReflectionClass::getLazyProxyFactory — pending proxy factory only (#6776). */
    public static function getProxyFactory(ObjectEntry $object): ?ClosureState
    {
        if ($object->lazyGhost) {
            return null;
        }
        if (!$object->lazyPending || null === $object->lazyResetInitializer) {
            return null;
        }

        return $object->lazyInitializer;
    }

    /** Zend zend_object_is_lazy && !zend_lazy_object_initialized (#6054, #6068). */
    public static function isUninitializedLazyObject(ObjectEntry $object): bool
    {
        return $object->lazyPending;
    }

    /** Zend zend_lazy_object_get_instance — proxy real instance or ghost shell (#7054, #9999). */
    public static function getLazyInstance(ObjectEntry $object): ObjectEntry
    {
        return $object->lazyInterfaceProxyTarget ?? $object;
    }

    /**
     * ReflectionProperty::isLazy — IS_PROP_LAZY probe without triggering init (#6515).
     *
     * @see ext/reflection/php_reflection.c ReflectionProperty::isLazy
     */
    public static function isPropertyLazy(ObjectEntry $object, string $propertyName): bool
    {
        if (isset($object->lazyRawInitializedProperties[$propertyName])) {
            return false;
        }
        if (!$object->lazyPending && null === $object->lazyResetInitializer) {
            return false;
        }

        if (!$object->lazyGhost) {
            return $object->lazyPending;
        }

        if ($object->lazyPending) {
            return true;
        }

        if (!$object->hasProperty($propertyName)) {
            return false;
        }

        $slot = $object->getProperty($propertyName)->resolveIndirect();

        return $slot->isUndefined() || TypedPropertyCheck::isUninitialized($slot);
    }

    /**
     * Zend zend_lazy_object_mark_as_initialized — skip initializer, apply defaults (#5968).
     */
    public static function markAsInitialized(ObjectEntry $object): ObjectEntry
    {
        if (!$object->lazyPending) {
            return $object;
        }
        self::applyPropertyDefaults($object);
        self::archiveResetInitializer($object, $object->lazyInitializer);
        $object->lazyInitializer = null;
        $object->lazyPending = false;
        $object->constructed = true;
        $object->lazyInitException = null;
        self::clearLazyRawInitializedProperties($object);

        return $object;
    }

    /** ReflectionClass::SKIP_DESTRUCTOR (PHP 8.4). */
    public const SKIP_DESTRUCTOR = 1;

    /**
     * Zend zend_object_make_lazy ghost reset path (#5968).
     */
    public static function resetAsLazyGhost(
        \PHPCompiler\VM $vm,
        ObjectEntry $object,
        ClosureState $initializer,
        int $options = 0
    ): void {
        if ($object->lazyPending) {
            $object->lazyInitializer = $initializer;
            $object->lazyResetInitializer = $initializer;

            return;
        }

        if (0 === ($options & self::SKIP_DESTRUCTOR) && !$object->destructorInvoked) {
            $vm->invokeUserDestructor($object);
        }

        foreach ($object->getRawProperties() as $var) {
            $var->reset();
            $var->type = Variable::TYPE_UNDEFINED;
        }

        $object->constructed = false;
        $object->destructorInvoked = false;
        $object->lazyInitializer = $initializer;
        $object->lazyPending = true;
        $object->lazyGhost = true;
        $object->lazyResetInitializer = $initializer;
        self::clearLazyRawInitializedProperties($object);
    }

    /**
     * Zend zend_object_make_lazy proxy reset path (#6776).
     */
    public static function resetAsLazyProxy(
        \PHPCompiler\VM $vm,
        ObjectEntry $object,
        ClosureState $factory,
        int $options = 0
    ): void {
        if ($object->lazyPending) {
            throw new \ReflectionException('Object is already lazy');
        }

        if (0 === ($options & self::SKIP_DESTRUCTOR) && !$object->destructorInvoked) {
            $vm->invokeUserDestructor($object);
        }

        foreach ($object->getRawProperties() as $var) {
            $var->reset();
            $var->type = Variable::TYPE_UNDEFINED;
        }

        $object->constructed = false;
        $object->destructorInvoked = false;
        $object->lazyInitializer = $factory;
        $object->lazyPending = true;
        $object->lazyGhost = false;
        $object->lazyResetInitializer = $factory;
        self::clearLazyRawInitializedProperties($object);
    }

    /**
     * Zend reflection_class_reset_as_lazy_object — restore uninitialized lazy state (#6125).
     */
    public static function resetAsLazyObject(\PHPCompiler\VM $vm, ObjectEntry $object): void
    {
        if ($object->lazyPending) {
            throw new \ReflectionException('Object is lazy and non-initialized');
        }

        $initializer = $object->lazyResetInitializer;
        if (null === $initializer) {
            throw new \TypeError('ReflectionClass::resetAsLazyObject(): Argument #1 ($object) must be a lazy object');
        }

        if (!$object->destructorInvoked) {
            $vm->invokeUserDestructor($object);
        }

        foreach ($object->getRawProperties() as $var) {
            $var->reset();
            $var->type = Variable::TYPE_UNDEFINED;
        }

        $object->constructed = false;
        $object->destructorInvoked = false;
        $object->lazyInitializer = $initializer;
        $object->lazyPending = true;
        self::clearLazyRawInitializedProperties($object);
    }

    private static function archiveResetInitializer(ObjectEntry $object, ?ClosureState $initializer): void
    {
        if (null !== $initializer) {
            $object->lazyResetInitializer = $initializer;
        }
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

    /**
     * ReflectionProperty::setRawValueWithoutLazyInitialization — backing write without init (#7095).
     *
     * @see ext/reflection/php_reflection.c reflection_property_set_raw_value_without_lazy_initialization
     */
    public static function setRawValueWithoutLazyInitialization(
        ObjectEntry $object,
        ClassProperty $meta,
        Variable $value,
        bool $strictTypes
    ): void {
        if (!$object->lazyPending || !$object->lazyGhost) {
            throw new \Error('Cannot set raw value on a non-lazy object');
        }
        if ($meta->propertyHookVirtual) {
            throw new \Error('Cannot set raw value on virtual property');
        }
        if (!$object->hasProperty($meta->name)) {
            throw new \LogicException('Undefined property in this compiler build');
        }
        $slot = $object->getProperty($meta->name);
        $write = self::coerceRawPropertyWrite($value, $meta, $strictTypes);
        $slot->copyFrom($write);
        $object->lazyRawInitializedProperties[$meta->name] = true;
    }

    private static function coerceRawPropertyWrite(
        Variable $value,
        ClassProperty $meta,
        bool $strictTypes
    ): Variable {
        $probe = new Variable();
        $probe->copyFrom($value);
        $target = $probe->resolveIndirect();
        $typeMeta = $meta->prototype->resolveIndirect();
        $target->typeConstraint = $typeMeta->typeConstraint;
        $target->classConstraint = $typeMeta->classConstraint;
        $target->literalBoolType = $typeMeta->literalBoolType;
        $target->unionTypeConstraints = $typeMeta->unionTypeConstraints;
        $target->declaredTypeLabel = $typeMeta->declaredTypeLabel;
        $target->genericArrayTypeSpec = $typeMeta->genericArrayTypeSpec;
        $target->dnfArms = $typeMeta->dnfArms;
        TypeCheck::coercePropertyWrite($probe, $strictTypes);

        return $probe;
    }

    public static function skipLazyInitForPropertyRead(ObjectEntry $object, string $propertyName): bool
    {
        return $object->lazyPending
            && isset($object->lazyRawInitializedProperties[$propertyName]);
    }

    /**
     * ReflectionProperty::skipLazyInitialization — default value without running initializer (#7094).
     *
     * @see ext/reflection/php_reflection.c reflection_property_skip_lazy_initialization
     */
    public static function skipLazyInitialization(ObjectEntry $object, ClassProperty $meta): void
    {
        if (!self::isPropertyLazy($object, $meta->name)) {
            return;
        }
        if (!$object->hasProperty($meta->name)) {
            throw new \LogicException('Undefined property in this compiler build');
        }
        $slot = $object->getProperty($meta->name);
        if (null !== $meta->default && !$meta->hasRuntimeDefaultInit()) {
            $slot->copyFrom($meta->default);
        }
        $object->lazyRawInitializedProperties[$meta->name] = true;
        if ($object->lazyPending && self::allLazyPropertiesResolved($object)) {
            self::markAsInitialized($object);
        }
    }

    private static function allLazyPropertiesResolved(ObjectEntry $object): bool
    {
        foreach ($object->class->properties as $property) {
            if ($property->propertyHookVirtual) {
                continue;
            }
            if (!isset($object->getRawProperties()[$property->name])) {
                continue;
            }
            if (self::isPropertyLazy($object, $property->name)) {
                return false;
            }
        }

        return true;
    }

    private static function clearLazyRawInitializedProperties(ObjectEntry $object): void
    {
        $object->lazyRawInitializedProperties = [];
    }

    /** Set declared properties on an uninitialized lazy ghost without running the initializer (#6531). */
    public static function applyInstanceProperties(ObjectEntry $object, HashTable $properties): void
    {
        foreach ($properties->iterateKeyed(true) as [$key, $value]) {
            if (Variable::TYPE_STRING !== $key->type) {
                continue;
            }
            $name = $key->toString();
            if (!$object->hasProperty($name)) {
                continue;
            }
            $object->getProperty($name)->copyFrom($value);
        }
    }
}
