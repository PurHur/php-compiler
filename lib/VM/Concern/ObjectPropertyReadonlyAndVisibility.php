<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\ClosureState;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * Readonly / asymmetric visibility / static property access enforcement for the VM (#36403).
 *
 * Extracted from {@see \PHPCompiler\VM}: {@code enforceReadonlyForCompoundAssign} through
 * {@code asymmetricPropertyWriteMessageForMeta} (php-src zend_object_handlers /
 * zend_execute asymmetric + readonly checks). Concern trait — same namespace as parent so
 * relative Frame / VM helpers resolve.
 */
trait ObjectPropertyReadonlyAndVisibility
{
    /**
     * Compound assignment ($obj->prop += 1) reuses one operand slot (arg1 === arg2).
     * Reject when the lvalue is a readonly instance property after construction (#3149).
     */
    private function enforceReadonlyForCompoundAssign(Frame $frame, OpCode $op, Variable $lvalue): ?Frame
    {
        if ($op->arg1 !== $op->arg2) {
            return null;
        }
        $catchFrame = $this->enforceAsymmetricPropertyWrite($lvalue, $frame);
        if (null !== $catchFrame) {
            return $catchFrame;
        }
        $catchFrame = $this->enforceVirtualPropertyHookWrite($lvalue, $frame);
        if (null !== $catchFrame) {
            return $catchFrame;
        }

        $catchFrame = $this->enforceReadonlyPropertyWrite($lvalue, $frame);
        if (null !== $catchFrame) {
            return $catchFrame;
        }

        return $this->enforceFinalPropertyWrite($lvalue, $frame);
    }

    /**
     * Reject writes to get-only VIRTUAL hooked properties (#4687, #18072, #26006, #29674).
     *
     * php-src: Zend/zend_object_handlers.c — omitting {@code set} on a *backed* property uses
     * default write into the backing store (manual: "omitting a get or set hook means the default
     * read or write behavior will be used"). Only VIRTUAL get-only props Error on write.
     *
     * PHP-8.4 external virtual write: "Property … is read-only".
     * "Must not write to virtual property" is only for raw backing-slot access inside a hook
     * ({@see raiseVirtualPropertyHookRawAccessError} / zend_throw_no_prop_backing_value_access).
     * php-src master tip uses "Cannot write to get-only virtual property …" for the external path —
     * keep the PHP-8.4 string under PROFILE=8.4.
     */
    private function enforceVirtualPropertyHookWrite(Variable $lvalue, Frame $frame): ?Frame
    {
        $target = $lvalue->resolveIndirect();
        $propName = $target->objectPropertyName;
        if (null === $propName) {
            return null;
        }
        if ($this->isPropertyHookRawWrite($frame, $propName)) {
            $owner = $this->resolvePropertyWriteOwner($lvalue);
            if (null !== $owner) {
                return $this->enforceVirtualPropertyHookRawAccess($owner, $propName, false, $frame);
            }

            return null;
        }
        $className = null;
        $virtual = false;
        $hasGetHook = false;
        $hasSetHook = false;
        $classLc = $target->staticPropertyClassLc;
        if (is_string($classLc) && isset($this->context->classes[$classLc])) {
            $entry = $this->context->classes[$classLc];
            $hooks = $this->resolveStaticPropertyHooks($classLc, strtolower($propName)) ?? [];
            $virtual = !empty($hooks['virtual']);
            $hasGetHook = !empty($hooks['get']);
            $hasSetHook = !empty($hooks['set']);
            $className = $entry->name;
        } else {
            $owner = $this->resolvePropertyWriteOwner($lvalue);
            if (null === $owner) {
                return null;
            }
            $meta = $this->classPropertyMeta($owner, $propName);
            if (null === $meta) {
                return null;
            }
            $virtual = $meta->propertyHookVirtual;
            $hasGetHook = null !== $meta->getHookMethodLc;
            $hasSetHook = null !== $meta->setHookMethodLc;
            $className = $owner->class->name;
        }
        if (!$hasGetHook || $hasSetHook) {
            return null;
        }
        // Backed get-only: default write to backing (ctor promo, `$this->x =`, external) — #29674.
        if (!$virtual) {
            return null;
        }
        if ($this->propertyHasDistinctAsymmetricSetVisibility($classLc, $propName, $lvalue)) {
            return $this->enforceAsymmetricPropertyWrite($lvalue, $frame);
        }

        $message = sprintf('Property %s::$%s is read-only', $className, $propName);
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

    /**
     * Reject dynamic property creation on readonly classes (Zend zend_objects.c).
     * Returns catch frame or raises uncaught Error (#4799).
     *
     * Route through {@see dispatchVmError} so file/line stamp the user assignment site
     * (php-src zend_object_handlers.c / #25556, #29457).
     *
     * @return ?Frame catch frame when handled; null when no violation or after uncaught raise
     */
    private function enforceReadonlyDynamicPropertyCreate(ObjectEntry $object, string $name, Frame $frame): ?Frame
    {
        if (VM\ObjectReadonlySupport::isDynamicReadonly($object)) {
            return $this->dispatchVmError(
                VM\ObjectReadonlySupport::modifyObjectMessage($object),
                $frame
            );
        }

        if (!$object->class->readonly || $this->hasInstanceMethod($object->class, '__set')) {
            return null;
        }
        if ($object->hasProperty($name)) {
            return null;
        }

        return $this->dispatchVmError(
            sprintf('Cannot create dynamic property %s::$%s', $object->class->name, $name),
            $frame
        );
    }

    /**
     * ZEND_ACC_NO_DYNAMIC_PROPERTIES → catchable Error (zend_object_handlers.c; #26371).
     * Closure/Fiber/Generator/WeakMap reject; Dom\* and other internals allow with E_DEPRECATED (#26566).
     *
     * Route through {@see dispatchVmError} so getFile()/getLine() and uncaught fatals cite the
     * user assignment, not ExceptionSupport.php (#29457, re-#25556).
     *
     * @return ?Frame catch frame when handled; null when allowed or after uncaught raise
     */
    private function enforceInternalDynamicPropertyCreate(ObjectEntry $object, string $name, Frame $frame): ?Frame
    {
        if (!$object->class->noDynamicProperties) {
            return null;
        }
        if ($object->hasProperty($name)) {
            return null;
        }
        // Declared ClassProperty (possibly not yet distinguished from dynamic) — leave to write path.
        if (null !== $this->classPropertyMeta($object, $name, $frame)) {
            return null;
        }
        if ($this->hasInstanceMethod($object->class, '__set')) {
            return null;
        }
        if (VM\SplArraySupport::hasArrayAsProps($object)) {
            return null;
        }

        return $this->dispatchVmError(
            sprintf('Cannot create dynamic property %s::$%s', $object->class->name, $name),
            $frame
        );
    }

    /**
     * ReflectionProperty::setValue on instance props — same readonly guard as ordinary writes (#15749, php_reflection.c).
     *
     * @throws \Error
     */
    private function assertReadonlyPropertyWriteAllowedForReflection(
        ObjectEntry $object,
        string $propName,
        Frame $frame
    ): void {
        if (VM\ObjectReadonlySupport::isDynamicReadonly($object)) {
            throw new \Error(VM\ObjectReadonlySupport::modifyObjectMessage($object));
        }
        $declaringClass = $this->readonlyPropertyDeclaringClass($object, $propName);
        if (null === $declaringClass) {
            return;
        }
        if (!$object->constructed) {
            // NIWC / mid-ctor: first init only from declaring-class scope (zend_readonly.c, #25745).
            // Prior check skipped null callerClassLc → global `$o->x = …` after NIWC wrongly succeeded.
            if ($this->allowReadonlyPropertyFirstInit($object, $propName, $frame)) {
                return;
            }
            throw new \Error($this->readonlyPropertyWriteErrorMessage($object, $propName, $declaringClass, $frame));
        }
        // Clone-with reinit unlocks readonly once; asymmetric set still applies (#29186).
        if (isset($object->reinitableProperties[$propName])) {
            $avizMsg = $this->asymmetricPropertyWriteMessageForMeta($object, $propName, $frame, true);
            if (null !== $avizMsg) {
                throw new \Error($avizMsg);
            }
            if (VM\CloneWithSupport::consumeReinit($object, $propName)) {
                return;
            }
        }
        // First write after construction from declaring-class scope is initialization (#23475).
        if ($this->allowReadonlyPropertyFirstInit($object, $propName, $frame)) {
            return;
        }

        throw new \Error($this->readonlyPropertyWriteErrorMessage($object, $propName, $declaringClass, $frame));
    }

    /**
     * ReflectionProperty::setValue — plain `final` does not block writes (php-src-strict, #23683).
     *
     * Verified Zend PHP 8.4.23 / 8.5.8: `final` is inheritance-only (zend_inheritance.c).
     * Asymmetric set visibility (private(set), #23068/#23110) still governs Reflection writes.
     */
    private function assertFinalPropertyWriteAllowedForReflection(
        ObjectEntry $object,
        string $propName
    ): void {
        // No-op: php-src has no "Cannot modify final property" write path.
        unset($object, $propName);
    }

    /**
     * Reject `&$obj->readonlyProp` at fetch-for-write / ASSIGN_REF time (#25620).
     *
     * php-src: Zend/zend_readonly.c / zend_object_handlers.c get_property_ptr_ptr —
     * initialized props use "Cannot modify…"; uninitialized use "Cannot indirectly modify…".
     */
    private function enforceReadonlyPropertyFetchByRef(Variable $lvalue, Frame $frame): ?Frame
    {
        $owner = $this->resolvePropertyWriteOwner($lvalue);
        if (null !== $owner && VM\ObjectReadonlySupport::isDynamicReadonly($owner)) {
            return $this->dispatchVmError(
                VM\ObjectReadonlySupport::modifyObjectMessage($owner),
                $frame
            );
        }
        if (null === $owner) {
            return null;
        }
        $prop = $this->resolvePropertyWriteName($lvalue) ?? 'property';
        $declaringClass = $this->readonlyPropertyDeclaringClass($owner, $prop);
        if (null === $declaringClass) {
            return null;
        }
        $uninitialized = !$owner->hasProperty($prop)
            || VM\TypedPropertyCheck::isUninitialized($owner->getProperty($prop));
        $declaringClass = MethodVisibility::formatAnonymousScopeForMessage($declaringClass);
        $message = $uninitialized
            ? sprintf('Cannot indirectly modify readonly property %s::$%s', $declaringClass, $prop)
            : sprintf('Cannot modify readonly property %s::$%s', $declaringClass, $prop);

        return $this->dispatchVmError($message, $frame);
    }

    /**
     * Reject readonly property writes; returns catch frame or throws when uncaught.
     *
     * Route through {@see dispatchVmError} so file/line stamp the user assignment site
     * (php-src zend_readonly_property_modification_error / #25556, re-#7343).
     */
    private function enforceReadonlyPropertyWrite(Variable $lvalue, Frame $frame): ?Frame
    {
        if ($this->shouldDeferReadonlyForPropertySetHook($lvalue, $frame)) {
            return null;
        }
        $owner = $this->resolvePropertyWriteOwner($lvalue);
        if (null !== $owner && VM\ObjectReadonlySupport::isDynamicReadonly($owner)) {
            return $this->dispatchVmError(
                VM\ObjectReadonlySupport::modifyObjectMessage($owner),
                $frame
            );
        }

        if (null === $owner) {
            return null;
        }
        $prop = $this->resolvePropertyWriteName($lvalue) ?? 'property';
        $declaringClass = $this->readonlyPropertyDeclaringClass($owner, $prop);
        if (null === $declaringClass) {
            return null;
        }
        if (!$owner->constructed) {
            // NIWC / mid-ctor: first init only from declaring-class scope (zend_readonly.c, #25745).
            // Prior check skipped null callerClassLc → global `$o->x = …` after NIWC wrongly succeeded.
            if ($this->allowReadonlyPropertyFirstInit($owner, $prop, $frame)) {
                return null;
            }

            return $this->dispatchVmError(
                $this->readonlyPropertyWriteErrorMessage($owner, $prop, $declaringClass, $frame),
                $frame
            );
        }
        // Clone-with reinit unlocks readonly once; asymmetric set still applies (#29186).
        if (isset($owner->reinitableProperties[$prop])) {
            $avizMsg = $this->asymmetricPropertyWriteMessageForMeta($owner, $prop, $frame, true);
            if (null !== $avizMsg) {
                return $this->dispatchVmError($avizMsg, $frame);
            }
            if (VM\CloneWithSupport::consumeReinit($owner, $prop)) {
                return null;
            }
        }
        // First write after construction from declaring-class scope is initialization (#23475).
        if ($this->allowReadonlyPropertyFirstInit($owner, $prop, $frame)) {
            return null;
        }

        return $this->dispatchVmError(
            $this->readonlyPropertyWriteErrorMessage($owner, $prop, $declaringClass, $frame),
            $frame
        );
    }

    /**
     * Zend allows the first assignment to an uninitialized readonly property from any
     * instance method of the declaring class — not only `__construct` (#23475).
     *
     * php-src: Zend/zend_object_handlers.c / Zend/zend_readonly.c
     */
    private function allowReadonlyPropertyFirstInit(ObjectEntry $owner, string $prop, Frame $frame): bool
    {
        $declaringClassLc = $this->readonlyPropertyDeclaringClassLc($owner, $prop);
        $callerClassLc = $this->callerClassLc($frame);
        if (null === $declaringClassLc || null === $callerClassLc || $callerClassLc !== $declaringClassLc) {
            return false;
        }
        if (!$owner->hasProperty($prop)) {
            return true;
        }

        return VM\TypedPropertyCheck::isUninitialized($owner->getProperty($prop));
    }

    /**
     * Plain `final` properties are inheritance-only in php-src (#23683, re-#22450).
     *
     * Verified Zend PHP 8.4.23 / 8.5.8: post-construct writes succeed; child redeclaration
     * fatals via {@see Compiler\FinalPropertyOverrideCheck}. External private(set) denies
     * remain on the asymmetric write path (#23110).
     */
    private function enforceFinalPropertyWrite(Variable $lvalue, Frame $frame): ?Frame
    {
        unset($lvalue, $frame);

        return null;
    }

    /**
     * True when set visibility differs from the property's read visibility flags (#3165, #23110).
     */
    private function classPropertyHasDistinctAsymmetricSetVisibility(VM\ClassProperty $meta): bool
    {
        return PropertyVisibility::effectiveSetVisibility($meta->visibility, $meta->setVisibility)
            !== MethodVisibility::mask($meta->visibility);
    }

    /** Zend zend_readonly_property_modification_error — init vs modify wording (#5463). */
    private function readonlyPropertyWriteErrorMessage(
        ObjectEntry $owner,
        string $prop,
        string $declaringClass,
        Frame $frame
    ): string {
        // Strip @anonymous\0file:line$id provenance for Error messages (#29250 / #26031).
        $declaringClass = MethodVisibility::formatAnonymousScopeForMessage($declaringClass);
        if ($owner->hasProperty($prop)) {
            $slot = $owner->getProperty($prop);
            if (VM\TypedPropertyCheck::isUninitialized($slot)) {
                return sprintf(
                    'Cannot initialize readonly property %s::$%s from %s',
                    $declaringClass,
                    $prop,
                    $this->propertyWriteScopeLabel($frame)
                );
            }
        }

        return sprintf('Cannot modify readonly property %s::$%s', $declaringClass, $prop);
    }

    /**
     * Zend routes external writes through set hooks; readonly backing checks run on raw writes inside the hook (#4518).
     */
    private function shouldDeferReadonlyForPropertySetHook(Variable $lvalue, Frame $frame): bool
    {
        $propName = $this->resolvePropertyWriteName($lvalue);
        if (null === $propName || $this->isPropertyHookRawWrite($frame, $propName)) {
            return false;
        }
        $owner = $this->resolvePropertyWriteOwner($lvalue);
        if (null !== $owner) {
            $meta = $this->classPropertyMeta($owner, $propName);

            return null !== $meta && null !== $meta->setHookMethodLc;
        }
        $target = $lvalue->resolveIndirect();
        $classLc = $lvalue->staticPropertyClassLc ?? $target->staticPropertyClassLc;
        $staticPropName = $lvalue->objectPropertyName ?? $target->objectPropertyName;
        if (!is_string($classLc) || !is_string($staticPropName) || '' === $staticPropName) {
            return false;
        }
        $hooks = $this->resolveStaticPropertyHooks($classLc, strtolower($staticPropName));

        return null !== $hooks && !empty($hooks['set']);
    }

    private function propertyWriteScopeLabel(Frame $frame): string
    {
        $callerClassLc = $this->callerClassLc($frame);
        if (null === $callerClassLc) {
            return 'global scope';
        }
        $className = isset($this->context->classes[$callerClassLc])
            ? $this->context->classes[$callerClassLc]->name
            : $callerClassLc;

        return 'scope ' . $className;
    }

    private function enforcePropertyVisibilityWrite(Variable $lvalue, Frame $frame): ?Frame
    {
        $target = $lvalue->resolveIndirect();
        $owner = $target->objectPropertyOwner;
        if (null === $owner) {
            return null;
        }

        return $this->enforcePropertyWriteVisibility($owner, $target->objectPropertyName ?? 'property', $frame);
    }

    private function enforcePropertyVisibilityRead(ObjectEntry $object, string $propName, Frame $frame): ?Frame
    {
        return $this->enforcePropertyReadVisibility($object, $propName, $frame);
    }

    private function isParentPrivatePropertyInvisibleFromCaller(
        VM\ClassProperty $meta,
        Frame $frame,
        ObjectEntry $object
    ): bool {
        return PropertyVisibility::isParentPrivatePropertyInvisibleFromChildScope(
            $meta->visibility,
            $this->callerClassLc($frame),
            $meta->declaringClassLc,
            fn (string $classLc, string $ancestorLc): bool => $this->isClassSameOrSubclassOf($classLc, $ancestorLc),
            $meta->getVisibility,
            strtolower($object->class->name)
        );
    }

    private function enforcePropertyReadVisibility(ObjectEntry $object, string $propName, Frame $frame): ?Frame
    {
        $meta = $this->classPropertyMeta($object, $propName, $frame);
        if (null === $meta) {
            return null;
        }
        if ($this->isParentPrivatePropertyInvisibleFromCaller($meta, $frame, $object)) {
            return null;
        }
        $readVis = PropertyVisibility::effectiveGetVisibility($meta->visibility, $meta->getVisibility);
        if (MethodVisibility::isPublic($readVis)) {
            return null;
        }
        $declaringDisplay = $this->context->classes[$meta->declaringClassLc]->name
            ?? $meta->declaringClassLc;
        try {
            PropertyVisibility::assertAccessible(
                $meta->visibility,
                $this->callerClassLc($frame),
                $meta->declaringClassLc,
                $declaringDisplay,
                $propName,
                strtolower($object->class->name),
                fn (string $classLc, string $ancestorLc): bool => $this->isClassSameOrSubclassOf($classLc, $ancestorLc),
                $meta->getVisibility
            );
        } catch (\LogicException $e) {
            return $this->dispatchVmError($e->getMessage(), $frame);
        }

        return null;
    }

    private function enforcePropertyWriteVisibility(ObjectEntry $object, string $propName, Frame $frame): ?Frame
    {
        if (null !== $this->context->lazyGhostInitializing
            && $this->context->lazyGhostInitializing === $object) {
            return null;
        }
        $meta = $this->classPropertyMeta($object, $propName, $frame);
        if (null === $meta) {
            return null;
        }
        $writeVis = PropertyVisibility::effectiveSetVisibility($meta->visibility, $meta->setVisibility);
        $readVis = PropertyVisibility::effectiveGetVisibility($meta->visibility, $meta->getVisibility);
        if ($writeVis !== $readVis) {
            return null;
        }
        if (MethodVisibility::isPublic($writeVis)) {
            return null;
        }
        $declaringDisplay = $this->context->classes[$meta->declaringClassLc]->name
            ?? $meta->declaringClassLc;
        try {
            PropertyVisibility::assertAccessible(
                $meta->visibility,
                $this->callerClassLc($frame),
                $meta->declaringClassLc,
                $declaringDisplay,
                $propName,
                strtolower($object->class->name),
                fn (string $classLc, string $ancestorLc): bool => $this->isClassSameOrSubclassOf($classLc, $ancestorLc),
                0
            );
        } catch (\LogicException $e) {
            return $this->dispatchVmError($e->getMessage(), $frame);
        }

        return null;
    }

    private function enforceDirectTraitConstAccess(ClassEntry $classEntry, string $constName, Frame $frame): ?Frame
    {
        if (!$classEntry->isTrait || 'class' === strtolower($constName)) {
            return null;
        }
        if ($this->isInTraitMethodScopeForTrait($frame, $classEntry)) {
            return null;
        }

        return $this->dispatchVmError(
            "Cannot access trait constant {$classEntry->name}::{$constName} directly",
            $frame
        );
    }

    /** self::CONST inside trait methods lowers to T::CONST — allow in-trait scope (#9187, Zend/zend_traits.c). */
    private function isInTraitMethodScopeForTrait(Frame $frame, ClassEntry $traitEntry): bool
    {
        if (!$traitEntry->isTrait) {
            return false;
        }
        $traitLc = strtolower(ltrim($traitEntry->name, '\\'));
        if (null !== $frame->block?->func?->class) {
            $funcClassLc = strtolower($frame->block->func->class->value);
            if ($funcClassLc === $traitLc) {
                return true;
            }
        }
        $declaringLc = null;
        if (null !== $frame->block?->func?->class) {
            $declaringLc = strtolower($frame->block->func->class->value);
        } elseif (null !== $frame->calledClass && '' !== $frame->calledClass) {
            $declaringLc = strtolower($frame->calledClass);
        }
        if (null === $declaringLc) {
            return false;
        }
        $scopeTraitLc = $this->traitScopeLcForFrameMethod($frame, $declaringLc);

        return null !== $scopeTraitLc && $scopeTraitLc === $traitLc;
    }

    private function enforceClassConstVisibility(ClassEntry $classEntry, string $constName, Frame $frame): ?Frame
    {
        $constKey = ClassConstName::key($constName);
        $vis = $classEntry->constVisibility[$constKey] ?? \PHPCfg\Func::FLAG_PUBLIC;
        if (MethodVisibility::isPublic($vis)) {
            return null;
        }
        // Trait methods keep access to private/protected consts imported from that trait onto the
        // composing class when self:: binds to the composing class (#9187, #19629, zend_traits.c).
        $sourceTrait = $classEntry->traitConstSources[$constKey] ?? null;
        if (null !== $sourceTrait && '' !== $sourceTrait) {
            $traitLc = strtolower(ltrim($sourceTrait, '\\'));
            $traitEntry = $this->context->classes[$traitLc] ?? null;
            if (null !== $traitEntry && $this->isInTraitMethodScopeForTrait($frame, $traitEntry)) {
                return null;
            }
        }
        try {
            ClassConstVisibility::assertAccessible(
                $vis,
                $this->callerClassLc($frame),
                strtolower($classEntry->name),
                $classEntry->name,
                $constName,
                fn (string $classLc, string $ancestorLc): bool => $this->isClassSameOrSubclassOf($classLc, $ancestorLc)
            );
        } catch (\LogicException $e) {
            return $this->dispatchVmError($e->getMessage(), $frame);
        }

        return null;
    }

    /**
     * @return array{
     *     visibility: int,
     *     setVisibility: int,
     *     getVisibility: int,
     *     asymmetricExplicitRead: bool,
     *     declaringClassLc: string,
     *     declaringClassDisplay: string
     * }|null
     */
    protected function resolveStaticPropertyVisibilityMeta(string $classLc, string $propLc): ?array
    {
        $currentLc = $classLc;
        while (isset($this->context->classes[$currentLc])) {
            $entry = $this->context->classes[$currentLc];
            if (isset($entry->staticProperties[$propLc])) {
                $declLc = $entry->staticPropertyDeclaringClassLc[$propLc] ?? $currentLc;
                $declEntry = $this->context->classes[$declLc] ?? $entry;

                return [
                    'visibility' => $entry->staticPropertyVisibility[$propLc] ?? \PHPCfg\Func::FLAG_PUBLIC,
                    'setVisibility' => $entry->staticPropertySetVisibility[$propLc] ?? 0,
                    'getVisibility' => $entry->staticPropertyGetVisibility[$propLc] ?? 0,
                    'asymmetricExplicitRead' => $entry->staticPropertyAsymmetricExplicitRead[$propLc] ?? false,
                    'declaringClassLc' => $declLc,
                    'declaringClassDisplay' => $declEntry->name,
                ];
            }
            if (null === $entry->parentLc) {
                break;
            }
            $currentLc = $entry->parentLc;
        }

        return null;
    }


    /**
     * Zend zend_get_property_offset: declared static accessed via -> emits E_NOTICE then
     * behaves as dynamic/undefined; inaccessible protected/private Error (unless silent /
     * BP_VAR_IS). Parent private statics are invisible (goto dynamic). (#30017)
     */
    private function handleStaticPropertyAccessedAsInstance(
        ObjectEntry $object,
        string $propName,
        Frame $frame,
        bool $silent
    ): ?Frame {
        $objectLc = strtolower($object->class->name);
        $meta = $this->resolveStaticPropertyVisibilityMeta($objectLc, strtolower($propName));
        if (null === $meta) {
            return null;
        }
        $vis = (int) $meta['visibility'];
        $declLc = (string) $meta['declaringClassLc'];
        // Parent private: not found on ce for instance lookup — no notice, no Error.
        if (
            ($vis & \PHPCfg\Func::FLAG_PRIVATE) !== 0
            && $declLc !== $objectLc
        ) {
            return null;
        }
        try {
            PropertyVisibility::assertAccessible(
                $vis,
                $this->callerClassLc($frame),
                $declLc,
                $object->class->name,
                $propName,
                $objectLc,
                fn (string $classLc, string $ancestorLc): bool => $this->isClassSameOrSubclassOf($classLc, $ancestorLc),
                (int) ($meta['getVisibility'] ?? 0)
            );
        } catch (\LogicException $e) {
            if ($silent) {
                return null;
            }

            return $this->dispatchVmError($e->getMessage(), $frame);
        }
        if (!$silent) {
            $scriptFile = '' !== $frame->scriptPath ? $frame->scriptPath : null;
            $this->context->errors->accessingStaticPropertyAsNonStatic(
                $object->class->name,
                $propName,
                $this->context,
                $frame,
                $scriptFile
            );
        }

        return null;
    }

    private function enforceStaticPropertyVisibilityWrite(Variable $lvalue, Frame $frame): ?Frame
    {
        $target = $lvalue->resolveIndirect();
        $classLc = $lvalue->staticPropertyClassLc ?? $target->staticPropertyClassLc;
        $propName = $lvalue->objectPropertyName ?? $target->objectPropertyName;
        if ((!is_string($classLc) || !is_string($propName) || '' === $propName)
            && $this->isStaticPropertyStorageCell($target)) {
            foreach ($this->context->classes as $entry) {
                foreach ($entry->staticProperties as $propLc => $storage) {
                    if ($storage !== $target) {
                        continue;
                    }
                    $classLc = strtolower($entry->name);
                    $propName = $entry->staticProperties[$propLc]->objectPropertyName ?? $propLc;
                    break 2;
                }
            }
        }
        if (!is_string($classLc) || !is_string($propName) || '' === $propName) {
            return null;
        }
        $catchFrame = $this->enforceStaticPropertyWriteVisibility($classLc, $propName, $frame);
        if (null !== $catchFrame) {
            return $catchFrame;
        }
        $msg = $this->asymmetricStaticPropertyWriteMessage($classLc, $propName, $frame);
        if (null !== $msg) {
            return $this->dispatchVmError($msg, $frame);
        }

        return null;
    }

    private function enforceStaticPropertyWriteVisibility(string $classLc, string $propName, Frame $frame): ?Frame
    {
        $meta = $this->resolveStaticPropertyVisibilityMeta($classLc, strtolower($propName));
        if (null === $meta) {
            return null;
        }
        $writeVis = PropertyVisibility::effectiveSetVisibility($meta['visibility'], $meta['setVisibility']);
        $readVis = PropertyVisibility::effectiveGetVisibility($meta['visibility'], $meta['getVisibility']);
        if ($writeVis !== $readVis) {
            return null;
        }
        if (MethodVisibility::isPublic($writeVis)) {
            return null;
        }
        try {
            // Error names the fetched class (self/static/parent/explicit), not the declarer (#29524).
            PropertyVisibility::assertAccessible(
                $meta['visibility'],
                $this->callerClassLc($frame),
                $meta['declaringClassLc'],
                $this->staticPropertyFetchClassDisplay($classLc),
                $propName,
                $this->callerClassLc($frame) ?? $meta['declaringClassLc'],
                fn (string $classLcArg, string $ancestorLc): bool => $this->isClassSameOrSubclassOf($classLcArg, $ancestorLc),
                0
            );
        } catch (\LogicException $e) {
            return $this->dispatchVmError($e->getMessage(), $frame);
        }

        return null;
    }

    private function asymmetricStaticPropertyWriteMessage(string $classLc, string $propName, Frame $frame): ?string
    {
        $meta = $this->resolveStaticPropertyVisibilityMeta($classLc, strtolower($propName));
        if (null === $meta) {
            return null;
        }
        $setVis = PropertyVisibility::effectiveSetVisibility($meta['visibility'], $meta['setVisibility']);
        $readVis = PropertyVisibility::effectiveGetVisibility($meta['visibility'], $meta['getVisibility']);
        if ($setVis === $readVis) {
            return null;
        }
        $callerLc = $this->callerClassLc($frame);
        try {
            PropertyVisibility::assertWritable(
                $setVis,
                $callerLc,
                $meta['declaringClassLc'],
                $this->staticPropertyFetchClassDisplay($classLc),
                $propName,
                fn (string $child, string $parent): bool => $this->isSubclassOf($child, $parent),
                MethodVisibility::mask($readVis),
                $meta['asymmetricExplicitRead'] ?? false,
                $this->callerScopeDisplay($frame, $callerLc)
            );
        } catch (\LogicException $e) {
            return $e->getMessage();
        }

        return null;
    }

    private function enforceStaticPropertyReadVisibility(
        string $classLc,
        string $propNameRaw,
        Frame $frame
    ): ?Frame {
        $propLc = strtolower($propNameRaw);
        $meta = $this->resolveStaticPropertyVisibilityMeta($classLc, $propLc);
        if (null === $meta) {
            return null;
        }
        $readVis = PropertyVisibility::effectiveGetVisibility($meta['visibility'], $meta['getVisibility']);
        if (MethodVisibility::isPublic($readVis)) {
            return null;
        }
        $callerLc = $this->callerClassLc($frame);
        try {
            // php-src zend_std_get_static_property: Error uses the fetch CE (self→child), not declarer (#29524).
            PropertyVisibility::assertAccessible(
                $meta['visibility'],
                $callerLc,
                $meta['declaringClassLc'],
                $this->staticPropertyFetchClassDisplay($classLc),
                $propNameRaw,
                $callerLc ?? $meta['declaringClassLc'],
                fn (string $classLcArg, string $ancestorLc): bool => $this->isClassSameOrSubclassOf($classLcArg, $ancestorLc),
                $meta['getVisibility']
            );
        } catch (\LogicException $e) {
            return $this->dispatchVmError($e->getMessage(), $frame);
        }

        return null;
    }

    /** Display name of the class used in a static property fetch (self/static/parent/Foo::). */
    private function staticPropertyFetchClassDisplay(string $classLc): string
    {
        return $this->context->classes[$classLc]->name ?? $classLc;
    }

    /**
     * Closure scope (ce) for self/parent/private — not late-static called_scope (#3673, #25793).
     *
     * Explicit bindTo($obj, null) leaves boundScopeClass null/empty; do not fall back to
     * the definition-site func->class or calledClass ($this) — that re-widens visibility
     * (#10097, #25838, zend_closures.c).
     */
    private function boundClosureScopeClassLc(Frame $frame): ?string
    {
        if (null === $frame->block || null === $frame->block->func) {
            return null;
        }
        if ((($frame->block->func->flags ?? 0) & \PHPCfg\Func::FLAG_CLOSURE) === 0) {
            return null;
        }
        $state = $frame->closureCall ?? $frame->pendingClosureInvoke;
        if (null !== $state) {
            if (null === $state->boundScopeClass || '' === $state->boundScopeClass) {
                return null;
            }

            return strtolower($state->boundScopeClass);
        }
        if (null !== $frame->block->func->class && null !== $frame->block->func->class->value
            && '' !== $frame->block->func->class->value) {
            return strtolower($frame->block->func->class->value);
        }

        return null;
    }

    /**
     * Late-static called_scope for a closure: $this's class, else stored creation LSB, else scope.
     */
    private function closureCalledScopeClass(ClosureState $state): ?string
    {
        if (null !== $state->boundThis) {
            $thisObj = $state->boundThis->resolveIndirect();
            if (Variable::TYPE_OBJECT === $thisObj->type) {
                return $thisObj->toObject()->class->name;
            }
        }
        if (null !== $state->boundCalledScopeClass && '' !== $state->boundCalledScopeClass) {
            return $state->boundCalledScopeClass;
        }
        if (null !== $state->boundScopeClass && '' !== $state->boundScopeClass) {
            return $state->boundScopeClass;
        }

        return null;
    }

    private function callerClassLc(Frame $frame): ?string
    {
        $classLc = $this->boundClosureScopeClassLc($frame);
        if (null === $classLc) {
            // Closure frames: scope (ce) is the only visibility source. calledClass is
            // late-static ($this class from #25793) and must not grant protected/private
            // when bindTo left the scope unbound (#10097, #25838).
            $isClosure = null !== $frame->block
                && null !== $frame->block->func
                && (($frame->block->func->flags ?? 0) & \PHPCfg\Func::FLAG_CLOSURE) !== 0;
            if (!$isClosure) {
                if (null !== $frame->block && null !== $frame->block->func && null !== $frame->block->func->class) {
                    $classLc = strtolower($frame->block->func->class->value);
                } elseif (null !== $frame->calledClass && '' !== $frame->calledClass) {
                    $classLc = strtolower($frame->calledClass);
                }
            }
        }
        if (null === $classLc) {
            return null;
        }
        // Trait methods: resolve to composing class (zend_traits.c scope copy; #24732 / #36382).
        if (isset($this->context->classes[$classLc]) && $this->context->classes[$classLc]->isTrait) {
            $composing = $this->resolveTraitComposingClassLc($frame, $classLc);
            if (null !== $composing) {
                $classLc = $composing;
            }
        }
        $traitLc = $this->traitScopeLcForFrameMethod($frame, $classLc);

        return $traitLc ?? $classLc;
    }

    /**
     * php-src: unbound Closure::bind/bindTo uses lexical scope "Closure" in visibility errors (zend_closures.c).
     */
    private function callerScopeDisplay(Frame $frame, ?string $callerClassLc): ?string
    {
        if (null !== $callerClassLc && isset($this->context->classes[$callerClassLc])) {
            return $this->context->classes[$callerClassLc]->name;
        }
        if ($this->isUnscopedUserClosureFrame($frame)) {
            return 'Closure';
        }

        return null;
    }

    private function isUnscopedUserClosureFrame(Frame $frame): bool
    {
        $state = $frame->closureCall ?? $frame->pendingClosureInvoke;
        if (null === $state || !$state->isUserClosure()) {
            return false;
        }

        return null === $state->boundScopeClass || '' === $state->boundScopeClass;
    }

    /**
     * When executing inside a trait method, resolve the composing (using) class from $this or
     * calledClass — Zend rebinds trait methods into the using class's scope (#24732).
     */
    private function resolveTraitComposingClassLc(Frame $frame, string $traitClassLc): ?string
    {
        // Prefer the object's actual class from $this.
        if (!empty($frame->scope)) {
            foreach ($frame->scope as $var) {
                if (Variable::TYPE_OBJECT === $var->type) {
                    $objClassLc = strtolower($var->toObject()->class->name);
                    if ($objClassLc !== $traitClassLc
                        && (!isset($this->context->classes[$objClassLc]) || !$this->context->classes[$objClassLc]->isTrait)) {
                        return $objClassLc;
                    }
                }
                break; // slot 0 is $this
            }
        }
        if (null !== $frame->calledClass && '' !== $frame->calledClass) {
            $calledLc = strtolower($frame->calledClass);
            if ($calledLc !== $traitClassLc
                && (!isset($this->context->classes[$calledLc]) || !$this->context->classes[$calledLc]->isTrait)) {
                return $calledLc;
            }
        }

        return null;
    }

    /**
     * After trait flatten, private methods use the composing class as scope (zend_traits.c
     * copies fn->common.scope to the using class) — same as protected/public (#24732).
     *
     * Returning the trait name here made private trait→trait calls and private property
     * writes fail with "from scope Trait" while Zend succeeds (Nyholm MessageTrait / #36382).
     * Const self:: access still uses {@see isInTraitMethodScopeForTrait} via func->class.
     */
    private function traitScopeLcForFrameMethod(Frame $frame, string $classLc): ?string
    {
        return null;
    }

    /**
     * Resolve instance owner for a property-write lvalue, including indirect wrappers (#6146).
     */
    private function resolvePropertyWriteOwner(Variable $lvalue): ?ObjectEntry
    {
        $var = $lvalue;
        $seen = [];
        while (true) {
            $id = \spl_object_id($var);
            if (isset($seen[$id])) {
                break;
            }
            $seen[$id] = true;
            if (null !== $var->objectPropertyOwner) {
                return $var->objectPropertyOwner;
            }
            if (null !== $var->magicSetTarget) {
                return $var->magicSetTarget;
            }
            if (null !== $var->hookedPropertyDimWriteBackContainer) {
                $container = $var->hookedPropertyDimWriteBackContainer;
                if (null !== $container->objectPropertyOwner) {
                    return $container->objectPropertyOwner;
                }
            }
            if (!$var->isIndirect()) {
                break;
            }
            $next = $var->directIndirectTarget();
            if (null === $next) {
                break;
            }
            $var = $next;
        }

        return null;
    }

    /** Property name for a property-write lvalue when metadata lives on an indirect wrapper (#6146). */
    private function resolvePropertyWriteName(Variable $lvalue): ?string
    {
        $var = $lvalue;
        $seen = [];
        while (true) {
            $id = \spl_object_id($var);
            if (isset($seen[$id])) {
                break;
            }
            $seen[$id] = true;
            if (null !== $var->objectPropertyName) {
                return $var->objectPropertyName;
            }
            if (null !== $var->magicSetName) {
                return $var->magicSetName;
            }
            if (null !== $var->hookedPropertyDimWriteBackContainer) {
                $container = $var->hookedPropertyDimWriteBackContainer;
                if (null !== $container->objectPropertyName) {
                    return $container->objectPropertyName;
                }
            }
            if (!$var->isIndirect()) {
                break;
            }
            $next = $var->directIndirectTarget();
            if (null === $next) {
                break;
            }
            $var = $next;
        }

        return null;
    }

    private function readonlyPropertyDeclaringClass(ObjectEntry $object, string $propName): ?string
    {
        $meta = $this->classPropertyMeta($object, $propName);
        if (null !== $meta && ($meta->readonly || $object->class->readonly)) {
            if ('' !== $meta->declaringClassLc && isset($this->context->classes[$meta->declaringClassLc])) {
                return $this->context->classes[$meta->declaringClassLc]->name;
            }

            return $meta->declaringClassLc !== '' ? $meta->declaringClassLc : $object->class->name;
        }
        if ($object->class->readonly) {
            return $object->class->name;
        }

        return null;
    }

    private function readonlyPropertyDeclaringClassLc(ObjectEntry $object, string $propName): ?string
    {
        $meta = $this->classPropertyMeta($object, $propName);
        if (null !== $meta && ($meta->readonly || $object->class->readonly)) {
            return '' !== $meta->declaringClassLc ? $meta->declaringClassLc : strtolower($object->class->name);
        }
        if ($object->class->readonly) {
            return strtolower($object->class->name);
        }

        return null;
    }

    /** Reject asymmetric set visibility violations (#3165, #6898); returns catch frame or null. */
    private function enforceAsymmetricPropertyWrite(Variable $lvalue, Frame $frame): ?Frame
    {
        $msg = $this->asymmetricPropertyWriteMessage($lvalue, $frame);
        if (null === $msg) {
            return null;
        }

        return $this->dispatchVmError($msg, $frame);
    }

    /**
     * Hook-block asymmetric markers use {@code set (private);} (php-compiler) or decl-site
     * {@code private(set)}; bare {@code private(set);} on a hook is a compile fatal (#29388).
     */
    private function propertyHasDistinctAsymmetricSetVisibility(
        ?string $staticClassLc,
        string $propName,
        Variable $lvalue
    ): bool {
        if (is_string($staticClassLc) && isset($this->context->classes[$staticClassLc])) {
            $visMeta = $this->resolveStaticPropertyVisibilityMeta($staticClassLc, strtolower($propName));

            return null !== $visMeta
                && PropertyVisibility::effectiveSetVisibility($visMeta['visibility'], $visMeta['setVisibility'])
                    !== MethodVisibility::mask($visMeta['visibility']);
        }
        $owner = $this->resolvePropertyWriteOwner($lvalue);
        if (null === $owner) {
            return false;
        }
        $meta = $this->classPropertyMeta($owner, $propName);
        if (null === $meta) {
            return false;
        }

        return PropertyVisibility::effectiveSetVisibility($meta->visibility, $meta->setVisibility)
            !== MethodVisibility::mask($meta->visibility);
    }

    /** Reject asymmetric set visibility violations (#3165); returns message or null. */
    private function asymmetricPropertyWriteMessage(Variable $lvalue, Frame $frame): ?string
    {
        $target = $lvalue->resolveIndirect();
        $owner = $target->objectPropertyOwner;
        if (null === $owner) {
            return null;
        }
        $propName = $target->objectPropertyName ?? '';
        if ('' === $propName) {
            return null;
        }

        return $this->asymmetricPropertyWriteMessageForMeta($owner, $propName, $frame, false);
    }

    /**
     * @param bool $readonlyReinitWindow when true, enforce aviz even for readonly props (clone-with, #29186)
     */
    private function asymmetricPropertyWriteMessageForMeta(
        ObjectEntry $owner,
        string $propName,
        Frame $frame,
        bool $readonlyReinitWindow
    ): ?string {
        $meta = $this->classPropertyMeta($owner, $propName);
        if (null === $meta) {
            return null;
        }
        // Ordinary readonly writes use the readonly Error; aviz applies after reinit unlock (#29186).
        if ($meta->readonly && !$readonlyReinitWindow) {
            return null;
        }
        $setVis = PropertyVisibility::effectiveSetVisibility($meta->visibility, $meta->setVisibility);
        $readVis = PropertyVisibility::effectiveGetVisibility($meta->visibility, $meta->getVisibility);
        if ($setVis === $readVis) {
            return null;
        }
        // Use declaring class (not runtime object class) so private(set) denies child scopes (#23110).
        $declaringLc = '' !== $meta->declaringClassLc
            ? $meta->declaringClassLc
            : strtolower($owner->class->name);
        $declaringDisplay = $this->context->classes[$declaringLc]->name
            ?? $owner->class->name;
        $callerLc = $this->callerClassLc($frame);
        try {
            PropertyVisibility::assertWritable(
                $setVis,
                $callerLc,
                $declaringLc,
                $declaringDisplay,
                $propName,
                fn (string $child, string $parent): bool => $this->isSubclassOf($child, $parent),
                MethodVisibility::mask($readVis),
                $meta->asymmetricExplicitRead,
                $this->callerScopeDisplay($frame, $callerLc),
                $meta->readonly
            );
        } catch (\LogicException $e) {
            return $e->getMessage();
        }

        return null;
    }
}
