<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\DnfCheck;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\TypeCheck;
use PHPCompiler\VM\Variable;
use PHPCompiler\VM\VmIsset;

/**
 * Object property isset / empty / unset + property hooks for the VM (#36403).
 *
 * Extracted from {@see \PHPCompiler\VM}: {@code objectPropertyIsSet} through
 * {@code slotIsReferenceBinding} (Zend zend_std_has_property / property hooks parity).
 * Concern trait — same namespace as parent so relative Frame / VM helpers resolve.
 */
trait ObjectPropertyIssetEmptyUnset
{
    /**
     * isset($obj->prop) — Zend zend_std_has_property / __isset parity (#3298, #4586, #25668).
     * Hooked properties: invoke get when zend_should_call_hook (virtual / initialized backing);
     * uninitialized same-name backed skips get (#30739, #11617).
     * Incomplete objects: E_WARNING + false (zend_object_handlers.c, #19632).
     * Inaccessible declared props skip the slot and route through __isset (zend_object_handlers.c).
     */
    public function objectPropertyIsSet(ObjectEntry $object, string $propName, ?Frame $frame = null): bool
    {
        if (VM\IncompleteClassSupport::isIncomplete($object)) {
            VM\IncompleteClassSupport::emitAccessWarning($object, $this->context, $frame);

            return false;
        }
        $hookedIsset = $this->issetHookedPropertyForIssetEmpty($object, $propName, $frame);
        if (null !== $hookedIsset) {
            return $hookedIsset;
        }
        // Dom\HTMLDocument / Element / Node computed props (#20540, #20532, #21033).
        $computedIsset = VM\ObjectComputedPropertySupport::propertyIsSet($object, $propName);
        if (null !== $computedIsset) {
            return $computedIsset;
        }
        // ReflectionAttribute / other C-only slots are not PHP-visible (#22513).
        $meta = $this->classPropertyMeta($object, $propName, $frame);
        if (null !== $meta && $meta->phpInvisible) {
            return false;
        }
        $props = $object->getRawProperties();
        if (isset($props[$propName])) {
            // Declared but not visible from caller scope — do not leak the private/protected slot (#25668).
            // Post-unset declared slots also route through __isset (zend_std_has_property; #25810).
            $useOverload = $object->isPropertyExplicitlyUnset($propName)
                || (
                    null !== $frame
                    && null !== $meta
                    && $this->declaredPropertyIssetUsesOverload($object, $meta, $propName, $frame)
                );
            if ($useOverload) {
                // Fall through to __isset / false (zend_std_has_property).
            } else {
                return VmIsset::storedPropertyIsSet($props[$propName]);
            }
        }
        // ArrayObject/ArrayIterator::ARRAY_AS_PROPS — backing keys as properties (spl_array.c; #22576).
        // has_property(isset) shares spl_array_has_dimension null-check (#24398, peer #24251).
        if (VM\SplArraySupport::hasArrayAsProps($object)) {
            $key = new Variable(Variable::TYPE_STRING);
            $key->string($propName);
            $native = $this->nativeSplArrayDimensionIsSet($object, $key);
            if (null !== $native) {
                return $native;
            }

            return VM\SplArraySupport::offsetExists($object, $key);
        }
        if ($this->hasInstanceMethod($object->class, '__isset')) {
            if ($object->isPropertyGuardActive($propName, ObjectEntry::GUARD_IN_ISSET)) {
                return false;
            }
            if (!$object->beginPropertyGuard($propName, ObjectEntry::GUARD_IN_ISSET)) {
                return false;
            }
            try {
                $key = new Variable();
                $key->string($propName);
                $result = $this->invokeInstanceMethod($object, '__isset', $key)->resolveIndirect();

                return $result->toBool();
            } finally {
                $object->endPropertyGuard($propName, ObjectEntry::GUARD_IN_ISSET);
            }
        }

        return false;
    }

    /**
     * ?? / ??= on property hooks — Zend BP_VAR_IS invokes get when present (#29266, zend_object_handlers.c).
     * Write-only (no get): probe backing (#6472). Incomplete: E_WARNING + false (#19632).
     */
    public function objectPropertyIsSetForCoalesceAssign(ObjectEntry $object, string $propName, ?Frame $frame = null): bool
    {
        if (VM\IncompleteClassSupport::isIncomplete($object)) {
            VM\IncompleteClassSupport::emitAccessWarning($object, $this->context, $frame);

            return false;
        }
        $hookedIsset = $this->issetHookedPropertyForIssetEmpty($object, $propName, $frame);
        if (null !== $hookedIsset) {
            return $hookedIsset;
        }

        return $this->objectPropertyIsSet($object, $propName, $frame);
    }

