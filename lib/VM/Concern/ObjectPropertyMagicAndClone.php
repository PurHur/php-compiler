<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * Clone visibility / magic __get|__set / declared-property overload paths for the VM (#36403).
 *
 * Extracted from {@see \PHPCompiler\VM}: {@code enforceCloneVisibility} through
 * {@code fetchObjectPropertyWriteLvalue} (php-src zend_object_handlers clone +
 * __get/__set / inaccessible property notices). Concern trait — same namespace as parent so
 * relative Frame / OpCode / VM helpers resolve. Move-only; no new C ABI.
 */
trait ObjectPropertyMagicAndClone
{
    /**
     * Zend zend_check_clone: private/protected __clone() rejects external-scope clone (#5077).
     *
     * @return null when clone is allowed, or a catch frame when Error was dispatched
     */
    protected function enforceCloneVisibility(ObjectEntry $object, Frame $frame): ?Frame
    {
        if (!$this->hasInstanceMethod($object->class, '__clone')) {
            return null;
        }
        try {
            [$resolvedClass, $methodLc] = $this->resolveInstanceMethod($object->class, '__clone');
            $declLc = $resolvedClass->methodDeclaringClassLc[$methodLc] ?? strtolower($resolvedClass->name);
            $declaringClass = $this->context->classes[$declLc] ?? $resolvedClass;
            $vis = $declaringClass->methodVisibility[$methodLc]
                ?? $resolvedClass->methodVisibility[$methodLc]
                ?? \PHPCfg\Func::FLAG_PUBLIC;
            $callerClassLc = $this->callerClassLc($frame);
            $callerDisplay = $this->callerScopeDisplay($frame, $callerClassLc);
            MethodVisibility::assertCloneCallable(
                $vis,
                $callerClassLc,
                strtolower($declaringClass->name),
                $declaringClass->name,
                false,
                fn (string $classLc, string $ancestorLc): bool => $this->isClassSameOrSubclassOf($classLc, $ancestorLc),
                $callerDisplay
            );
        } catch (\LogicException $e) {
            return $this->dispatchVmError($e->getMessage(), $frame);
        }

        return null;
    }

    /**
     * Zend object construction: private/protected inherited __construct() rejects external scope (#5382).
     *
     * @return null when construction may proceed, or a catch frame when Error was dispatched
     */
    protected function enforceNewConstructorVisibility(ClassEntry $class, Frame $frame): ?Frame
    {
        if (null === $class->constructor && !$this->hasInstanceMethod($class, '__construct')) {
            return null;
        }
        // Internal ce handlers may keep $entry->constructor without advertising __construct
        // in the method table (php-src SplDoublyLinkedList / SplStack / SplQueue, #22789).
        if (!$this->hasInstanceMethod($class, '__construct')) {
            return null;
        }
        try {
            [$declaringClass, $methodLc] = $this->resolveInstanceMethod($class, '__construct');
            $vis = $declaringClass->methodVisibility[$methodLc] ?? \PHPCfg\Func::FLAG_PUBLIC;
            $callerClassLc = $this->callerClassLc($frame);
            $callerDisplay = $this->callerScopeDisplay($frame, $callerClassLc);
            MethodVisibility::assertConstructorCallable(
                $vis,
                $callerClassLc,
                strtolower($declaringClass->name),
                $declaringClass->name,
                false,
                fn (string $classLc, string $ancestorLc): bool => $this->isClassSameOrSubclassOf($classLc, $ancestorLc),
                $callerDisplay
            );
        } catch (\LogicException $e) {
            return $this->dispatchVmError($e->getMessage(), $frame);
        }

        return null;
    }

    /**
     * Zend object handler clone_obj — copy extension-owned storage after shallow property clone
     * (#19803 ArrayObject, #19805 SplObjectStorage). Walks parentLc for subclass handlers.
     */
    protected function invokeCloneObjectHandler(ObjectEntry $src, ObjectEntry $dest): void
    {
        $class = $src->class;
        while (null !== $class) {
            if (null !== $class->cloneObjectHandler) {
                ($class->cloneObjectHandler)($src, $dest);

                return;
            }
            $parentLc = $class->parentLc;
            if (null === $parentLc || !isset($this->context->classes[$parentLc])) {
                return;
            }
            $class = $this->context->classes[$parentLc];
        }
    }

    /**
     * Zend zend_std_clone_object: shallow copy then user __clone() when defined (#3170).
     *
     * Must run on an isolated run stack with parent frame linkage — nested runFrames() from
     * invokePhpFunctionOnStack would pop the clone opcode caller off the shared stack (#10165).
     */
    /**
     * @return null when __clone completed, or a catch frame when throw bubbled from isolated stack (#12068)
     */
    protected function invokeCloneMagicMethod(ObjectEntry $object, Frame $parentFrame): ?Frame
    {
        $class = $object->class;
        if (!isset($class->methods['__clone'])) {
            return null;
        }
        $func = $class->methods['__clone'];
        $thisVar = new Variable(Variable::TYPE_OBJECT);
        $thisVar->object($object);
        $savedStack = null !== $this->context->currentFiber
            ? null
            : $this->context->swapRunStack(null);
        $savedExternalCatch = $this->context->cloneMagicExternalCatchFrame;
        $savedCallerFrame = $this->context->cloneMagicCallerFrame;
        $this->context->cloneMagicExternalCatchFrame = null;
        $this->context->cloneMagicCallerFrame = $parentFrame;
        $this->context->invokingCloneMagic = true;
        VM\CloneWithSupport::beginCloneMagicReinit(
            $object,
            fn (ObjectEntry $owner, string $prop): ?string => $this->readonlyPropertyDeclaringClass($owner, $prop)
        );
        try {
            $child = $func->getFrame($this->context, $parentFrame);
            $child->calledArgs = [$thisVar];
            if (null !== $func->block->func && null !== $func->block->func->class
                && !(($func->block->func->flags ?? 0) & \PHPCfg\Func::FLAG_STATIC)) {
                $thisIdx = $func->block->slotIndexForVariableName('this');
                if (null !== $thisIdx) {
                    if (!isset($child->scope[$thisIdx])) {
                        $child->scope[$thisIdx] = new Variable();
                    }
                    $child->scope[$thisIdx]->copyFrom($thisVar);
                }
            }
            $out = new Variable();
            $child->returnVar = $out;
            $this->context->push($child);
            $result = $this->runFrames();
            if (null !== $this->context->cloneMagicExternalCatchFrame) {
                return $this->context->cloneMagicExternalCatchFrame;
            }
            if (self::FIBER_SUSPEND === $result) {
                throw new \LogicException('Fiber suspend during __clone() is not supported in this compiler build');
            }
            if (self::SUCCESS !== $result) {
                throw new \LogicException('__clone() invocation failed in this compiler build');
            }

            return null;
        } finally {
            VM\CloneWithSupport::endReinit($object);
            $this->context->invokingCloneMagic = false;
            $this->context->cloneMagicExternalCatchFrame = $savedExternalCatch;
            $this->context->cloneMagicCallerFrame = $savedCallerFrame;
            if (null !== $savedStack) {
                $this->context->swapRunStack($savedStack);
            }
        }
    }

    /**
     * Zend zend_std_read_property / __get slow path (#146).
     */
    protected function invokeMagicGet(ObjectEntry $object, string $name): Variable
    {
        if (!$this->hasInstanceMethod($object->class, '__get')) {
            throw new \LogicException('Undefined property access');
        }
        if (!$object->beginPropertyGuard($name, ObjectEntry::GUARD_IN_GET)) {
            // Already in __get for this prop — fall through to slot / undef path (zend guard).
            if ($object->hasProperty($name)) {
                return $object->getProperty($name);
            }
            $null = new Variable();
            $null->null();

            return $null;
        }
        try {
            $nameVar = new Variable(Variable::TYPE_STRING);
            $nameVar->string($name);

            return $this->invokeInstanceMethod($object, '__get', $nameVar);
        } finally {
            $object->endPropertyGuard($name, ObjectEntry::GUARD_IN_GET);
        }
    }

    /**
     * Zend zend_std_write_property / __set slow path (#146).
     */
    protected function invokeMagicSet(ObjectEntry $object, string $name, Variable $value): void
    {
        if (!$this->hasInstanceMethod($object->class, '__set')) {
            throw new \LogicException('Undefined property access');
        }
        if (!$object->beginPropertyGuard($name, ObjectEntry::GUARD_IN_SET)) {
            // Already in __set — assign directly to slot / allocate (zend IN_SET guard; #25810).
            $slot = $object->hasProperty($name)
                ? $object->getProperty($name)
                : $object->allocateProperty($name);
            $object->clearPropertyExplicitlyUnset($name);
            $slot->copyFrom($value);

            return;
        }
        try {
            $nameVar = new Variable(Variable::TYPE_STRING);
            $nameVar->string($name);
            $valueCopy = new Variable();
            $valueCopy->copyFrom($value);
            $this->invokeInstanceMethod($object, '__set', $nameVar, $valueCopy);
        } finally {
            $object->endPropertyGuard($name, ObjectEntry::GUARD_IN_SET);
        }
    }

    /**
     * True when zend_std_read_property must invoke __get (missing, inaccessible, or post-unset).
     * Existing dynamic slots are read from storage, not __get (#31949).
     * Scope-aware meta: in-frame private beats child shadow so __get does not recurse (#25795).
     * Post-unset declared slots use __get like Zend (#25810, zend_object_handlers.c).
     */
    protected function propertyReadUsesMagicGet(ObjectEntry $object, string $name, Frame $frame): bool
    {
        if (!$this->hasInstanceMethod($object->class, '__get')) {
            return false;
        }
        if ($object->isPropertyGuardActive($name, ObjectEntry::GUARD_IN_GET)) {
            return false;
        }
        $meta = $this->classPropertyMeta($object, $name, $frame);
        if (null === $meta) {
            if (!$object->hasProperty($name)) {
                return true;
            }
            // get_property_ptr_ptr may allocate a fresh dynamic slot before ++/-- (#32016).
            return $object->getProperty($name)->objectPropertyRwFresh;
        }
        if ($this->declaredPropertyInaccessibleFromCaller($object, $meta, $name, $frame, $meta->getVisibility)) {
            return true;
        }

        // unset($obj->prop) on a declared property → UNDEF; subsequent reads use __get (#25810).
        return $object->isPropertyExplicitlyUnset($name);
    }

    /**
     * True when zend_std_write_property must invoke __set (undeclared, inaccessible, or post-unset).
     * Shared by direct assign (#25686) and RMW ++/-- / assign-op (#25687).
     * Post-unset declared slots use __set like Zend (#25810); IN_SET guard prevents re-entry.
     */
    protected function propertyWriteUsesMagicSet(ObjectEntry $object, string $name, Frame $frame): bool
    {
        if (!$this->hasInstanceMethod($object->class, '__set')) {
            return false;
        }
        if ($object->isPropertyGuardActive($name, ObjectEntry::GUARD_IN_SET)) {
            return false;
        }
        $meta = $this->classPropertyMeta($object, $name, $frame);
        if (null === $meta) {
            return true;
        }

        // Symmetric visibility: inaccessible declared props route through __set (zend_object_handlers.c).
        // Asymmetric set visibility is handled separately via enforceAsymmetricPropertyWrite.
        if ($this->declaredPropertyInaccessibleFromCaller($object, $meta, $name, $frame, 0)) {
            return true;
        }

        // unset($obj->prop) → subsequent assigns use __set (#25810).
        return $object->isPropertyExplicitlyUnset($name);
    }

    /**
     * Declared private/protected prop not visible from the calling scope (zend_std_*_property).
     */
    private function declaredPropertyInaccessibleFromCaller(
        ObjectEntry $object,
        VM\ClassProperty $meta,
        string $name,
        Frame $frame,
        int $getOrSetVisibility
    ): bool {
        if ($this->isParentPrivatePropertyInvisibleFromCaller($meta, $frame, $object)) {
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
                $name,
                strtolower($object->class->name),
                fn (string $classLc, string $ancestorLc): bool => $this->isClassSameOrSubclassOf($classLc, $ancestorLc),
                $getOrSetVisibility
            );

            return false;
        } catch (\LogicException $e) {
            return true;
        }
    }

    /**
     * isset/empty must not read an inaccessible declared slot (zend_std_has_property; #25668).
     */
    private function declaredPropertyIssetUsesOverload(
        ObjectEntry $object,
        VM\ClassProperty $meta,
        string $name,
        Frame $frame
    ): bool {
        return $this->declaredPropertyInaccessibleFromCaller(
            $object,
            $meta,
            $name,
            $frame,
            $meta->getVisibility
        );
    }

    /**
     * Inaccessible declared unset — __unset, silent no-op (parent private from child), or Error (#25668).
     *
     * @return Frame|false|null Frame on catch, null when handled, false when caller should continue
     */
    private function dispatchInaccessibleDeclaredPropertyUnset(
        ObjectEntry $object,
        string $propName,
        Frame $frame
    ): Frame|false|null {
        $meta = $this->classPropertyMeta($object, $propName, $frame);
        if (null === $meta) {
            return false;
        }
        $invisibleParent = $this->isParentPrivatePropertyInvisibleFromCaller($meta, $frame, $object);
        $inaccessible = $invisibleParent
            || $this->declaredPropertyInaccessibleFromCaller($object, $meta, $propName, $frame, 0);
        if (!$inaccessible) {
            return false;
        }
        if ($this->hasInstanceMethod($object->class, '__unset')) {
            $key = new Variable();
            $key->string($propName);
            $this->invokeInstanceMethod($object, '__unset', $key);

            return null;
        }
        if ($invisibleParent) {
            // Parent private is not in child scope — unset is a no-op (zend_get_property_offset).
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

    /**
     * Copy __get return into $result and mark for indirect-modify detection (#4673).
     */
    protected function deliverMagicGetRead(Variable $result, ObjectEntry $object, string $name): void
    {
        $result->copyFrom($this->invokeMagicGet($object, $name));
        $result->magicGetOverloadedTarget = $object;
        $result->magicGetOverloadedName = $name;
    }

    /**
     * `$r = &$obj->inaccessible` — Zend get_property_ptr_ptr fails; read_property(BP_VAR_W)
     * invokes __get (zend_object_handlers.c, #25688).
     *
     * By-ref `__get` binds the returned lvalue; by-value `__get` yields a notice and a
     * temporary (Indirect modification of overloaded property … has no effect).
     */
    protected function deliverInaccessiblePropertyFetchByRef(
        Variable $result,
        ObjectEntry $object,
        string $name,
        Frame $frame
    ): void {
        if ($this->instanceMethodReturnsByRef($object, '__get')) {
            $result->indirect($this->invokeMagicGet($object, $name));

            return;
        }
        $this->deliverMagicGetRead($result, $object, $name);
        $scriptFile = '' !== $frame->scriptPath ? $frame->scriptPath : null;
        $this->context->errors->indirectModificationOfOverloadedProperty(
            $object->class->name,
            $name,
            $this->context,
            $frame,
            $scriptFile
        );
    }

    /**
     * Notice + continue for []= / dim-write on a non-object value from __get (#29231, re-#4673).
     *
     * php-src zend_object_handlers.c: arrays returned by value from __get cannot be
     * written back — Zend emits E_NOTICE ("Indirect modification … has no effect") and
     * continues (write hits the temporary only). Objects from __get — including
     * SimpleXMLElement / ArrayAccess — keep write_dimension on the live instance, so
     * $sxe->child["attr"] = … must reach offsetSet (#20005, sxe_prop_dim_write).
     *
     * Hooked-property Indirect modification Error paths are separate (#28590 / #29215).
     */
    protected function rejectMagicGetIndirectModify(Variable $containerSlot, bool $forWrite, Frame $frame): ?Frame
    {
        if (!$forWrite) {
            return null;
        }
        if (null === $containerSlot->magicGetOverloadedTarget || null === $containerSlot->magicGetOverloadedName) {
            return null;
        }
        $resolved = $containerSlot->resolveIndirect();
        if (Variable::TYPE_OBJECT === $resolved->type) {
            return null;
        }
        $class = $containerSlot->magicGetOverloadedTarget->class->name;
        $prop = $containerSlot->magicGetOverloadedName;
        $scriptFile = '' !== $frame->scriptPath ? $frame->scriptPath : null;
        $this->context->errors->indirectModificationOfOverloadedProperty(
            $class,
            $prop,
            $this->context,
            $frame,
            $scriptFile
        );

        return null;
    }

    /**
     * Resolve an instance property write lvalue, including __set / dynamic properties (#146).
     * Inaccessible declared props with __set use the magic proxy (zend_std_write_property; #25686/#25687).
     * $op distinguishes zend_std_write_property (plain assign) from get_property_ptr_ptr (#31949).
     */
    protected function fetchObjectPropertyWriteLvalue(ObjectEntry $object, string $name, Frame $frame, ?OpCode $op = null): Variable
    {
        if ($this->propertyWriteUsesMagicSet($object, $name, $frame)) {
            $proxy = new Variable();
            $proxy->magicSetTarget = $object;
            $proxy->magicSetName = $name;

            return $proxy;
        }
        $meta = $this->classPropertyMeta($object, $name, $frame);
        if (null !== $meta && $object->hasPropertyForMeta($meta)) {
            $object->clearPropertyExplicitlyUnset($name);

            return $object->getPropertyForMeta($meta);
        }
        if ($object->hasProperty($name)) {
            $object->clearPropertyExplicitlyUnset($name);

            return $object->getProperty($name);
        }
        if (VM\ObjectReadonlySupport::isDynamicReadonly($object)) {
            // Stamp user site before raise (same class as #25556 / #29457).
            [$file, $line] = VM\ExceptionSupport::userFatalSite($frame);
            $thrown = VM\BuiltinExceptionSupport::materializeError(
                $this->context,
                VM\ObjectReadonlySupport::modifyObjectMessage($object),
                $file,
                $line
            );
            $this->raiseUncaughtException($thrown);
        }
        if ($object->class->readonly && !$this->hasInstanceMethod($object->class, '__set')) {
            [$file, $line] = VM\ExceptionSupport::userFatalSite($frame);
            $thrown = VM\BuiltinExceptionSupport::materializeError(
                $this->context,
                sprintf('Cannot create dynamic property %s::$%s', $object->class->name, $name),
                $file,
                $line
            );
            $this->raiseUncaughtException($thrown);
        }
        if ($this->hasInstanceMethod($object->class, '__set')) {
            // IN_SET re-entry: allocate/assign directly (zend_get_property_guard; #25810).
            if ($object->isPropertyGuardActive($name, ObjectEntry::GUARD_IN_SET)) {
                return $object->allocateProperty($name);
            }
            $proxy = new Variable();
            $proxy->magicSetTarget = $object;
            $proxy->magicSetName = $name;

            return $proxy;
        }
        if (VM\SplArraySupport::hasArrayAsProps($object)) {
            $proxy = new Variable();
            $proxy->arrayAsPropsTarget = $object;
            $proxy->arrayAsPropsName = $name;

            return $proxy;
        }
        // get_property_ptr_ptr (+=, ++, &$obj->p) may bind a by-ref __get (#5309).
        // zend_std_write_property does not: no __set → deprecated dynamic property (#31949).
        if (
            $this->instanceMethodReturnsByRef($object, '__get')
            && (null === $op || !$this->propertyFetchDestUsedAsPlainAssign($frame, $op))
        ) {
            return $this->invokeMagicGet($object, $name);
        }
        // Defense in depth — primary gate is enforceInternalDynamicPropertyCreate (#26055, #26371).
        if ($object->class->noDynamicProperties) {
            // Stamp assignment site so uncaught Error does not cite ExceptionSupport (#29457).
            [$file, $line] = VM\ExceptionSupport::userFatalSite($frame);
            $thrown = VM\BuiltinExceptionSupport::materializeError(
                $this->context,
                sprintf('Cannot create dynamic property %s::$%s', $object->class->name, $name),
                $file,
                $line
            );
            $this->raiseUncaughtException($thrown);
        }
        // ++/-- with __get only: defer slot allocation so RMW reads via __get (#32016, zend_object_handlers.c).
        if (
            null !== $op
            && $this->propertyFetchDestUsedAsIncDec($frame, $op)
            && $this->hasInstanceMethod($object->class, '__get')
            && !$this->propertyWriteUsesMagicSet($object, $name, $frame)
        ) {
            $proxy = new Variable();
            $proxy->magicSetTarget = $object;
            $proxy->magicSetName = $name;

            return $proxy;
        }
        if (!$object->class->allowsDynamicProperties) {
            if (\PHPCompiler\CompilerVersion::supportsDynamicPropertyCreationDeprecation()) {
                $scriptPath = $frame->scriptPath;
                $this->context->errors->deprecatedDynamicProperty(
                    $object->class->name,
                    $name,
                    '' !== $scriptPath && '-' !== $scriptPath ? $scriptPath : null,
                    $this->context,
                    $frame
                );
            }
        }

        return $object->allocateProperty($name);
    }
}
