<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\Block;
use PHPCompiler\JIT\Builtin\ErrorRaise;
use PHPCompiler\JIT\Builtin\Type\Object_;
use PHPCompiler\MethodVisibility;
use PHPCompiler\PropertyVisibility;
use PHPCompiler\SourcePreprocessor\PropertyHooks;
use PHPCompiler\VM\PropertyHookJitHelper;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Dispatch property get/set hooks lowered to __phpc_property_* methods (#3145, #3723).
 *
 * php-src: Zend/zend_property_hooks.c (VM parity via preprocessor + hook methods).
 */
final class PropertyHookDispatchLlvm
{
    /**
     * Invoke set hook instead of direct property store when applicable.
     */
    public static function emitSetHookIfNeeded(
        Context $context,
        Variable $lvalue,
        Variable $value,
        ?Block $enclosingBlock,
        ?\PHPCompiler\JIT $jit = null
    ): bool {
        if (null === $lvalue->objectPropertySlot || null === $lvalue->objectPropertyName) {
            return false;
        }
        if (null === $lvalue->objectPropertyReceiver) {
            return false;
        }
        if (PropertyHookJitHelper::isRawHookWrite($context, $lvalue->objectPropertyName, $enclosingBlock)) {
            return false;
        }
        $className = $lvalue->objectPropertyClassName ?? 'stdclass';
        $propName = $lvalue->objectPropertyName;
        $hookLc = strtolower(PropertyHooks::setHookMethodName($propName));
        $proxyName = self::resolveHookProxy($context, $className, $hookLc);
        if (null === $proxyName || self::proxyIsStatic($context, $proxyName)) {
            if (self::emitGetOnlyVirtualWriteGuard($context, $jit, $className, $propName)) {
                return true;
            }

            return false;
        }

        $receiver = new Variable(
            $context,
            Variable::TYPE_OBJECT,
            Variable::KIND_VALUE,
            $lvalue->objectPropertyReceiver
        );
        $toCall = $context->resolveFunctionProxy($proxyName);
        $prevStrict = $context->callerStrictTypes;
        $context->callerStrictTypes = $enclosingBlock?->strictTypes ?? false;
        $toCall->call($context, $receiver, $value);
        $context->callerStrictTypes = $prevStrict;

        return true;
    }

    /**
     * Invoke get hook instead of loading the backing slot when applicable.
     */
    public static function tryEmitPropertyGet(
        Context $context,
        Value $receiver,
        string $declaringClass,
        string $propertyName,
        ?Block $enclosingBlock
    ): ?Value {
        if (PropertyHookJitHelper::isRawHookWrite($context, $propertyName, $enclosingBlock)) {
            return null;
        }
        $hookLc = strtolower(PropertyHooks::getHookMethodName($propertyName));
        $proxyName = self::resolveHookProxy($context, $declaringClass, $hookLc);
        if (null === $proxyName || self::proxyIsStatic($context, $proxyName)) {
            return null;
        }

        $receiverVar = new Variable(
            $context,
            Variable::TYPE_OBJECT,
            Variable::KIND_VALUE,
            $receiver
        );
        $toCall = $context->resolveFunctionProxy($proxyName);
        $prevStrict = $context->callerStrictTypes;
        $context->callerStrictTypes = $enclosingBlock?->strictTypes ?? false;
        $hookValue = $toCall->call($context, $receiverVar);
        $context->callerStrictTypes = $prevStrict;

        return $hookValue;
    }

    /**
     * isset($obj->prop) on hooked properties (#9671, #29214, zend_std_has_property).
     * When a get hook exists, always invoke it (same-name / expression-bodied set included);
     * write-only (no get) probes the backing slot.
     */
    public static function tryEmitPropertyIsSet(
        Context $context,
        Value $receiver,
        string $declaringClass,
        string $propertyName,
        ?Block $enclosingBlock
    ): ?Value {
        $backingName = self::hookedPropertyBackingName($context, $declaringClass, $propertyName);
        if (null === $backingName) {
            return null;
        }
        $object = $context->type->object;
        assert($object instanceof Builtin\Type\Object_);
        $hookLc = strtolower(PropertyHooks::getHookMethodName($propertyName));
        $getProxy = self::resolveHookProxy($context, $declaringClass, $hookLc);
        $canCallGet = null !== $getProxy
            && !self::proxyIsStatic($context, $getProxy)
            && !PropertyHookJitHelper::isRawHookWrite($context, $propertyName, $enclosingBlock);
        if ($canCallGet) {
            return self::emitIssetViaGetHook(
                $context,
                $receiver,
                $declaringClass,
                $propertyName,
                $enclosingBlock
            );
        }
        $fetched = $object->propertyFetch($receiver, $declaringClass, $backingName);
        if (Variable::TYPE_VALUE === $fetched->type) {
            $valueMap = $context->structFieldMap['__value__'];
            $typeByte = $context->builder->load(
                $context->builder->structGep($fetched->value, $valueMap['type'])
            );
            $i8 = $context->getTypeFromString('int8');
            $nullType = $i8->constInt(\PHPCompiler\VM\Variable::TYPE_NULL, false);
            $undefType = $i8->constInt(\PHPCompiler\VM\Variable::TYPE_UNDEFINED, false);
            $notNull = $context->builder->icmp(Builder::INT_NE, $typeByte, $nullType);
            $notUndef = $context->builder->icmp(Builder::INT_NE, $typeByte, $undefType);

            return $context->builder->and($notNull, $notUndef);
        }
        // Raw-hook / write-only backing probe: isset is BP_VAR_IS (#29688).
        $savedClass = $fetched->objectPropertyClassName;
        $savedName = $fetched->objectPropertyName;
        $fetched->objectPropertyClassName = null;
        $fetched->objectPropertyName = null;
        try {
            $loaded = $context->helper->loadValue($fetched);
        } finally {
            $fetched->objectPropertyClassName = $savedClass;
            $fetched->objectPropertyName = $savedName;
        }
        $nullPtr = $context->getTypeFromString('void*')->constNull();

        return $context->builder->icmp(Builder::INT_NE, $loaded, $nullPtr);
    }

    /**
     * isset via get hook — result is non-null (#29214, zend_std_has_property).
     */
    private static function emitIssetViaGetHook(
        Context $context,
        Value $receiver,
        string $declaringClass,
        string $propertyName,
        ?Block $enclosingBlock
    ): Value {
        $i1 = $context->getTypeFromString('int1');
        $hookValue = self::tryEmitPropertyGet(
            $context,
            $receiver,
            $declaringClass,
            $propertyName,
            $enclosingBlock
        );
        if (null === $hookValue) {
            return $i1->constInt(0, false);
        }
        if (Variable::TYPE_VALUE === $hookValue->type) {
            $valueMap = $context->structFieldMap['__value__'];
            $typeByte = $context->builder->load(
                $context->builder->structGep($hookValue->value, $valueMap['type'])
            );
            $nullType = $context->getTypeFromString('int8')->constInt(
                \PHPCompiler\VM\Variable::TYPE_NULL,
                false
            );

            return $context->builder->icmp(Builder::INT_NE, $typeByte, $nullType);
        }
        $loaded = $context->helper->loadValue($hookValue);
        $nullPtr = $context->getTypeFromString('void*')->constNull();

        return $context->builder->icmp(Builder::INT_NE, $loaded, $nullPtr);
    }

    /**
     * Backing field name for isset/empty on hooked properties, or null when not hooked.
     */
    public static function hookedPropertyBackingName(
        Context $context,
        string $declaringClass,
        string $propertyName
    ): ?string {
        return PropertyHookJitHelper::hookedPropertyBackingName(
            $context->runtime->vmContext->propertyHookRegistry,
            $declaringClass,
            $propertyName
        );
    }

    /**
     * Invoke static get hook instead of loading the backing global (#4807).
     */
    public static function tryEmitStaticPropertyGet(
        Context $context,
        string $declaringClass,
        string $propertyName,
        ?Block $enclosingBlock
    ): ?Value {
        if (PropertyHookJitHelper::isRawHookWrite($context, $propertyName, $enclosingBlock)) {
            return null;
        }
        $hookLc = strtolower(PropertyHooks::getHookMethodName($propertyName));
        $proxyName = self::resolveStaticHookProxy($context, $declaringClass, $hookLc);
        if (null === $proxyName) {
            return null;
        }
        $toCall = $context->resolveFunctionProxy($proxyName);
        $prevStrict = $context->callerStrictTypes;
        $context->callerStrictTypes = $enclosingBlock?->strictTypes ?? false;
        $hookValue = $toCall->call($context);
        $context->callerStrictTypes = $prevStrict;

        return $hookValue;
    }

    /**
     * Whether a static property has a lowered static set hook at compile time (#4807).
     */
    public static function staticPropertyHasSetHook(
        Context $context,
        string $declaringClass,
        string $propertyName
    ): bool {
        $hookLc = strtolower(PropertyHooks::setHookMethodName($propertyName));

        return null !== self::resolveStaticHookProxy($context, $declaringClass, $hookLc);
    }

    /**
     * Invoke static set hook instead of storing the backing global (#4807).
     */
    public static function emitStaticSetHookIfNeeded(
        Context $context,
        Variable $lvalue,
        Variable $value,
        ?Block $enclosingBlock,
        ?\PHPCompiler\JIT $jit = null
    ): bool {
        if (null === $lvalue->staticPropertyGlobal || null === $lvalue->staticPropertyHookClassLc) {
            return false;
        }
        $propName = $lvalue->objectPropertyName;
        if (null === $propName) {
            return false;
        }
        if (PropertyHookJitHelper::isRawHookWrite($context, $propName, $enclosingBlock)) {
            return false;
        }
        $className = $lvalue->staticPropertyHookClassLc;
        $hookLc = strtolower(PropertyHooks::setHookMethodName($propName));
        $proxyName = self::resolveStaticHookProxy($context, $className, $hookLc);
        if (null === $proxyName) {
            if (self::emitGetOnlyVirtualWriteGuard($context, $jit, $className, $propName, true)) {
                return true;
            }

            return false;
        }
        $toCall = $context->resolveFunctionProxy($proxyName);
        $prevStrict = $context->callerStrictTypes;
        $context->callerStrictTypes = $enclosingBlock?->strictTypes ?? false;
        $toCall->call($context, $value);
        $context->callerStrictTypes = $prevStrict;

        return true;
    }

    private static function resolveStaticHookProxy(Context $context, string $className, string $hookMethodLc): ?string
    {
        $proxy = self::resolveHookProxy($context, $className, $hookMethodLc);
        if (null === $proxy || !self::proxyIsStatic($context, $proxy)) {
            return null;
        }

        return $proxy;
    }

    private static function proxyIsStatic(Context $context, string $proxyName): bool
    {
        $parts = explode('::', $proxyName, 2);
        if (2 !== count($parts)) {
            return false;
        }
        $objectType = $context->type->object;
        assert($objectType instanceof Object_);
        $classId = $objectType->lookup($parts[0]);
        $flags = $objectType->methodVisibility($classId, $parts[1]);

        return ($flags & \PHPCfg\Func::FLAG_STATIC) !== 0;
    }

    private static function resolveHookProxy(Context $context, string $className, string $hookMethodLc): ?string
    {
        $objectType = $context->type->object;
        assert($objectType instanceof Object_);
        $visited = [];
        $current = strtolower(ltrim($className, '\\'));
        while (!isset($visited[$current])) {
            $visited[$current] = true;
            $proxy = $current.'::'.$hookMethodLc;
            if ($context->functionIsRegistered($proxy)) {
                return $proxy;
            }
            $parent = $objectType->parentClassLc($current);
            if (null === $parent) {
                break;
            }
            $current = $parent;
        }

        return null;
    }

    /**
     * Block stores to get-only VIRTUAL hooked properties (#4687, #26006, #29674).
     *
     * Backed get-only omits {@code set} and uses default write into the backing store
     * (zend_object_handlers.c / php.net property-hooks manual). Only virtual get-only Errors.
     * PHP-8.4: "Property … is read-only". "Must not write to virtual property" is the raw
     * backing-slot path only — not here.
     *
     * @return bool true when the store was blocked (caller must skip propertyStore)
     */
    private static function emitGetOnlyVirtualWriteGuard(
        Context $context,
        ?\PHPCompiler\JIT $jit,
        string $className,
        string $propertyName,
        bool $staticProperty = false
    ): bool {
        $lcClass = strtolower(ltrim($className, '\\'));
        $propLc = strtolower($propertyName);
        $meta = $context->runtime->vmContext->propertyHookRegistry[$lcClass][$propertyName]
            ?? $context->runtime->vmContext->propertyHookRegistry[$lcClass][$propLc]
            ?? null;
        if (!is_array($meta) || isset($meta['set']) || !isset($meta['get'])) {
            return false;
        }
        // Backed get-only: allow default propertyStore (ctor promo + later assigns) — #29674.
        if (empty($meta['virtual'])) {
            return false;
        }
        if (self::propertyHasDistinctAsymmetricSetVisibility($context, $className, $propertyName, $staticProperty)) {
            return self::emitAsymmetricSetVisibilityWriteGuard(
                $context,
                $jit,
                $className,
                $propertyName,
                $staticProperty
            );
        }
        $getLc = strtolower(PropertyHooks::getHookMethodName($propertyName));
        $getProxy = $staticProperty
            ? self::resolveStaticHookProxy($context, $className, $getLc)
            : self::resolveHookProxy($context, $className, $getLc);
        if (null === $getProxy || (!$staticProperty && self::proxyIsStatic($context, $getProxy))) {
            return false;
        }

        $message = sprintf('Property %s::$%s is read-only', $className, $propertyName);
        if (null !== $jit && [] !== $context->tryCatch->handlerStack) {
            TryCatchHelper::emitCatchableErrorMessage($context, $jit, $message);
        } else {
            ErrorRaise::emitRaise($context, $message);
        }

        return true;
    }

    private static function propertyHasDistinctAsymmetricSetVisibility(
        Context $context,
        string $className,
        string $propertyName,
        bool $staticProperty
    ): bool {
        $objectType = $context->type->object;
        if (!$objectType instanceof Object_) {
            return false;
        }
        $classId = $objectType->lookup($className);
        $readVis = $staticProperty
            ? $objectType->staticPropertyVisibility($classId, $propertyName)
            : $objectType->propertyVisibility($classId, $propertyName);
        $setVis = PropertyVisibility::effectiveSetVisibility(
            $readVis,
            $staticProperty
                ? $objectType->staticPropertySetVisibility($classId, $propertyName)
                : $objectType->propertySetVisibility($classId, $propertyName)
        );

        return $setVis !== MethodVisibility::mask($readVis);
    }

    /**
     * Get-only virtual hook write on asymmetric property — private(set) message not read-only (#9842).
     *
     * @return bool true when the store was blocked
     */
    private static function emitAsymmetricSetVisibilityWriteGuard(
        Context $context,
        ?\PHPCompiler\JIT $jit,
        string $className,
        string $propertyName,
        bool $staticProperty
    ): bool {
        $objectType = $context->type->object;
        if (!$objectType instanceof Object_) {
            return false;
        }
        $classId = $objectType->lookup($className);
        $readVis = $staticProperty
            ? $objectType->staticPropertyVisibility($classId, $propertyName)
            : $objectType->propertyVisibility($classId, $propertyName);
        $getVis = $staticProperty
            ? $objectType->staticPropertyGetVisibility($classId, $propertyName)
            : $objectType->propertyGetVisibility($classId, $propertyName);
        $effectiveRead = PropertyVisibility::effectiveGetVisibility($readVis, $getVis);
        $setVis = PropertyVisibility::effectiveSetVisibility(
            $readVis,
            $staticProperty
                ? $objectType->staticPropertySetVisibility($classId, $propertyName)
                : $objectType->propertySetVisibility($classId, $propertyName)
        );
        if ($setVis === MethodVisibility::mask($effectiveRead)) {
            return false;
        }
        $declaringLc = strtolower(ltrim($className, '\\'));
        $callerLc = '' !== $context->scope->className
            ? strtolower(ltrim($context->scope->className, '\\'))
            : null;
        $callerDisplay = '' !== $context->scope->className
            ? ltrim($context->scope->className, '\\')
            : null;
        try {
            PropertyVisibility::assertWritable(
                $setVis,
                $callerLc,
                $declaringLc,
                $className,
                $propertyName,
                static fn (string $child, string $parent): bool => self::isSubclassOfForAsymmetricGuard(
                    $objectType,
                    $child,
                    $parent
                ),
                MethodVisibility::mask($effectiveRead),
                $staticProperty
                    ? $objectType->staticPropertyAsymmetricExplicitRead($classId, $propertyName)
                    : $objectType->propertyAsymmetricExplicitRead($classId, $propertyName),
                $callerDisplay
            );
        } catch (\LogicException $e) {
            $message = $e->getMessage();
            if (null !== $jit && [] !== $context->tryCatch->handlerStack) {
                TryCatchHelper::emitCatchableErrorMessage($context, $jit, $message);
            } else {
                ErrorRaise::emitRaise($context, $message);
            }

            return true;
        }

        return false;
    }

    private static function isSubclassOfForAsymmetricGuard(
        Object_ $objectType,
        string $childLc,
        string $parentLc
    ): bool {
        $current = $childLc;
        for ($depth = 0; $depth < 64; ++$depth) {
            if ($current === $parentLc) {
                return true;
            }
            $parent = $objectType->parentClassLc($current);
            if (null === $parent) {
                return false;
            }
            $current = $parent;
        }

        return false;
    }

    /**
     * Block unset() on hooked properties without an unset hook (#6425, #6491, #26373).
     * Matches Zend: backed get+set and virtual hooks all Error unless an unset hook exists.
     *
     * @return bool true when unset was blocked (caller must skip propertyStore)
     */
    public static function emitVirtualHookUnsetGuard(
        Context $context,
        string $className,
        string $propertyName,
        ?\PHPCompiler\JIT $jit = null
    ): bool {
        $lcClass = strtolower(ltrim($className, '\\'));
        $propLc = strtolower($propertyName);
        $meta = $context->runtime->vmContext->propertyHookRegistry[$lcClass][$propertyName]
            ?? $context->runtime->vmContext->propertyHookRegistry[$lcClass][$propLc]
            ?? null;
        if (!is_array($meta)) {
            return false;
        }
        $hasSet = isset($meta['set']);
        $hasGet = isset($meta['get']);
        if (!$hasSet && !$hasGet) {
            return false;
        }
        if (isset($meta['unset'])) {
            return false;
        }
        $unsetLc = strtolower(PropertyHooks::unsetHookMethodName($propertyName));
        $unsetProxy = self::resolveHookProxy($context, $className, $unsetLc)
            ?? self::resolveStaticHookProxy($context, $className, $unsetLc);
        if (null !== $unsetProxy) {
            return false;
        }

        $message = sprintf('Cannot unset hooked property %s::$%s', $className, $propertyName);
        if (null !== $jit && [] !== $context->tryCatch->handlerStack) {
            TryCatchHelper::emitCatchableErrorMessage($context, $jit, $message);
        } else {
            ErrorRaise::emitRaise($context, $message);
        }

        return true;
    }

    /**
     * Block reads/isset/empty on write-only virtual hooked properties (#6484, zend_property_hooks.c).
     *
     * @return bool true when the read was blocked (caller must skip property load)
     */
    public static function emitWriteOnlyVirtualReadGuard(
        Context $context,
        ?\PHPCompiler\JIT $jit,
        string $className,
        string $propertyName,
        bool $staticProperty = false
    ): bool {
        $lcClass = strtolower(ltrim($className, '\\'));
        $propLc = strtolower($propertyName);
        $meta = $context->runtime->vmContext->propertyHookRegistry[$lcClass][$propertyName]
            ?? $context->runtime->vmContext->propertyHookRegistry[$lcClass][$propLc]
            ?? null;
        if (is_array($meta) && isset($meta['get'])) {
            return false;
        }
        $setLc = strtolower(PropertyHooks::setHookMethodName($propertyName));
        $setProxy = $staticProperty
            ? self::resolveStaticHookProxy($context, $className, $setLc)
            : self::resolveHookProxy($context, $className, $setLc);
        if (null === $setProxy || (!$staticProperty && self::proxyIsStatic($context, $setProxy))) {
            return false;
        }
        $getLc = strtolower(PropertyHooks::getHookMethodName($propertyName));
        $getProxy = $staticProperty
            ? self::resolveStaticHookProxy($context, $className, $getLc)
            : self::resolveHookProxy($context, $className, $getLc);
        if (null !== $getProxy && (!$staticProperty || !self::proxyIsStatic($context, $getProxy))) {
            return false;
        }
        $classPropertyVirtual = false;
        $staticHookVirtual = false;
        if (isset($context->runtime->vmContext->classes[$lcClass])) {
            $entry = $context->runtime->vmContext->classes[$lcClass];
            if ($staticProperty) {
                $staticHooks = $entry->staticPropertyHooks[$propLc] ?? [];
                $staticHookVirtual = !empty($staticHooks['virtual']);
            } else {
                $propMeta = $entry->properties[$propLc] ?? $entry->properties[$propertyName] ?? null;
                $classPropertyVirtual = null !== $propMeta && $propMeta->propertyHookVirtual;
            }
        }
        if (!\PHPCompiler\VM\AbstractPropertyHookCheck::isWriteOnlyVirtualHook(
            is_array($meta) ? $meta : null,
            $classPropertyVirtual || $staticHookVirtual,
            $propertyName
        )) {
            return false;
        }

        // php-src PHP 8.4: zend_object_handlers.c — "Property %s::$%s is write-only" (#29240).
        $message = sprintf('Property %s::$%s is write-only', $className, $propertyName);
        if ([] !== $context->tryCatch->handlerStack) {
            // Object_::isset passes $jit=null; emitCatchableClassError accepts that (#29240).
            if (null !== $jit) {
                TryCatchHelper::emitCatchableErrorMessage($context, $jit, $message);
            } else {
                TryCatchHelper::emitCatchableClassError($context, 'Error', $message, null);
            }
        } else {
            ErrorRaise::emitRaise($context, $message);
            // Pending Error alone is swallowed by ?? ISSET/COALESCE — hard-stop (#29240).
            $context->builder->call($context->lookupFunction('abort'));
            $context->builder->clearInsertionPosition();
        }

        return true;
    }
}