    /**
     * ?? / ??= on static property hooks — invoke get when present (#29266); else backing (#9683).
     */
    public function fetchStaticPropertyForCoalesce(
        string $classLc,
        string $propNameRaw,
        Variable $dst,
        ?Frame $frame = null
    ): void {
        $propLc = strtolower($propNameRaw);
        $hooks = $this->resolveStaticPropertyHooks($classLc, $propLc);
        if (null !== $hooks && isset($hooks['get']) && null !== $frame) {
            $hookValue = $this->fetchStaticPropertyWithHooks($classLc, $propNameRaw, $hooks['get'], $frame);
            $dst->copyFromForClone($hookValue->resolveIndirect());

            return;
        }
        $backing = $this->hookedStaticPropertyBackingValue($classLc, $propNameRaw);
        if (false !== $backing) {
            $this->copyPropertyValueForIsMode($dst, $backing);

            return;
        }
        $storage = $this->resolveStaticPropertyStorage($classLc, strtolower($propNameRaw));
        if (null !== $storage) {
            $this->copyPropertyValueForIsMode($dst, $storage);

            return;
        }
        $dst->undefined();
    }

    /**
     * ReflectionClass::getStaticPropertyValue / ReflectionProperty::getValue on static props.
     * Invokes get hook when present instead of reading uninitialized backing (#9863, php_reflection.c).
     */
    public function readStaticPropertyForReflection(
        string $classLc,
        string $propertyName,
        Variable $backingStorage,
        ?Variable $default,
        Frame $frame
    ): Variable {
        $propLc = strtolower($propertyName);
        $hooks = $this->resolveStaticPropertyHooks($classLc, $propLc);
        if (null !== $hooks && isset($hooks['get'])) {
            return $this->fetchStaticPropertyWithHooks($classLc, $propertyName, $hooks['get'], $frame);
        }
        if (VM\TypedPropertyCheck::isUninitialized($backingStorage)) {
            if (null !== $default) {
                $out = new Variable();
                $out->copyFrom($default);

                return $out;
            }
            throw new \Error(VM\TypedPropertyCheck::errorMessage($backingStorage));
        }
        $out = new Variable();
        $out->copyFrom($backingStorage->resolveIndirect());

        return $out;
    }

    /**
     * ReflectionProperty::setValue on static props — invoke set hook when present (#4469, php_reflection.c).
     */
    public function writeStaticPropertyForReflection(
        ClassEntry $entry,
        string $propertyName,
        Variable $value,
        Frame $frame
    ): void {
        $classLc = strtolower(ltrim($entry->name, '\\'));
        $propLc = strtolower($propertyName);
        $hooks = $this->resolveStaticPropertyHooks($classLc, $propLc);
        if (null !== $hooks && !empty($hooks['set'])) {
            $setLc = $hooks['set'];
            if (isset($entry->methods[$setLc])) {
                $func = $entry->methods[$setLc];
                if ($func instanceof Func\PHP) {
                    $this->context->propertyHookSetAborted = false;
                    $this->invokeStaticPropertyHookRaw(
                        $func,
                        $propertyName,
                        $classLc,
                        $frame,
                        $value->resolveIndirect()
                    );

                    return;
                }
            }
        }
        \PHPCompiler\ext\standard\VmReflection::setStaticPropertyValueForReflection(
            $entry,
            $this->context,
            $propertyName,
            $value
        );
    }

    /**
     * ReflectionProperty::setValue on instance props — invoke set hook when present (#4469, php_reflection.c).
     */
    public function writeInstancePropertyForReflection(
        ObjectEntry $object,
        string $instanceName,
        ?VM\ClassProperty $meta,
        Variable $value,
        Frame $frame
    ): void {
        $this->assertReadonlyPropertyWriteAllowedForReflection($object, $instanceName, $frame);
        $this->assertFinalPropertyWriteAllowedForReflection($object, $instanceName);
        $setLc = $meta?->setHookMethodLc
            ?? strtolower(SourcePreprocessor\PropertyHooks::setHookMethodName($instanceName));
        if (isset($object->class->methods[$setLc])) {
            $func = $object->class->methods[$setLc];
            if ($func instanceof Func\PHP) {
                $this->context->propertyHookSetAborted = false;
                $thisVar = new Variable();
                $thisVar->object($object);
                $this->invokePhpFunctionWithPropertyHookRaw(
                    $func,
                    $instanceName,
                    $frame,
                    $thisVar,
                    $value->resolveIndirect()
                );

                return;
            }
        }
        $slot = $object->getProperty($instanceName);
        $slot->copyFrom($value->resolveIndirect());
        TypeCheck::coercePropertyWrite($slot, false);
        $resolved = $slot->resolveIndirect();
        if (null !== $resolved->dnfArms) {
            DnfCheck::assertMatches(
                $value,
                $resolved->dnfArms,
                $this->context,
                'Property',
                $resolved,
                false
            );
        }
    }

    /**
     * ReflectionProperty::getRawValue — read backing storage without get hook (#6451, php_reflection.c).
     */
    public function readInstancePropertyRawForReflection(
        ObjectEntry $object,
        string $instanceName,
        ?VM\ClassProperty $meta
    ): Variable {
        $slot = $this->instancePropertyRawBackingSlot($object, $instanceName);
        if (null === $slot) {
            throw new \LogicException('Undefined property in this compiler build');
        }
        if (VM\TypedPropertyCheck::isUninitialized($slot)) {
            throw new \Error(VM\TypedPropertyCheck::errorMessage($slot));
        }
        $out = new Variable();
        $out->copyFrom($slot->resolveIndirect());

        return $out;
    }

