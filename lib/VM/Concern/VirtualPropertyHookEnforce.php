<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * Virtual / readonly / asymmetric property-hook enforce helpers for the VM (#36403).
 *
 * Extracted from {@see \PHPCompiler\VM}: {@code enforceReadonlyPropertyUnset} through
 * {@code raiseVirtualPropertyHookRawAccessError} (php-src Zend/zend_object_handlers.c
 * zend_std_unset_property / verify_readonly_initialization_access; zend_property_hooks.c
 * write-only virtual reads + re-entrant raw access — #29131, #23338, #6425, #6484, #10005,
 * #21467, #22476, #26373). Companion to {@see ObjectPropertyHooks} /
 * {@see ObjectPropertyReadonlyAndVisibility} / {@see ObjectPropertyIssetEmptyUnset}.
 * Concern trait — same namespace as parent so relative Frame / OpCode helpers resolve.
 * Move-only; no new C ABI.
 */
trait VirtualPropertyHookEnforce
{
    /**
     * Reject unset() on readonly properties; returns catch frame or throws when uncaught.
     *
     * php-src zend_std_unset_property / verify_readonly_initialization_access (#29131):
     * uninitialized readonly may be unset from declaring-class scope (same window as first
     * init), including inside __construct, so a later write can initialize. Once initialized,
     * unset always Errors — even mid-construction.
     */
    private function enforceReadonlyPropertyUnset(ObjectEntry $object, string $propName, Frame $frame): ?Frame
    {
        if (VM\ObjectReadonlySupport::isDynamicReadonly($object)) {
            // Stamp user site via dispatchVmError (#25556 / #7343).
            return $this->dispatchVmError(
                VM\ObjectReadonlySupport::unsetObjectMessage($object),
                $frame
            );
        }

        $declaringClass = $this->readonlyPropertyDeclaringClass($object, $propName);
        if (null === $declaringClass) {
            return null;
        }

        // Uninitialized + declaring-class scope → allow (reinit via later assign; #29131).
        if ($this->allowReadonlyPropertyFirstInit($object, $propName, $frame)) {
            return null;
        }

        $uninitialized = !$object->hasProperty($propName)
            || VM\TypedPropertyCheck::isUninitialized($object->getProperty($propName));
        if ($uninitialized) {
            return $this->dispatchVmError(
                sprintf(
                    'Cannot unset readonly property %s::$%s from %s',
                    $declaringClass,
                    $propName,
                    $this->propertyWriteScopeLabel($frame)
                ),
                $frame
            );
        }

        return $this->dispatchVmError(
            sprintf('Cannot unset readonly property %s::$%s', $declaringClass, $propName),
            $frame
        );
    }

    /**
     * Reject unset() outside set-visibility scope (zend_object_handlers.c, #23338).
     * Same gate as writes; message verb is "unset" instead of "modify".
     */
    private function enforceAsymmetricPropertyUnset(ObjectEntry $object, string $propName, Frame $frame): ?Frame
    {
        $msg = $this->asymmetricPropertyUnsetMessage($object, $propName, $frame);
        if (null === $msg) {
            return null;
        }
        $thrown = VM\BuiltinExceptionSupport::materializeError($this->context, $msg);
        $catchFrame = $this->findCatchFrameForThrow($frame, $thrown);
        if (null !== $catchFrame) {
            return $catchFrame;
        }
        $this->raiseUncaughtException($thrown);

        return null;
    }

    /** Reject asymmetric set visibility for unset(); returns message or null (#23338). */
    private function asymmetricPropertyUnsetMessage(ObjectEntry $object, string $propName, Frame $frame): ?string
    {
        $meta = $this->classPropertyMeta($object, $propName);
        if (null === $meta) {
            return null;
        }
        // Implicit PHP 8.4 protected(set) on readonly — wording is readonly, not aviz (#29273).
        if ($meta->readonly || $object->class->readonly) {
            return null;
        }
        $setVis = PropertyVisibility::effectiveSetVisibility($meta->visibility, $meta->setVisibility);
        $readVis = PropertyVisibility::effectiveGetVisibility($meta->visibility, $meta->getVisibility);
        if ($setVis === $readVis) {
            return null;
        }
        $declaringLc = '' !== $meta->declaringClassLc
            ? $meta->declaringClassLc
            : strtolower($object->class->name);
        $declaringDisplay = $this->context->classes[$declaringLc]->name
            ?? $object->class->name;
        $callerLc = $this->callerClassLc($frame);
        try {
            PropertyVisibility::assertUnsettable(
                $setVis,
                $callerLc,
                $declaringLc,
                $declaringDisplay,
                $propName,
                fn (string $child, string $parent): bool => $this->isSubclassOf($child, $parent),
                MethodVisibility::mask($readVis),
                $meta->asymmetricExplicitRead,
                $this->callerScopeDisplay($frame, $callerLc)
            );
        } catch (\LogicException $e) {
            return $e->getMessage();
        }

        return null;
    }

    /**
     * Reject unset() on virtual hooked instance properties without an unset hook (#6425, #6491, #26373).
     * Backed hooked properties are rejected in dispatchHookedInstancePropertyUnset.
     */
    private function enforceVirtualPropertyHookUnset(ObjectEntry $object, string $propName, Frame $frame): ?Frame
    {
        $meta = $this->classPropertyMeta($object, $propName);
        if (null === $meta || !$meta->propertyHookVirtual) {
            return null;
        }
        $hasSet = null !== $meta->setHookMethodLc;
        $hasGet = null !== $meta->getHookMethodLc;
        if (null !== $meta->unsetHookMethodLc) {
            return null;
        }
        if (!$hasSet && !$hasGet) {
            return null;
        }
        $className = $object->class->name;
        if ('' !== $meta->declaringClassLc && isset($this->context->classes[$meta->declaringClassLc])) {
            $className = $this->context->classes[$meta->declaringClassLc]->name;
        }

        return $this->raiseVirtualPropertyHookUnsetError($className, $propName, $frame);
    }

    /**
     * Reject unset() on static properties (Zend zend_std_unset_static_property).
     * Typed (#6648) and untyped (#23691) both Error; hook raw-writes may clear backing.
     *
     * Route through {@see dispatchVmError} so getFile()/getLine() stamp the user unset site
     * (#31859, zend_object_handlers.c).
     */
    private function enforceStaticPropertyUnset(
        string $classLc,
        string $propNameRaw,
        Frame $frame
    ): ?Frame {
        if ($this->isPropertyHookRawWrite($frame, $propNameRaw)) {
            return null;
        }
        $className = $this->context->classes[$classLc]->name ?? $classLc;

        return $this->dispatchVmError(
            sprintf('Attempt to unset static property %s::$%s', $className, $propNameRaw),
            $frame
        );
    }

    /** Reject unset() on virtual hooked static properties without an unset hook (#6425, #6491, #26373). */
    private function enforceVirtualStaticPropertyHookUnset(
        string $classLc,
        string $propLc,
        string $propNameRaw,
        Frame $frame
    ): ?Frame {
        $hooks = $this->resolveStaticPropertyHooks($classLc, $propLc);
        if (null === $hooks || empty($hooks['virtual'])) {
            return null;
        }
        $hasSet = !empty($hooks['set']);
        $hasGet = !empty($hooks['get']);
        if (!empty($hooks['unset'])) {
            return null;
        }
        if (!$hasSet && !$hasGet) {
            return null;
        }
        $className = $this->context->classes[$classLc]->name ?? $classLc;

        return $this->raiseVirtualPropertyHookUnsetError($className, $propNameRaw, $frame);
    }

    private function raiseVirtualPropertyHookUnsetError(
        string $className,
        string $propName,
        Frame $frame
    ): ?Frame {
        $message = sprintf('Cannot unset hooked property %s::$%s', $className, $propName);
        $thrown = VM\BuiltinExceptionSupport::materializeError(
            $this->context,
            $message
        );
        $catchFrame = $this->findCatchFrameForThrow($frame, $thrown);
        if (null !== $catchFrame) {
            return $catchFrame;
        }
        $this->raiseUncaughtException($thrown);

        return null;
    }

    /** Reject reads/isset/empty on write-only virtual hooked instance properties (#6484, #19163, zend_property_hooks.c). */
    private function enforceWriteOnlyVirtualPropertyRead(ObjectEntry $object, string $propName, Frame $frame): ?Frame
    {
        if (!$this->instancePropertyIsWriteOnlyVirtualHook($object, $propName)) {
            return null;
        }
        $meta = $this->classPropertyMeta($object, $propName);
        $className = $object->class->name;
        if (null !== $meta && '' !== $meta->declaringClassLc && isset($this->context->classes[$meta->declaringClassLc])) {
            $className = $this->context->classes[$meta->declaringClassLc]->name;
        }

        return $this->raiseWriteOnlyVirtualPropertyReadError($className, $propName, $frame);
    }

    /** Reject reads on write-only virtual hooked static properties (#6484, #19163). */
    private function enforceWriteOnlyVirtualStaticPropertyRead(string $classLc, string $propName, Frame $frame): ?Frame
    {
        $hooks = $this->resolveStaticPropertyHooks($classLc, strtolower($propName));
        if (null === $hooks || empty($hooks['set']) || !empty($hooks['get'])) {
            return null;
        }
        if (!$this->staticPropertyIsWriteOnlyVirtualHook($classLc, $propName, $hooks)) {
            return null;
        }
        $className = $this->context->classes[$classLc]->name ?? $classLc;

        return $this->raiseWriteOnlyVirtualPropertyReadError($className, $propName, $frame);
    }

    private function enforceWriteOnlyVirtualPropertyReadForLvalue(Variable $lvalue, Frame $frame): ?Frame
    {
        $propName = $this->resolvePropertyWriteName($lvalue);
        if ($this->isPropertyHookRawWrite($frame, $propName ?? '')) {
            return null;
        }
        $owner = $this->resolvePropertyWriteOwner($lvalue);
        if (null !== $owner && null !== $propName) {
            return $this->enforceWriteOnlyVirtualPropertyRead($owner, $propName, $frame);
        }
        $target = $lvalue->resolveIndirect();
        $classLc = $lvalue->staticPropertyClassLc ?? $target->staticPropertyClassLc;
        $staticPropName = $lvalue->objectPropertyName ?? $target->objectPropertyName;
        if (is_string($classLc) && is_string($staticPropName) && '' !== $staticPropName) {
            return $this->enforceWriteOnlyVirtualStaticPropertyRead($classLc, $staticPropName, $frame);
        }

        return null;
    }

    private function raiseWriteOnlyVirtualPropertyReadError(string $className, string $propName, Frame $frame): ?Frame
    {
        // php-src PHP 8.4: zend_object_handlers.c — "Property %s::$%s is write-only" (#29240).
        $thrown = VM\BuiltinExceptionSupport::materializeError(
            $this->context,
            sprintf('Property %s::$%s is write-only', $className, $propName)
        );
        $catchFrame = $this->findCatchFrameForThrow($frame, $thrown);
        if (null !== $catchFrame) {
            return $catchFrame;
        }
        $this->raiseUncaughtException($thrown);

        return null;
    }

    /**
     * Inside a property hook, re-entrant $this->prop skips the hook (zend_should_call_hook).
     * Virtual: "Must not read/write virtual property" (#10005).
     * Backed typed, uninitialized: typed-property Error (#21467, php-src-strict).
     *
     * Virtuality is judged for the **hook-declaring class** on this frame, not the leaf
     * object's override — parent::$prop::set()/get() must still touch the parent's backing
     * when the child marks the property virtual (#22476, zend_property_hooks.c).
     */
    private function enforceVirtualPropertyHookRawAccess(
        ObjectEntry $object,
        string $propName,
        bool $isRead,
        Frame $frame
    ): ?Frame {
        if (!$this->isPropertyHookRawWrite($frame, $propName)) {
            return null;
        }
        if ($isRead && !$this->frameIsPropertyGetHook($frame)) {
            return null;
        }
        if (!$isRead && !$this->frameIsPropertySetHook($frame)) {
            return null;
        }
        $hookClassLc = $this->propertyHookFrameDeclaringClassLc($frame);
        $className = null !== $hookClassLc && isset($this->context->classes[$hookClassLc])
            ? $this->context->classes[$hookClassLc]->name
            : $this->resolveHookedPropertyClassName($object, $propName);
        if ($this->instancePropertyIsVirtualHookForHookFrame($object, $propName, $hookClassLc)) {
            return $this->raiseVirtualPropertyHookRawAccessError($className, $propName, $isRead, $frame);
        }
        if (!$isRead) {
            return null;
        }
        $meta = $this->classPropertyMeta($object, $propName);
        if (null === $meta?->getHookMethodLc) {
            return null;
        }
        if ($this->hookedPropertyUsesDistinctBacking($object, $propName)) {
            return null;
        }
        // Parent hook with same-name backing on a child that overrode as virtual: probe the
        // declaring class registry, not the child's virtual leaf meta (#22476).
        if (null !== $hookClassLc && $this->hookedPropertyUsesDistinctBackingForClass($hookClassLc, $propName)) {
            return null;
        }
        $backing = $this->hookedPropertyBackingValue($object, $propName);
        if (false !== $backing && ($backing->isUndefined() || VM\TypedPropertyCheck::isUninitialized($backing))) {
            return $this->dispatchVmError(VM\TypedPropertyCheck::errorMessage($backing), $frame);
        }

        return null;
    }

    /** Declaring class lc of the __phpc_property_* method on this frame, if any. */
    private function propertyHookFrameDeclaringClassLc(Frame $frame): ?string
    {
        $func = $frame->block->func ?? null;
        if (null === $func || null === $func->class) {
            return null;
        }
        $className = $func->class->value ?? null;
        if (!is_string($className) || '' === $className) {
            return null;
        }

        return strtolower(ltrim($className, '\\'));
    }

    /**
     * Virtual check scoped to the running hook's class (#22476 parent::$prop::set/get).
     */
    private function instancePropertyIsVirtualHookForHookFrame(
        ObjectEntry $object,
        string $propName,
        ?string $hookClassLc
    ): bool {
        if (null !== $hookClassLc) {
            $propMeta = $this->context->propertyHookRegistry[$hookClassLc][$propName]
                ?? $this->context->propertyHookRegistry[$hookClassLc][strtolower($propName)]
                ?? null;
            if (is_array($propMeta)) {
                return !empty($propMeta['virtual']);
            }
            if (isset($this->context->classes[$hookClassLc])) {
                foreach ($this->context->classes[$hookClassLc]->properties as $prop) {
                    if ($prop->name === $propName || 0 === strcasecmp($prop->name, $propName)) {
                        return $prop->propertyHookVirtual;
                    }
                }
            }
        }

        return $this->instancePropertyIsVirtualHook($object, $propName);
    }

    /** Distinct backing declared on a specific class's hook registry. */
    private function hookedPropertyUsesDistinctBackingForClass(string $classLc, string $propName): bool
    {
        $propMeta = $this->context->propertyHookRegistry[$classLc][$propName]
            ?? $this->context->propertyHookRegistry[$classLc][strtolower($propName)]
            ?? null;
        if (!is_array($propMeta)) {
            return false;
        }
        $backingName = $propMeta['setBacking'] ?? $propMeta['getBacking'] ?? null;

        return null !== $backingName && 0 !== strcasecmp($backingName, $propName);
    }

    private function resolveHookedPropertyClassName(ObjectEntry $object, string $propName): string
    {
        $meta = $this->classPropertyMeta($object, $propName);
        $className = $object->class->name;
        if (null !== $meta && '' !== $meta->declaringClassLc && isset($this->context->classes[$meta->declaringClassLc])) {
            $className = $this->context->classes[$meta->declaringClassLc]->name;
        }

        return $className;
    }

    private function hookedPropertyUsesDistinctBacking(ObjectEntry $object, string $propName): bool
    {
        $lcClass = strtolower($object->class->name);
        $propMeta = $this->context->propertyHookRegistry[$lcClass][$propName]
            ?? $this->context->propertyHookRegistry[$lcClass][strtolower($propName)]
            ?? null;
        if (!is_array($propMeta)) {
            return false;
        }
        $backingName = $propMeta['setBacking'] ?? $propMeta['getBacking'] ?? null;

        return null !== $backingName && 0 !== strcasecmp($backingName, $propName);
    }

    private function instancePropertyIsVirtualHook(ObjectEntry $object, string $propName): bool
    {
        $meta = $this->classPropertyMeta($object, $propName);
        if (null !== $meta && $meta->propertyHookVirtual) {
            return true;
        }
        $lcClass = strtolower($object->class->name);
        $propMeta = $this->context->propertyHookRegistry[$lcClass][$propName]
            ?? $this->context->propertyHookRegistry[$lcClass][strtolower($propName)]
            ?? null;

        return is_array($propMeta) && !empty($propMeta['virtual']);
    }

    /**
     * zend_should_call_hook is false for uninitialized same-name backed hooks (#30739).
     * Virtual / distinct-backing properties still invoke get.
     */
    private function skipHookedGetForUninitializedSameNameBacking(ObjectEntry $object, string $propName): bool
    {
        if (!$this->instancePropertyHasGetHook($object, $propName)) {
            return false;
        }
        if ($this->instancePropertyIsVirtualHook($object, $propName)) {
            return false;
        }
        if ($this->hookedPropertyUsesDistinctBacking($object, $propName)) {
            return false;
        }
        $backing = $this->hookedPropertyBackingValue($object, $propName);
        if (false === $backing) {
            return false;
        }

        return $backing->isUndefined() || VM\TypedPropertyCheck::isUninitialized($backing);
    }

    /** Set-only hook with short `set =>` backing or explicit virtual — external reads forbidden (#6484, #12941, #19163). */
    private function instancePropertyIsWriteOnlyVirtualHook(ObjectEntry $object, string $propName): bool
    {
        $meta = $this->classPropertyMeta($object, $propName);
        if (null === $meta || null === $meta->setHookMethodLc || null !== $meta->getHookMethodLc) {
            return false;
        }
        $lcClass = strtolower($object->class->name);
        $propMeta = $this->context->propertyHookRegistry[$lcClass][$propName]
            ?? $this->context->propertyHookRegistry[$lcClass][strtolower($propName)]
            ?? null;

        return VM\AbstractPropertyHookCheck::isWriteOnlyVirtualHook(
            $propMeta,
            $meta->propertyHookVirtual,
            $propName
        );
    }

    /**
     * @param array<string, mixed> $hooks
     */
    private function staticPropertyIsWriteOnlyVirtualHook(string $classLc, string $propName, array $hooks): bool
    {
        $propMeta = $this->context->propertyHookRegistry[$classLc][$propName]
            ?? $this->context->propertyHookRegistry[$classLc][strtolower($propName)]
            ?? null;

        return VM\AbstractPropertyHookCheck::isWriteOnlyVirtualHook(
            $propMeta,
            !empty($hooks['virtual']),
            $propName
        );
    }

    private function raiseVirtualPropertyHookRawAccessError(
        string $className,
        string $propName,
        bool $isRead,
        Frame $frame
    ): ?Frame {
        return $this->dispatchVmError(
            sprintf(
                'Must not %s virtual property %s::$%s',
                $isRead ? 'read from' : 'write to',
                $className,
                $propName
            ),
            $frame
        );
    }
}