    /**
     * ReflectionProperty::setRawValue — write backing storage without set hook (#6451, php_reflection.c).
     */
    public function writeInstancePropertyRawForReflection(
        ObjectEntry $object,
        string $instanceName,
        ?VM\ClassProperty $meta,
        Variable $value,
        bool $strictTypes
    ): void {
        $slot = $this->instancePropertyRawBackingSlot($object, $instanceName);
        if (null === $slot) {
            throw new \LogicException('Undefined property in this compiler build');
        }
        if (null !== $meta) {
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
            VM\TypeCheck::coercePropertyWrite($probe, $strictTypes);
            $slot->copyFrom($probe);

            return;
        }
        $slot->copyFrom($value->resolveIndirect());
        VM\TypeCheck::coercePropertyWrite($slot, $strictTypes);
    }

    /**
     * Writable slot for hooked or plain instance property backing (#6451).
     */
    private function instancePropertyRawBackingSlot(ObjectEntry $object, string $propName): ?Variable
    {
        if ($this->instancePropertyHasHooks($object, $propName)) {
            $lcClass = strtolower($object->class->name);
            $propMeta = $this->context->propertyHookRegistry[$lcClass][$propName]
                ?? $this->context->propertyHookRegistry[$lcClass][strtolower($propName)]
                ?? null;
            if (is_array($propMeta)) {
                $backingName = $propMeta['setBacking'] ?? $propMeta['getBacking'] ?? null;
                if (null !== $backingName && strcasecmp($backingName, $propName) !== 0) {
                    if ($object->hasProperty($backingName)) {
                        return $object->getProperty($backingName);
                    }

                    return null;
                }
            }
            if ($object->hasProperty($propName)) {
                return $object->getProperty($propName);
            }

            return null;
        }
        if ($object->hasProperty($propName)) {
            return $object->getProperty($propName);
        }

        return null;
    }

    /**
     * ?? / ??= isset probe on static hooked properties — backing only, never get hook (#9683).
     */
    public function staticPropertyIsSetForCoalesceAssign(string $classLc, string $propNameRaw): bool
    {
        $hookedIsset = $this->issetHookedStaticPropertyWithoutGetHook($classLc, $propNameRaw);
        if (null !== $hookedIsset) {
            return $hookedIsset;
        }
        $storage = $this->resolveStaticPropertyStorage($classLc, strtolower($propNameRaw));
        if (null === $storage) {
            return false;
        }
        $value = $storage->resolveIndirect();
        if ($value->isUndefined() || VM\TypedPropertyCheck::isUninitialized($value)) {
            return false;
        }

        return Variable::TYPE_NULL !== $value->type;
    }

    /**
     * ?? / ??= isset probe on hooked properties when no get hook — backing only (#8902, #6472).
     * Prefer {@see issetHookedPropertyForIssetEmpty} when a get hook may exist (#29266).
     *
     * @return bool|null null when the property is not hook-backed
     */
    private function issetHookedPropertyWithoutGetHook(ObjectEntry $object, string $propName): ?bool
    {
        $backing = $this->hookedPropertyBackingValue($object, $propName);
        if (false === $backing) {
            return null;
        }
        if ($backing->isUndefined() || VM\TypedPropertyCheck::isUninitialized($backing)) {
            return false;
        }

        return Variable::TYPE_NULL !== $backing->type;
    }

    /**
     * isset($obj->hooked) — php-src zend_std_has_property + zend_should_call_hook (#30739, #29214).
     * Virtual / distinct-backing / initialized same-name: invoke get. Uninitialized same-name
     * backed: false without get (Zend/zend_property_hooks.c). Write-only: probe backing (#6484).
     *
     * @return bool|null null when the property is not hook-backed
     */
    private function issetHookedPropertyForIssetEmpty(ObjectEntry $object, string $propName, ?Frame $frame): ?bool
    {
        if (!$this->instancePropertyHasHooks($object, $propName)) {
            return null;
        }
        if (!$this->instancePropertyHasGetHook($object, $propName)) {
            return $this->issetHookedPropertyWithoutGetHook($object, $propName);
        }
        if (null === $frame) {
            return $this->issetHookedPropertyWithoutGetHook($object, $propName);
        }
        // Inside get/set for this prop: isset($this->prop) is BP_VAR_IS on backing
        // (zend_should_call_hook) — uninitialized typed slots are unset, not Error (#29688).
        if ($this->isPropertyHookRawWrite($frame, $propName)) {
            return $this->issetHookedPropertyWithoutGetHook($object, $propName);
        }
        // External isset: uninitialized same-name backing must not invoke get (#30739).
        if ($this->skipHookedGetForUninitializedSameNameBacking($object, $propName)) {
            return false;
        }
        try {
            $hookValue = $this->fetchPropertyWithHooks($object, $propName, $frame);
        } catch (VM\PropertyHookRefWriteSignal) {
            return false;
        }
        if (null === $hookValue) {
            return $this->issetHookedPropertyWithoutGetHook($object, $propName);
        }
        $value = $hookValue->resolveIndirect();

        return Variable::TYPE_NULL !== $value->type;
    }

    /**
     * ?? / ??= quiet property read (zend BP_VAR_IS / coalesce) (#6472, #8902, #29228, #29266).
     * Hooked props with get: invoke get (zend_std_read_property; virtual get-only included).
     * Write-only hooked: backing probe only. Magic: __isset then __get, or __get alone
     * when __isset is absent (unlike isset(), which stays false without __isset).
     * ArrayObject/ArrayIterator::ARRAY_AS_PROPS — backing keys (spl_array.c; #22649, re-#22576).
     *
     * @return Frame|null catch frame when a hook/type guard throws into userland
     */
    public function fetchObjectPropertyForCoalesce(
        ObjectEntry $object,
        string $propName,
        Variable $dst,
        ?Frame $frame = null
    ): ?Frame {
        // Inside get/set for this prop: $this->prop ?? … is BP_VAR_IS on backing
        // (zend_should_call_hook) — skip typed-uninit Error from raw re-entry (#29688).
        if (null !== $frame && $this->isPropertyHookRawWrite($frame, $propName)) {
            $rawBacking = $this->hookedPropertyBackingValue($object, $propName);
            if (false !== $rawBacking) {
                $this->copyPropertyValueForIsMode($dst, $rawBacking);
            } else {
                $dst->undefined();
            }

            return null;
        }
        // External ?? : uninitialized same-name backing is unset without get (#30739).
        if ($this->skipHookedGetForUninitializedSameNameBacking($object, $propName)) {
            $dst->undefined();

            return null;
        }
        if (null !== $frame && $this->instancePropertyHasGetHook($object, $propName)) {
            try {
                $hookValue = $this->fetchPropertyWithHooks($object, $propName, $frame);
            } catch (VM\PropertyHookRefWriteSignal $signal) {
                return $signal->catchFrame;
            }
            if (null !== $hookValue) {
                $dst->copyFrom($hookValue->resolveIndirect());

                return null;
            }
        }
        $backing = $this->hookedPropertyBackingValue($object, $propName);
        if (false !== $backing) {
            $this->copyPropertyValueForIsMode($dst, $backing);

            return null;
        }
        // ARRAY_AS_PROPS storage is not declarative object properties — mirror PROPERTY_FETCH read.
        if (VM\SplArraySupport::hasArrayAsProps($object)) {
            $key = new Variable(Variable::TYPE_STRING);
            $key->string($propName);
            if (VM\SplArraySupport::offsetExists($object, $key)) {
                $dst->copyFrom(VM\SplArraySupport::offsetGet($object, $key));
            } else {
                $dst->null();
            }

            return null;
        }
        // Overloaded / inaccessible: coalesce consults __get (zend_std_read_property IS-mode; #29228).
        if (
            null !== $frame
            && $this->propertyReadUsesMagicGet($object, $propName, $frame)
        ) {
            if ($this->hasInstanceMethod($object->class, '__isset')) {
                if (!$this->objectPropertyIsSet($object, $propName, $frame)) {
                    $dst->undefined();

                    return null;
                }
            }
            $got = $this->invokeMagicGet($object, $propName)->resolveIndirect();
            $dst->copyFrom($got);

            return null;
        }
        // No __get: inaccessible declared slots are unset for ?? (zend_std_has_property; #29503).
        if (null !== $frame) {
            $meta = $this->classPropertyMeta($object, $propName, $frame);
            if (
                null !== $meta
                && $this->declaredPropertyInaccessibleFromCaller(
                    $object,
                    $meta,
                    $propName,
                    $frame,
                    $meta->getVisibility
                )
            ) {
                $dst->undefined();

                return null;
            }
        }
        if ($object->hasProperty($propName)) {
            // Plain typed slots: BP_VAR_IS must not Error (#31146, zend_object_handlers.c).
            $this->copyPropertyValueForIsMode($dst, $object->getProperty($propName));
        } else {
            $dst->undefined();
        }

        return null;
    }

    /**
     * empty($obj->prop) — uninitialized typed slots are empty without read (#6787, zend_object_handlers.c);
     * declared/dynamic slots use value truthiness (zend_is_true), not isset alone (#23983);
     * magic: __isset first, then __get + truthiness when set (#3298, zend_object_handlers.c).
     * Incomplete objects: E_WARNING + empty (true) (#19632).
     */
    public function emptyObjectProperty(ObjectEntry $object, string $propName, Frame $frame, Variable $dst): ?Frame
    {
        if (VM\IncompleteClassSupport::isIncomplete($object)) {
            VM\IncompleteClassSupport::emitAccessWarning($object, $this->context, $frame);
            $dst->bool(true);

            return null;
        }
        // SimpleXMLElement / Dom computed empty($obj->prop) (#19707, #20540, #20532, #21033).
        $computedEmpty = VM\ObjectComputedPropertySupport::propertyIsEmpty($object, $propName);
        if (null !== $computedEmpty) {
            $dst->bool($computedEmpty);

            return null;
        }
        $catchFrame = $this->enforceWriteOnlyVirtualPropertyRead($object, $propName, $frame);
        if (null !== $catchFrame) {
            return $catchFrame;
        }
        try {
            if ($this->emptyHookedProperty($object, $propName, $frame, $dst)) {
                return null;
            }
        } catch (VM\PropertyHookRefWriteSignal $signal) {
            return $signal->catchFrame;
        }
        // ArrayObject/ArrayIterator::ARRAY_AS_PROPS — empty mirrors offset value truthiness (#22576).
        if (VM\SplArraySupport::hasArrayAsProps($object)) {
            $key = new Variable(Variable::TYPE_STRING);
            $key->string($propName);
            if (!VM\SplArraySupport::offsetExists($object, $key)) {
                $dst->bool(true);

                return null;
            }
            $dst->bool(!ext\standard\boolval::isTruthy(VM\SplArraySupport::offsetGet($object, $key)));

            return null;
        }
        // Accessible declared/dynamic slot: value truthiness (zend_is_true). Inaccessible declared
        // props are unset for empty unless __isset/__get overload applies (#23983).
        if (
            $object->hasProperty($propName)
            && $this->isInstancePropertyReadableForEmpty($object, $propName, $frame)
        ) {
            $props = $object->getRawProperties();
            if (isset($props[$propName]) && VM\TypedPropertyCheck::isUninitialized($props[$propName])) {
                $dst->bool(true);

                return null;
            }
            $slot = $object->getProperty($propName);
            $dst->bool(!ext\standard\boolval::isTruthy($slot));

            return null;
        }
        // Overload: zend_std_has_property(check_empty) — __isset first; only then __get + zend_is_true (#23983).
        if ($this->hasInstanceMethod($object->class, '__isset')) {
            if (!$this->objectPropertyIsSet($object, $propName, $frame)) {
                $dst->bool(true);

                return null;
            }
            if ($this->propertyReadUsesMagicGet($object, $propName, $frame)) {
                $read = new Variable();
                $this->deliverMagicGetRead($read, $object, $propName);
                $dst->bool(!ext\standard\boolval::isTruthy($read));

                return null;
            }
            // __isset true but no readable value (no __get / no slot) — treat as empty (null-like).
            $dst->bool(true);

            return null;
        }
        // __get without __isset, or inaccessible without magic: empty does not invoke __get.
        $dst->bool(true);

        return null;
    }

    /**
     * empty(Class::$prop) — uninitialized typed statics empty without read; else value truthiness (#23983, #6787).
     */
    public function emptyStaticProperty(string $classLc, string $propNameRaw, Frame $frame, Variable $dst): ?Frame
    {
        $visFrame = $this->enforceStaticPropertyReadVisibility($classLc, $propNameRaw, $frame);
        if (null !== $visFrame) {
            return $visFrame;
        }
        $propLc = strtolower($propNameRaw);
        $hooks = $this->resolveStaticPropertyHooks($classLc, $propLc);
        if (null !== $hooks && isset($hooks['get'])) {
            if ($this->emptyHookedStaticProperty($classLc, $propNameRaw, $frame, $dst)) {
                return null;
            }
        }
        $storage = $this->resolveStaticPropertyStorage($classLc, $propLc);
        if (null === $storage) {
            $dst->bool(true);

            return null;
        }
        $value = $storage->resolveIndirect();
        if ($value->isUndefined() || VM\TypedPropertyCheck::isUninitialized($value)) {
            $dst->bool(true);

            return null;
        }
        $dst->bool(!ext\standard\boolval::isTruthy($value));

        return null;
    }

    public function unsetObjectProperty(ObjectEntry $object, string $propName): void
    {
        // php-src date_interval_get_property_ptr_ptr — living fields ignore unset (#26180).
        if (VM\DateIntervalSupport::shouldNoopUnset($object, $propName)) {
            return;
        }
        $props = $object->getRawProperties();
        if (isset($props[$propName])) {
            $object->unsetProperty($propName);

            return;
        }
        // ArrayObject/ArrayIterator::ARRAY_AS_PROPS — unset mirrors offsetUnset (spl_array.c; #22576).
        if (VM\SplArraySupport::hasArrayAsProps($object)) {
            $key = new Variable(Variable::TYPE_STRING);
            $key->string($propName);
            VM\SplArraySupport::offsetUnset($object, $key);

            return;
        }
        if ($this->hasInstanceMethod($object->class, '__unset')) {
            $key = new Variable();
            $key->string($propName);
            $this->invokeInstanceMethod($object, '__unset', $key);
        }
    }

    /**
     * unset($obj->hooked) — invoke unset hook, or Error for any get/set-hooked property (#6471, #6502, #26373).
     * Zend rejects unset on hooked properties without a dedicated unset hook (backed get+set included).
     * Inaccessible declared props: __unset or Error before touching the slot (#25668).
     */
    private function dispatchHookedInstancePropertyUnset(ObjectEntry $object, string $propName, Frame $frame): ?Frame
    {
        if ($this->isPropertyHookRawWrite($frame, $propName)) {
            $this->unsetHookedInstancePropertyRaw($object, $propName);

            return null;
        }
        $inaccessibleFrame = $this->dispatchInaccessibleDeclaredPropertyUnset($object, $propName, $frame);
        if (false !== $inaccessibleFrame) {
            return $inaccessibleFrame;
        }
        if ($this->invokeInstancePropertyUnsetHook($object, $propName, $frame)) {
            return null;
        }
        $meta = $this->classPropertyMeta($object, $propName);
        if (null !== $meta && (null !== $meta->getHookMethodLc || null !== $meta->setHookMethodLc)) {
            $className = $object->class->name;
            if ('' !== $meta->declaringClassLc && isset($this->context->classes[$meta->declaringClassLc])) {
                $className = $this->context->classes[$meta->declaringClassLc]->name;
            }

            return $this->raiseVirtualPropertyHookUnsetError(
                $className,
                $propName,
                $frame
            );
        }
        $this->unsetHookedInstanceProperty($object, $propName);

        return null;
    }

    /** unset(Class::$hooked) — unset hook, or Error for get/set-hooked statics (#6502, #26373). */
    private function dispatchHookedStaticPropertyUnset(
        string $classLc,
        string $propLc,
        string $propNameRaw,
        Variable $storage,
        Frame $frame
    ): ?Frame {
        if ($this->isPropertyHookRawWrite($frame, $propNameRaw)) {
            $storage->reset();
            $storage->type = Variable::TYPE_UNDEFINED;

            return null;
        }
        if ($this->invokeStaticPropertyUnsetHook($classLc, $propLc, $propNameRaw, $frame)) {
            return null;
        }
        $hooks = $this->resolveStaticPropertyHooks($classLc, $propLc);
        if (null !== $hooks && (!empty($hooks['get']) || !empty($hooks['set']))) {
            $className = $this->context->classes[$classLc]->name ?? $classLc;

            return $this->raiseVirtualPropertyHookUnsetError(
                $className,
                $propNameRaw,
                $frame
            );
        }
        $storage->reset();
        $storage->type = Variable::TYPE_UNDEFINED;

        return null;
    }

    /**
     * isset/empty/?? backing probe — never invokes get hook (#6472, #8901, #8917, #8918).
     *
     * @return Variable|false false when the property is not hooked
     */
    /**
     * BP_VAR_IS / ?? copy — uninitialized typed slots become undefined without Error (#29688).
     * {@see Variable::copyFrom} asserts readable (BP_VAR_R).
     */
    private function copyPropertyValueForIsMode(Variable $dst, Variable $src): void
    {
        $src = $src->resolveIndirect();
        if ($src->isUndefined() || VM\TypedPropertyCheck::isUninitialized($src)) {
            $dst->undefined();

            return;
        }
        $dst->copyFrom($src);
    }

    private function hookedPropertyBackingValue(ObjectEntry $object, string $propName): Variable|false
    {
        if (!$this->instancePropertyHasHooks($object, $propName)) {
            return false;
        }
        $lcClass = strtolower($object->class->name);
        $propMeta = $this->context->propertyHookRegistry[$lcClass][$propName]
            ?? $this->context->propertyHookRegistry[$lcClass][strtolower($propName)]
            ?? null;
        if (is_array($propMeta)) {
            $backingName = $propMeta['setBacking'] ?? $propMeta['getBacking'] ?? null;
            if (null !== $backingName) {
                if ($object->hasProperty($backingName)) {
                    return $object->getProperty($backingName)->resolveIndirect();
                }
                $uninit = new Variable();
                $uninit->undefined();

                return $uninit;
            }
        }
        if ($object->hasProperty($propName)) {
            return $object->getProperty($propName)->resolveIndirect();
        }
        $uninit = new Variable();
        $uninit->undefined();

        return $uninit;
    }

    /**
     * isset/empty/?? backing probe for static hooked properties — never invokes get hook (#9683).
     *
     * @return bool|null null when the property is not hooked
     */
    private function issetHookedStaticPropertyWithoutGetHook(string $classLc, string $propNameRaw): ?bool
    {
        $backing = $this->hookedStaticPropertyBackingValue($classLc, $propNameRaw);
        if (false === $backing) {
            return null;
        }
        if ($backing->isUndefined() || VM\TypedPropertyCheck::isUninitialized($backing)) {
            return false;
        }

        return Variable::TYPE_NULL !== $backing->type;
    }

    /**
     * @return Variable|false false when the static property is not hooked
     */
    private function hookedStaticPropertyBackingValue(string $classLc, string $propNameRaw): Variable|false
    {
        $propLc = strtolower($propNameRaw);
        if (null === $this->resolveStaticPropertyHooks($classLc, $propLc)) {
            return false;
        }
        $propMeta = $this->context->propertyHookRegistry[$classLc][$propNameRaw]
            ?? $this->context->propertyHookRegistry[$classLc][$propLc]
            ?? null;
        if (is_array($propMeta)) {
            $backingName = $propMeta['setBacking'] ?? $propMeta['getBacking'] ?? null;
            if (null !== $backingName) {
                $backingStorage = $this->resolveStaticPropertyStorage($classLc, strtolower($backingName));
                if (null !== $backingStorage) {
                    return $backingStorage->resolveIndirect();
                }
                $uninit = new Variable();
                $uninit->undefined();

                return $uninit;
            }
        }
        $storage = $this->resolveStaticPropertyStorage($classLc, $propLc);
        if (null !== $storage) {
            return $storage->resolveIndirect();
        }
        $uninit = new Variable();
        $uninit->undefined();

        return $uninit;
    }

    private function instancePropertyHasHooks(ObjectEntry $object, string $propName): bool
    {
        $meta = $this->classPropertyMeta($object, $propName);
        if (null !== $meta && (null !== $meta->getHookMethodLc || null !== $meta->setHookMethodLc)) {
            return true;
        }
        $lcClass = strtolower($object->class->name);
        $propMeta = $this->context->propertyHookRegistry[$lcClass][$propName]
            ?? $this->context->propertyHookRegistry[$lcClass][strtolower($propName)]
            ?? null;

        return is_array($propMeta) && (isset($propMeta['get']) || isset($propMeta['set']));
    }

    private function instancePropertyHasGetHook(ObjectEntry $object, string $propName): bool
    {
        $meta = $this->classPropertyMeta($object, $propName);
        if (null !== $meta && null !== $meta->getHookMethodLc) {
            return true;
        }
        $lcClass = strtolower($object->class->name);
        $propMeta = $this->context->propertyHookRegistry[$lcClass][$propName]
            ?? $this->context->propertyHookRegistry[$lcClass][strtolower($propName)]
            ?? null;

        return is_array($propMeta) && isset($propMeta['get']);
    }

    /**
     * empty(Class::$hooked) — uninitialized/unset distinct backing probes storage only;
     * initialized get-hook paths invoke get (#23983, #9683, zend_property_hooks.c).
     */
    private function emptyHookedStaticProperty(string $classLc, string $propNameRaw, Frame $frame, Variable $dst): bool
    {
        $propLc = strtolower($propNameRaw);
        $hooks = $this->resolveStaticPropertyHooks($classLc, $propLc);
        if (null === $hooks) {
            return false;
        }
        if (!is_array($hooks) || !isset($hooks['get'])) {
            $backing = $this->hookedStaticPropertyBackingValue($classLc, $propNameRaw);
            if (false === $backing) {
                return false;
            }
            $uninit = $backing->isUndefined() || VM\TypedPropertyCheck::isUninitialized($backing);
            if ($uninit) {
                $dst->bool(true);

                return true;
            }
            $dst->bool(!ext\standard\boolval::isTruthy($backing));

            return true;
        }
        $issetProbe = $this->issetHookedStaticPropertyWithoutGetHook($classLc, $propNameRaw);
        if (false === $issetProbe) {
            // Uninitialized / unset backing — empty without invoking get (#9683).
            $dst->bool(true);

            return true;
        }
        $hookValue = $this->fetchStaticPropertyWithHooks($classLc, $propNameRaw, $hooks['get'], $frame);
        $value = $hookValue->resolveIndirect();
        if ($value->isUndefined() || VM\TypedPropertyCheck::isUninitialized($value)) {
            $dst->bool(true);

            return true;
        }
        $dst->bool(!ext\standard\boolval::isTruthy($value));

        return true;
    }

    /**
     * True when empty($obj->prop) may read the declared slot (public/accessible), not overload (#23983).
     */
    private function isInstancePropertyReadableForEmpty(ObjectEntry $object, string $propName, Frame $frame): bool
    {
        if ($this->propertyReadUsesMagicGet($object, $propName, $frame)) {
            return false;
        }
        $meta = $this->classPropertyMeta($object, $propName, $frame);
        if (null === $meta) {
            // Dynamic property on the object — readable.
            return true;
        }
        if ($this->isParentPrivatePropertyInvisibleFromCaller($meta, $frame, $object)) {
            return false;
        }
        $readVis = PropertyVisibility::effectiveGetVisibility($meta->visibility, $meta->getVisibility);
        if (MethodVisibility::isPublic($readVis)) {
            return true;
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

            return true;
        } catch (\LogicException $e) {
            return false;
        }
    }

    /**
     * empty($obj->hooked) — zend_std_has_property(ZEND_PROPERTY_NOT_EMPTY) + zend_should_call_hook
     * (#30739, #29214, #16935). Uninitialized same-name backed is empty without get.
     */
    private function emptyHookedProperty(ObjectEntry $object, string $propName, Frame $frame, Variable $dst): bool
    {
        if (!$this->instancePropertyHasHooks($object, $propName)) {
            return false;
        }
        if (!$this->instancePropertyHasGetHook($object, $propName)) {
            $backing = $this->hookedPropertyBackingValue($object, $propName);
            if (false === $backing) {
                return false;
            }
            $uninit = $backing->isUndefined() || VM\TypedPropertyCheck::isUninitialized($backing);
            if ($uninit) {
                $dst->bool(true);

                return true;
            }
            $dst->bool(!ext\standard\boolval::isTruthy($backing));

            return true;
        }
        // Inside get/set for this prop: empty($this->prop) probes backing quietly (#29688).
        if ($this->isPropertyHookRawWrite($frame, $propName)) {
            $backing = $this->hookedPropertyBackingValue($object, $propName);
            if (false === $backing) {
                return false;
            }
            $uninit = $backing->isUndefined() || VM\TypedPropertyCheck::isUninitialized($backing);
            if ($uninit) {
                $dst->bool(true);

                return true;
            }
            $dst->bool(!ext\standard\boolval::isTruthy($backing));

            return true;
        }
        if ($this->skipHookedGetForUninitializedSameNameBacking($object, $propName)) {
            $dst->bool(true);

            return true;
        }
        $hookValue = $this->fetchPropertyWithHooks($object, $propName, $frame);
        if (null === $hookValue) {
            $backing = $this->hookedPropertyBackingValue($object, $propName);
            if (false === $backing) {
                return false;
            }
            $dst->bool(!ext\standard\boolval::isTruthy($backing));

            return true;
        }
        $value = $hookValue->resolveIndirect();
        $dst->bool(!ext\standard\boolval::isTruthy($value));

        return true;
    }

    private function invokeInstancePropertyUnsetHook(ObjectEntry $object, string $propName, Frame $frame): bool
    {
        $meta = $this->classPropertyMeta($object, $propName);
        $unsetLc = $meta?->unsetHookMethodLc
            ?? strtolower(SourcePreprocessor\PropertyHooks::unsetHookMethodName($propName));
        if (!isset($object->class->methods[$unsetLc])) {
            return false;
        }
        $func = $object->class->methods[$unsetLc];
        if (!$func instanceof Func\PHP) {
            return false;
        }
        $thisVar = new Variable();
        $thisVar->object($object);
        $this->invokePhpFunctionWithPropertyHookRaw($func, $propName, $frame, $thisVar);

        return true;
    }

    private function invokeStaticPropertyUnsetHook(
        string $classLc,
        string $propLc,
        string $propNameRaw,
        Frame $frame
    ): bool {
        if (!isset($this->context->classes[$classLc])) {
            return false;
        }
        $entry = $this->context->classes[$classLc];
        $hooks = $this->resolveStaticPropertyHooks($classLc, $propLc) ?? [];
        $unsetLc = $hooks['unset']
            ?? strtolower(SourcePreprocessor\PropertyHooks::unsetHookMethodName($propNameRaw));
        if (!isset($entry->methods[$unsetLc])) {
            return false;
        }
        $func = $entry->methods[$unsetLc];
        if (!$func instanceof Func\PHP) {
            return false;
        }
        $this->invokeStaticPropertyHookRaw($func, $propNameRaw, $classLc, $frame);

        return true;
    }

    /**
     * unset($obj->hooked) — reset hook backing + declared slot (Zend zend_property_hooks.c, #6471).
     */
    private function unsetHookedInstanceProperty(ObjectEntry $object, string $propName): void
    {
        $this->resetHookedPropertyBackingField($object, $propName);
        $this->unsetObjectProperty($object, $propName);
    }

    /**
     * unset($this->hooked) inside a property hook — backing storage only, no hook re-entry (#9625).
     */
    private function unsetHookedInstancePropertyRaw(ObjectEntry $object, string $propName): void
    {
        $lcClass = strtolower($object->class->name);
        $propMeta = $this->context->propertyHookRegistry[$lcClass][$propName]
            ?? $this->context->propertyHookRegistry[$lcClass][strtolower($propName)]
            ?? null;
        $backingName = $propName;
        if (is_array($propMeta)) {
            $backingName = $propMeta['setBacking'] ?? $propMeta['getBacking'] ?? $propName;
        }
        if ($object->hasProperty($backingName)) {
            // Always UNDEF after unset — distinguish from initialized null so isset/empty
            // can invoke get for `?T $backing = null` defaults (#23339 / re-#17260).
            $slot = $object->getProperty($backingName);
            $slot->reset();
            $slot->type = Variable::TYPE_UNDEFINED;
        }
        if (0 !== strcasecmp($backingName, $propName)) {
            $this->unsetObjectProperty($object, $propName);
        }
    }

    /** Clear registry-recorded get/set backing field after hooked-property unset (#6471, #5191, #11617, #23339). */
    private function resetHookedPropertyBackingField(ObjectEntry $object, string $propName): void
    {
        $lcClass = strtolower($object->class->name);
        $propMeta = $this->context->propertyHookRegistry[$lcClass][$propName]
            ?? $this->context->propertyHookRegistry[$lcClass][strtolower($propName)]
            ?? null;
        if (!is_array($propMeta)) {
            return;
        }
        $backingName = $propMeta['setBacking'] ?? $propMeta['getBacking'] ?? null;
        if (null === $backingName || !$object->hasProperty($backingName)) {
            return;
        }
        // UNDEF (not null) so nullable init-null still runs get on isset/empty (#23339).
        $slot = $object->getProperty($backingName);
        $slot->reset();
        $slot->type = Variable::TYPE_UNDEFINED;
    }

    /**
     * True when $slot is an indirect binding shared with another local (Zend ref chain).
     * Used by (unset) cast: only break references, not ordinary locals (#3517).
     *
     * @param array<int, Variable> $scope
     */
    private function slotIsReferenceBinding(Variable $slot, array $scope): bool
    {
        if (Variable::TYPE_INDIRECT !== $slot->type) {
            return false;
        }
        $target = $slot->resolveIndirect();
        foreach ($scope as $other) {
            if ($other === $slot) {
                continue;
            }
            if ($other === $target || $other->resolveIndirect() === $target) {
                return true;
            }
        }

        return false;
    }
}
