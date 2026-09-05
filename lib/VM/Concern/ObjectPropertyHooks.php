<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Func;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\ReflectionPropertyHookSupport;
use PHPCompiler\VM\TypeCheck;
use PHPCompiler\VM\Variable;

/**
 * Object / static property hook invoke + ref paths for the VM (#36403).
 *
 * Extracted from {@see \PHPCompiler\VM}: {@code resolveStaticPropertyHooks} through
 * {@code invokePhpFunctionWithPropertyHookRaw} (Zend property hooks / Reflection
 * setHook / Fiber-aware hook frames). Concern trait — same namespace as parent so
 * relative Frame / VM helpers resolve.
 */
trait ObjectPropertyHooks
{
    /**
     * @return array{get?: string, set?: string}|null
     */
    private function resolveStaticPropertyHooks(string $classLc, string $propLc): ?array
    {
        $currentLc = $classLc;
        while (isset($this->context->classes[$currentLc])) {
            $entry = $this->context->classes[$currentLc];
            if (isset($entry->staticPropertyHooks[$propLc])) {
                return $entry->staticPropertyHooks[$propLc];
            }
            if (isset($entry->staticProperties[$propLc])) {
                if (null === $entry->parentLc) {
                    return null;
                }
                $currentLc = $entry->parentLc;

                continue;
            }
            $currentLc = $entry->parentLc;
            if (null === $currentLc) {
                break;
            }
        }

        return null;
    }

    private function fetchStaticPropertyWithHooks(
        string $classLc,
        string $propName,
        string $getMethodLc,
        Frame $frame
    ): Variable {
        [$owner, $methodLc] = $this->resolveStaticMethod($classLc, $getMethodLc);
        $func = $owner->methods[$methodLc];
        if (!$func instanceof Func\PHP) {
            throw new \LogicException('Static property get hook must be a user method');
        }

        $result = $this->invokeStaticPropertyHookRaw($func, $propName, $classLc, $frame);
        $catchFrame = $this->enforcePropertyHookGetReturnForClass($classLc, $propName, null, $result, $frame);
        if (null !== $catchFrame) {
            throw new VM\PropertyHookRefWriteSignal($catchFrame);
        }

        return $result;
    }

    private function invokeStaticPropertyHookRaw(
        Func\PHP $func,
        string $rawProperty,
        string $classLc,
        Frame $parentFrame,
        Variable ...$args
    ): Variable {
        // Keep hook frames on the fiber run stack so Fiber::suspend() can resume (#9862).
        $savedStack = null !== $this->context->currentFiber
            ? null
            : $this->context->swapRunStack(null);
        $savedExternalCatch = $this->context->propertyHookExternalCatchFrame;
        $this->context->propertyHookExternalCatchFrame = null;
        $savedCallSiteLine = $parentFrame->callSiteLine;
        if ($parentFrame->callSiteLine <= 0) {
            $fromOp = VM\FatalSite::lineFromOpcodes($parentFrame);
            if ($fromOp > 0) {
                $parentFrame->callSiteLine = $fromOp;
            }
        }
        try {
            $this->emitPropertyHookDeprecationNotice($func, $rawProperty, $parentFrame);
            $child = $func->getFrame($this->context, $parentFrame);
            $child->propertyHookRawProperty = $rawProperty;
            $child->calledClass = $classLc;
            $child->calledArgs = $args;
            $out = new Variable();
            $child->returnVar = $out;
            $this->context->push($child);
            $result = $this->runFrames();
            if (null !== $this->context->propertyHookExternalCatchFrame) {
                throw new VM\PropertyHookRefWriteSignal($this->context->propertyHookExternalCatchFrame);
            }
            if (self::FIBER_SUSPEND === $result) {
                throw new VM\PropertyHookFiberSuspendSignal($parentFrame);
            }
            if (self::SUCCESS !== $result) {
                throw new \LogicException('Static property hook invocation failed in this compiler build');
            }

            return $out->resolveIndirect();
        } finally {
            $parentFrame->callSiteLine = $savedCallSiteLine;
            $this->context->propertyHookExternalCatchFrame = $savedExternalCatch;
            if (null !== $savedStack) {
                $this->context->swapRunStack($savedStack);
            }
        }
    }

    private function linkPropertyHooks(ClassEntry $entry, VM\ClassProperty $prop): void
    {
        $setLc = strtolower(SourcePreprocessor\PropertyHooks::setHookMethodName($prop->name));
        if (isset($entry->methods[$setLc])) {
            $prop->setHookMethodLc = $setLc;
        }
        $getLc = strtolower(SourcePreprocessor\PropertyHooks::getHookMethodName($prop->name));
        if (isset($entry->methods[$getLc])) {
            $prop->getHookMethodLc = $getLc;
        }
        $unsetLc = strtolower(SourcePreprocessor\PropertyHooks::unsetHookMethodName($prop->name));
        if (isset($entry->methods[$unsetLc])) {
            $prop->unsetHookMethodLc = $unsetLc;
        }
        $lcClass = strtolower($entry->name);
        $propMeta = $this->context->propertyHookRegistry[$lcClass][$prop->name]
            ?? $this->context->propertyHookRegistry[$lcClass][strtolower($prop->name)]
            ?? null;
        if (is_array($propMeta) && !empty($propMeta['virtual'])) {
            $prop->propertyHookVirtual = true;
        }
        if (is_array($propMeta) && !empty($propMeta['finalProperty'])) {
            $prop->propertyFinal = true;
        }
        if (is_array($propMeta) && !empty($propMeta['getParameterized'])) {
            $prop->getHookParameterized = true;
        }
        if (is_array($propMeta) && !empty($propMeta['getByRef'])) {
            $prop->getHookByRef = true;
        }
    }

    private function classPropertyMeta(ObjectEntry $object, string $propertyName, ?Frame $frame = null): ?VM\ClassProperty
    {
        $matches = [];
        foreach ($object->class->properties as $prop) {
            if ($prop->name === $propertyName) {
                $matches[] = $prop;
            }
        }
        if ([] === $matches) {
            // Ancestor-declared slots (e.g. phpInvisible DomRegistry id on DOMNode; #31439).
            return ext\standard\VmReflection::findClassPropertyExact(
                $object->class,
                $propertyName,
                $this->context
            );
        }
        if (1 === \count($matches)) {
            return $matches[0];
        }
        $callerLc = null !== $frame ? $this->callerClassLc($frame) : null;
        if (null !== $callerLc) {
            foreach ($matches as $prop) {
                if (
                    ($prop->visibility & \PHPCfg\Func::FLAG_PRIVATE) !== 0
                    && $prop->declaringClassLc === $callerLc
                ) {
                    return $prop;
                }
            }
        }
        foreach ($matches as $prop) {
            if (($prop->visibility & \PHPCfg\Func::FLAG_PRIVATE) === 0) {
                return $prop;
            }
        }

        // Most-derived private (child props are listed before inherited parent privates).
        return $matches[0];
    }

    private function enforcePropertyHookGetReturn(
        ObjectEntry $object,
        string $propName,
        ?VM\ClassProperty $meta,
        Variable $value,
        Frame $frame
    ): ?Frame {
        $meta ??= $this->classPropertyMeta($object, $propName);
        if (null === $meta) {
            return null;
        }
        $strict = null !== $frame->parent
            ? $frame->parent->block->strictTypes
            : $frame->block->strictTypes;
        try {
            TypeCheck::assertPropertyHookGetReturn($value, $meta->prototype, $strict, $this->context);
        } catch (\TypeError $e) {
            return $this->dispatchVmTypeError($e, $frame);
        }

        return null;
    }

    private function enforcePropertyHookGetReturnForClass(
        string $classLc,
        string $propName,
        ?Variable $typePrototype,
        Variable $value,
        Frame $frame
    ): ?Frame {
        $typePrototype ??= $this->staticPropertyTypePrototype($classLc, $propName);
        if (null === $typePrototype) {
            return null;
        }
        $strict = null !== $frame->parent
            ? $frame->parent->block->strictTypes
            : $frame->block->strictTypes;
        try {
            TypeCheck::assertPropertyHookGetReturn($value, $typePrototype, $strict, $this->context);
        } catch (\TypeError $e) {
            return $this->dispatchVmTypeError($e, $frame);
        }

        return null;
    }

    private function staticPropertyTypePrototype(string $classLc, string $propName): ?Variable
    {
        if (!isset($this->context->classes[$classLc])) {
            return null;
        }
        $propLc = strtolower($propName);

        return $this->context->classes[$classLc]->staticProperties[$propLc] ?? null;
    }

    /**
     * Read a hooked property through a reference binding (#6426).
     */
    public function readPropertyHookRef(Variable $writeLvalue): Variable
    {
        $frame = $this->requireExecutingFrame();
        $owner = $this->resolvePropertyWriteOwner($writeLvalue);
        $propName = $this->resolvePropertyWriteName($writeLvalue);
        if (null !== $owner && null !== $propName) {
            $catchFrame = $this->enforceWriteOnlyVirtualPropertyRead($owner, $propName, $frame);
            if (null !== $catchFrame) {
                throw new VM\PropertyHookRefWriteSignal($catchFrame);
            }
            $hookValue = $this->fetchPropertyWithHooks($owner, $propName, $frame);
            if (null !== $hookValue) {
                return $hookValue;
            }
        }
        $target = $writeLvalue->resolveIndirect();
        $classLc = $target->staticPropertyClassLc;
        $staticPropName = $target->objectPropertyName;
        if (is_string($classLc) && is_string($staticPropName) && '' !== $staticPropName) {
            $hooks = $this->resolveStaticPropertyHooks($classLc, strtolower($staticPropName));
            $getLc = $hooks['get'] ?? null;
            if (null !== $getLc) {
                return $this->fetchStaticPropertyWithHooks($classLc, $staticPropName, $getLc, $frame);
            }
        }
        $out = new Variable();
        $out->copyFrom($target);

        return $out;
    }

    /**
     * Write a hooked property through a reference binding (#6426).
     */
    public function writePropertyHookRef(Variable $writeLvalue, Variable $value): void
    {
        $frame = $this->requireExecutingFrame();
        $catchFrame = $this->enforceAsymmetricPropertyWrite($writeLvalue, $frame);
        if (null !== $catchFrame) {
            throw new VM\PropertyHookRefWriteSignal($catchFrame);
        }
        if ($this->dispatchPropertySetHookAssign($writeLvalue, $value, $frame)) {
            return;
        }
        if ($this->context->propertyHookSetAborted) {
            $this->context->propertyHookSetAborted = false;

            return;
        }
        $catchFrame = $this->enforceVirtualPropertyHookWrite($writeLvalue, $frame);
        if (null !== $catchFrame) {
            throw new VM\PropertyHookRefWriteSignal($catchFrame);
        }
        $writeLvalue->resolveIndirect()->copyFrom($value);
    }

    private function requireExecutingFrame(): Frame
    {
        if (null === $this->executingFrame) {
            throw new \LogicException('No active frame for property hook reference');
        }

        return $this->executingFrame;
    }

    /** Active user opcode frame during runFrames (not on runStack — see #14132). */
    public function currentExecutingFrame(): ?Frame
    {
        return $this->executingFrame;
    }

    /**
     * Mark an ASSIGN_REF alias so TypeErrors use "reference held by property" (#25622).
     */
    private function markTypedPropertyByRefAlias(Variable $alias, Variable $storage): void
    {
        $resolved = $storage->resolveIndirect();
        if (
            (null !== $resolved->objectPropertyOwner && null !== $resolved->objectPropertyName)
            || (null !== $resolved->staticPropertyClassLc && null !== $resolved->objectPropertyName)
        ) {
            $alias->typedPropertyByRef = true;
        }
    }

    /**
     * Builtin serialize/unserialize invoke property hooks with a user PHP frame parent (#6474).
     */
    private function resolvePropertyHookParentFrame(?Frame $frame): Frame
    {
        $cursor = $frame ?? $this->executingFrame;
        while (null !== $cursor) {
            if (null !== $cursor->handler && null !== $cursor->block) {
                return $cursor;
            }
            $cursor = $cursor->parent;
        }

        return $this->requireExecutingFrame();
    }

    /**
     * Property lvalue for assign-by-ref when rhs is a hooked property (#6426, #22475).
     */
    private function resolvePropertyHookRefWriteLvalue(Variable $operand, Frame $frame): ?Variable
    {
        $propName = $this->resolvePropertyWriteName($operand);
        if (null === $propName || $this->isPropertyHookRawWrite($frame, $propName)) {
            return null;
        }
        $owner = $this->resolvePropertyWriteOwner($operand);
        if (null !== $owner) {
            $meta = $this->classPropertyMeta($owner, $propName);
            if (
                null === $meta
                || (
                    !$meta->propertyHookVirtual
                    && null === $meta->setHookMethodLc
                    && null === $meta->getHookMethodLc
                )
            ) {
                return null;
            }

            return $operand;
        }
        $target = $operand->resolveIndirect();
        $classLc = $operand->staticPropertyClassLc ?? $target->staticPropertyClassLc;
        $staticPropName = $operand->objectPropertyName ?? $target->objectPropertyName;
        if (is_string($classLc) && is_string($staticPropName) && '' !== $staticPropName) {
            if (null !== $this->resolveStaticPropertyHooks($classLc, strtolower($staticPropName))) {
                return $operand;
            }
        }

        return null;
    }

    /**
     * True when the hooked property declares `&get` (ZEND_ACC_RETURN_REFERENCE, #21098 / #22475).
     */
    private function propertyHookGetIsByRef(Variable $lvalue): bool
    {
        $propName = $this->resolvePropertyWriteName($lvalue);
        if (null === $propName) {
            return false;
        }
        $owner = $this->resolvePropertyWriteOwner($lvalue);
        if (null !== $owner) {
            $meta = $this->classPropertyMeta($owner, $propName);

            return (bool) ($meta?->getHookByRef);
        }
        $target = $lvalue->resolveIndirect();
        $classLc = $lvalue->staticPropertyClassLc ?? $target->staticPropertyClassLc;
        $staticPropName = $lvalue->objectPropertyName ?? $target->objectPropertyName;
        if (is_string($classLc) && is_string($staticPropName) && '' !== $staticPropName) {
            $propMeta = $this->context->propertyHookRegistry[$classLc][$staticPropName]
                ?? $this->context->propertyHookRegistry[$classLc][strtolower($staticPropName)]
                ?? null;

            return is_array($propMeta) && !empty($propMeta['getByRef']);
        }

        return false;
    }

    /**
     * php-src zend_object_handlers.c — assign-ref / get_ptr without `&get` (#22475).
     */
    private function indirectModificationOfHookedPropertyMessage(Variable $lvalue): string
    {
        $propName = $this->resolvePropertyWriteName($lvalue) ?? '?';
        $owner = $this->resolvePropertyWriteOwner($lvalue);
        if (null !== $owner) {
            return sprintf('Indirect modification of %s::$%s is not allowed', $owner->class->name, $propName);
        }
        $target = $lvalue->resolveIndirect();
        $classLc = $lvalue->staticPropertyClassLc ?? $target->staticPropertyClassLc;
        if (is_string($classLc) && isset($this->context->classes[$classLc])) {
            return sprintf(
                'Indirect modification of %s::$%s is not allowed',
                $this->context->classes[$classLc]->name,
                $propName
            );
        }

        return sprintf('Indirect modification of $%s is not allowed', $propName);
    }

    /**
     * `$r = &$obj->hooked` without `&get` — php-src read_property(BP_VAR_W) (#29719).
     *
     * Invoke get when present (side effects / throw propagation). When get returns an
     * object, Zend allows the temporary (no Indirect modification). Otherwise Error.
     *
     * @return ?Frame catch frame when get or Indirect modification throws
     */
    private function assignRefFromHookedPropertyWithoutByRefGet(
        Variable $writeTarget,
        Variable $hookLvalue,
        Frame $frame,
    ): ?Frame {
        $owner = $this->resolvePropertyWriteOwner($hookLvalue);
        $propName = $this->resolvePropertyWriteName($hookLvalue);
        if (null !== $owner && null !== $propName) {
            $meta = $this->classPropertyMeta($owner, $propName);
            $hasGet = null !== $meta && null !== $meta->getHookMethodLc;
            if (!$hasGet) {
                $hasGet = null !== ReflectionPropertyHookSupport::runtimeHookClosure(
                    $this->context,
                    $owner->class,
                    $propName,
                    'get'
                );
            }
            if ($hasGet) {
                try {
                    $hookValue = $this->fetchPropertyWithHooks($owner, $propName, $frame);
                } catch (VM\PropertyHookRefWriteSignal $signal) {
                    return $signal->catchFrame;
                }
                if (null !== $hookValue) {
                    $resolved = $hookValue->resolveIndirect();
                    if (Variable::TYPE_OBJECT === $resolved->type) {
                        // Temporary from get — not a live property cell (#29719 / zend_object_handlers.c).
                        $cell = new Variable();
                        $cell->copyFrom($resolved);
                        $this->bindAssignRefSharedCell($writeTarget, $cell);

                        return null;
                    }
                }
            }
        } else {
            $target = $hookLvalue->resolveIndirect();
            $classLc = $hookLvalue->staticPropertyClassLc ?? $target->staticPropertyClassLc;
            $staticPropName = $hookLvalue->objectPropertyName ?? $target->objectPropertyName;
            if (is_string($classLc) && is_string($staticPropName) && '' !== $staticPropName) {
                $hooks = $this->resolveStaticPropertyHooks($classLc, strtolower($staticPropName));
                if (null !== $hooks && isset($hooks['get'])) {
                    try {
                        $hookValue = $this->fetchStaticPropertyWithHooks(
                            $classLc,
                            $staticPropName,
                            $hooks['get'],
                            $frame
                        );
                    } catch (VM\PropertyHookRefWriteSignal $signal) {
                        return $signal->catchFrame;
                    }
                    if (null !== $hookValue) {
                        $resolved = $hookValue->resolveIndirect();
                        if (Variable::TYPE_OBJECT === $resolved->type) {
                            $cell = new Variable();
                            $cell->copyFrom($resolved);
                            $this->bindAssignRefSharedCell($writeTarget, $cell);

                            return null;
                        }
                    }
                }
            }
        }

        return $this->dispatchVmError(
            $this->indirectModificationOfHookedPropertyMessage($hookLvalue),
            $frame
        );
    }

    /**
     * Bind `$r = &$obj->prop` when `&get` is declared (zend_property_hooks.c, #22475).
     *
     * Prefer recorded getBacking (same as dim writes, #21098) so `$r = &$obj->hooked; $r = $v`
     * mutates the private arrow-target — fetchPropertyWithHooksByRef alone can return a value
     * copy when return-by-ref of object props is not yet a live alias (#26368). When a set hook
     * is also present, PropertyHookRef write-through stays valid for private backing cells.
     *
     * @return ?Frame catch frame when hook throws
     */
    private function bindAssignRefToByRefGetHook(Variable $writeTarget, Variable $hookLvalue, Frame $frame): ?Frame
    {
        $owner = $this->resolvePropertyWriteOwner($hookLvalue);
        $propName = $this->resolvePropertyWriteName($hookLvalue);
        if (null !== $owner && null !== $propName) {
            $meta = $this->classPropertyMeta($owner, $propName);
            // `&get`+`set` (virtual): Prefer PropertyHookRef so writes stay in-hook scope (#22475).
            if (null !== $meta && null !== $meta->setHookMethodLc) {
                $stableLvalue = $this->stablePropertyHookRefWriteLvalue($hookLvalue);
                $hookRefVar = new Variable();
                $hookRefVar->propertyHookRef(new VM\PropertyHookRef($this, $stableLvalue));
                $writeTarget->indirect($hookRefVar);

                return null;
            }
            // `&get`-only with known backing: alias the storage cell directly (#26368).
            $backing = $this->resolveByRefGetHookBackingStorage($owner, $propName);
            if (null !== $backing) {
                $this->bindAssignRefSharedCell($writeTarget, $backing);

                return null;
            }
            try {
                $byRef = $this->fetchPropertyWithHooksByRef($owner, $propName, $frame);
            } catch (VM\PropertyHookRefWriteSignal $signal) {
                return $signal->catchFrame;
            }
            if (null === $byRef) {
                return $this->dispatchVmError(
                    $this->indirectModificationOfHookedPropertyMessage($hookLvalue),
                    $frame
                );
            }
            $cell = $byRef->isIndirect()
                ? ($byRef->directIndirectTarget() ?? $byRef->resolveIndirect())
                : $byRef;
            $this->bindAssignRefSharedCell($writeTarget, $cell);

            return null;
        }
        if ($this->propertyWriteHasSetHook($hookLvalue)) {
            $stableLvalue = $this->stablePropertyHookRefWriteLvalue($hookLvalue);
            $hookRefVar = new Variable();
            $hookRefVar->propertyHookRef(new VM\PropertyHookRef($this, $stableLvalue));
            $writeTarget->indirect($hookRefVar);

            return null;
        }

        return $this->dispatchVmError(
            $this->indirectModificationOfHookedPropertyMessage($hookLvalue),
            $frame
        );
    }

    /**
     * Object property cell named by registry getBacking for an `&get` arrow/block (#21098 / #26368).
     */
    private function resolveByRefGetHookBackingStorage(ObjectEntry $owner, string $propName): ?Variable
    {
        $lcClass = strtolower($owner->class->name);
        $propMeta = $this->context->propertyHookRegistry[$lcClass][$propName]
            ?? $this->context->propertyHookRegistry[$lcClass][strtolower($propName)]
            ?? null;
        $backingName = is_array($propMeta) ? ($propMeta['getBacking'] ?? null) : null;
        if (!is_string($backingName) || '' === $backingName || !$owner->hasProperty($backingName)) {
            return null;
        }

        return $owner->getProperty($backingName);
    }

    /**
     * Promote storage to a shared IS_REFERENCE-style cell and bind the assign-ref LHS (#22475).
     */
    private function bindAssignRefSharedCell(Variable $writeTarget, Variable $cell): void
    {
        if (Variable::TYPE_INDIRECT !== $cell->type) {
            $shared = new Variable();
            $shared->copyFrom($cell);
            $cell->indirect($shared);
        }
        $writeTarget->indirect($cell->resolveIndirect());
    }

    /** Live property storage cell for hooked ref bindings (#6426). */
    private function stablePropertyHookRefWriteLvalue(Variable $operand): Variable
    {
        $owner = $this->resolvePropertyWriteOwner($operand);
        $propName = $this->resolvePropertyWriteName($operand);
        if (null !== $owner && null !== $propName && $owner->hasProperty($propName)) {
            return $owner->getProperty($propName);
        }
        $target = $operand->resolveIndirect();
        $classLc = $target->staticPropertyClassLc;
        $staticPropName = $target->objectPropertyName;
        if (is_string($classLc) && is_string($staticPropName) && isset($this->context->classes[$classLc])) {
            return $this->context->classes[$classLc]->staticProperties[strtolower($staticPropName)];
        }

        return $operand;
    }

    /**
     * foreach ($iterable as &$obj->hooked) — write iteration scalar to hook backing without set hook (#6435).
     */
    private function writeHookedPropertyForeachIterationValue(
        Variable $writeLvalue,
        Variable $value,
        Frame $frame,
    ): void {
        $owner = $this->resolvePropertyWriteOwner($writeLvalue);
        $propName = $this->resolvePropertyWriteName($writeLvalue);
        if (null !== $owner && null !== $propName) {
            $lcClass = strtolower($owner->class->name);
            $propMeta = $this->context->propertyHookRegistry[$lcClass][$propName]
                ?? $this->context->propertyHookRegistry[$lcClass][strtolower($propName)]
                ?? null;
            $backingName = is_array($propMeta)
                ? ($propMeta['getBacking'] ?? $propMeta['setBacking'] ?? null)
                : null;
            if (null !== $backingName && $owner->hasProperty($backingName)) {
                $owner->getProperty($backingName)->copyFrom($value->resolveIndirect());

                return;
            }
            if ($owner->hasProperty($propName)) {
                $owner->getProperty($propName)->copyFrom($value->resolveIndirect());

                return;
            }
        } else {
            $target = $writeLvalue->resolveIndirect();
            $classLc = $writeLvalue->staticPropertyClassLc ?? $target->staticPropertyClassLc;
            $staticPropName = $writeLvalue->objectPropertyName ?? $target->objectPropertyName;
            if (is_string($classLc) && is_string($staticPropName) && '' !== $staticPropName) {
                $propLc = strtolower($staticPropName);
                $propMeta = $this->context->propertyHookRegistry[$classLc][$staticPropName]
                    ?? $this->context->propertyHookRegistry[$classLc][$propLc]
                    ?? null;
                $backingName = is_array($propMeta)
                    ? ($propMeta['getBacking'] ?? $propMeta['setBacking'] ?? null)
                    : null;
                if (null !== $backingName) {
                    $backingStorage = $this->resolveStaticPropertyStorage($classLc, strtolower($backingName));
                    if (null !== $backingStorage) {
                        $backingStorage->copyFrom($value->resolveIndirect());

                        return;
                    }
                }
            }
        }
        $this->stablePropertyHookRefWriteLvalue($writeLvalue)->copyFrom($value->resolveIndirect());
    }

    private function propertyWriteHasSetHook(Variable $lvalue): bool
    {
        $propName = $this->resolvePropertyWriteName($lvalue);
        if (null === $propName) {
            return false;
        }
        $target = $lvalue->resolveIndirect();
        $classLc = $target->staticPropertyClassLc;
        $staticPropName = $target->objectPropertyName;
        if (is_string($classLc) && is_string($staticPropName) && '' !== $staticPropName) {
            $hooks = $this->resolveStaticPropertyHooks($classLc, strtolower($staticPropName));

            return null !== $hooks && !empty($hooks['set']);
        }
        $owner = $this->resolvePropertyWriteOwner($lvalue);
        if (null === $owner) {
            return false;
        }
        $meta = $this->classPropertyMeta($owner, $propName);
        if (null !== ReflectionPropertyHookSupport::runtimeHookClosure(
            $this->context,
            $owner->class,
            $propName,
            'set'
        )) {
            return true;
        }
        $setLc = $meta?->setHookMethodLc
            ?? strtolower(SourcePreprocessor\PropertyHooks::setHookMethodName($propName));

        return isset($owner->class->methods[$setLc]);
    }

    /**
     * Assignment expression result after set hook — hook owns backing storage (#7251, zend_property_hooks.c).
     */
    private function deliverPropertySetHookAssignResult(Variable $dest, Variable $rhs): void
    {
        if ($dest->isIndirect()) {
            $dest->reset();
        }
        $dest->duplicateFrom($rhs);
    }

    /**
     * Invoke set hook instead of direct assign when applicable (#3145).
     */
    private function dispatchPropertySetHookAssign(Variable $lvalue, Variable $value, Frame $frame): bool
    {
        // array_walk object HT writeback mutates backing without set (#29703).
        if ($this->lvalueSkipsPropertySetHook($lvalue)) {
            return false;
        }
        $target = $lvalue->resolveIndirect();
        $classLc = $lvalue->staticPropertyClassLc ?? $target->staticPropertyClassLc;
        $staticPropName = $lvalue->objectPropertyName ?? $target->objectPropertyName;
        if (
            is_string($classLc)
            && is_string($staticPropName)
            && !$this->isPropertyHookRawWrite($frame, $staticPropName)
        ) {
            if (!isset($this->context->classes[$classLc])) {
                return false;
            }
            $entry = $this->context->classes[$classLc];
            $propLc = strtolower($staticPropName);
            $hooks = $this->resolveStaticPropertyHooks($classLc, $propLc);
            $setLc = $hooks['set'] ?? null;
            if (null === $setLc || !isset($entry->methods[$setLc])) {
                return false;
            }
            $func = $entry->methods[$setLc];
            if (!$func instanceof Func\PHP) {
                return false;
            }
            $this->context->propertyHookSetAborted = false;
            $this->invokeStaticPropertyHookRaw($func, $staticPropName, $classLc, $frame, $value->resolveIndirect());
            if ($this->context->propertyHookSetAborted) {
                return false;
            }

            return true;
        }
        $owner = $this->resolvePropertyWriteOwner($lvalue);
        $propName = $this->resolvePropertyWriteName($lvalue);
        if (null === $owner || null === $propName || $this->isPropertyHookRawWrite($frame, $propName)) {
            return false;
        }
        $meta = $this->classPropertyMeta($owner, $propName);
        $runtimeSet = ReflectionPropertyHookSupport::runtimeHookClosure(
            $this->context,
            $owner->class,
            $propName,
            'set'
        );
        if (null !== $runtimeSet) {
            $this->context->propertyHookSetAborted = false;
            $thisVar = new Variable();
            $thisVar->object($owner);
            $this->invokeReflectionRuntimePropertyHook($runtimeSet, $thisVar, $value->resolveIndirect(), $frame);
            if ($this->context->propertyHookSetAborted) {
                return false;
            }

            return true;
        }
        $setLc = $meta?->setHookMethodLc
            ?? strtolower(SourcePreprocessor\PropertyHooks::setHookMethodName($propName));
        if (!isset($owner->class->methods[$setLc])) {
            return false;
        }
        $func = $owner->class->methods[$setLc];
        if (!$func instanceof Func\PHP) {
            return false;
        }
        $this->context->propertyHookSetAborted = false;
        $thisVar = new Variable();
        $thisVar->object($owner);
        $this->invokePhpFunctionWithPropertyHookRaw($func, $propName, $frame, $thisVar, $value->resolveIndirect());
        if ($this->context->propertyHookSetAborted) {
            return false;
        }

        return true;
    }

    /**
     * Invoke a ReflectionProperty::setHook() closure with $this bound (#22116).
     */
    private function invokeReflectionRuntimePropertyHook(
        VM\ClosureState $state,
        Variable $thisVar,
        ?Variable $setValue,
        Frame $frame
    ): Variable {
        $prevBound = $state->boundThis;
        $state->boundThis = $thisVar;
        try {
            if (null !== $setValue) {
                return $this->invokeClosure($state, $setValue);
            }

            return $this->invokeClosure($state);
        } finally {
            $state->boundThis = $prevBound;
        }
    }

    /**
     * Object foreach / array_walk value read (#9470, #29703, zend_property_hooks.c).
     *
     * By-value foreach invokes get hooks like get_object_vars(). array_walk by-ref aliases
     * hook backing storage so writeback skips set (php_array_walk HT update).
     */
    public function readObjectForeachProperty(
        ObjectEntry $object,
        string $name,
        Frame $frame,
        bool $byRef,
        bool $arrayWalkRawBacking = false,
    ): Variable {
        $meta = $this->classPropertyMeta($object, $name);
        if (!$byRef && null !== $meta?->getHookMethodLc) {
            $hookValue = $this->fetchPropertyWithHooks($object, $name, $frame);
            if (null !== $hookValue) {
                $copy = new Variable();
                $copy->copyFrom($hookValue->resolveIndirect());

                return $copy;
            }
        }
        if ($byRef && $arrayWalkRawBacking) {
            $alias = $this->arrayWalkByRefHookBackingAlias($object, $name, $meta);
            if (null !== $alias) {
                return $alias;
            }
        }
        $prop = $object->getProperty($name);
        if ($byRef) {
            return $prop;
        }
        $copy = new Variable();
        $copy->copyFrom($prop->resolveIndirect());

        return $copy;
    }

    /**
     * array_walk by-ref into a hooked property — alias backing without set-hook dispatch (#29703).
     */
    private function arrayWalkByRefHookBackingAlias(
        ObjectEntry $object,
        string $name,
        ?VM\ClassProperty $meta,
    ): ?Variable {
        if (null === $meta || null === $meta->setHookMethodLc) {
            return null;
        }
        $backing = $this->resolveArrayWalkHookBackingStorage($object, $name);
        if (null === $backing) {
            return null;
        }
        if (Variable::TYPE_INDIRECT !== $backing->type) {
            $shared = new Variable();
            $shared->copyFrom($backing);
            $this->copyPropertyTypeMetadataOntoCell($shared, $backing);
            $shared->objectPropertyOwner = $object;
            $shared->objectPropertyName = $name;
            $backing->indirect($shared);
        } else {
            $shared = $backing->resolveIndirect();
            $this->copyPropertyTypeMetadataOntoCell($shared, $backing);
            if (null === $shared->objectPropertyOwner) {
                $shared->objectPropertyOwner = $object;
                $shared->objectPropertyName = $name;
            }
        }
        $alias = new Variable();
        $alias->indirect($shared);
        $alias->skipPropertySetHook = true;

        return $alias;
    }

    /**
     * Backing cell for array_walk object HT writeback (setBacking / getBacking / declared slot).
     */
    private function resolveArrayWalkHookBackingStorage(ObjectEntry $object, string $name): ?Variable
    {
        $lcClass = strtolower($object->class->name);
        $propMeta = $this->context->propertyHookRegistry[$lcClass][$name]
            ?? $this->context->propertyHookRegistry[$lcClass][strtolower($name)]
            ?? null;
        $backingName = is_array($propMeta)
            ? ($propMeta['setBacking'] ?? $propMeta['getBacking'] ?? null)
            : null;
        if (is_string($backingName) && '' !== $backingName && $object->hasProperty($backingName)) {
            return $object->getProperty($backingName);
        }
        if ($object->hasProperty($name)) {
            return $object->getProperty($name);
        }

        return null;
    }

    /** Move typed-property metadata onto the shared value cell for HT-style by-ref writes. */
    private function copyPropertyTypeMetadataOntoCell(Variable $cell, Variable $typeMeta): void
    {
        if (null === $cell->typeConstraint && null !== $typeMeta->typeConstraint) {
            $cell->typeConstraint = $typeMeta->typeConstraint;
        }
        if (null === $cell->classConstraint && null !== $typeMeta->classConstraint) {
            $cell->classConstraint = $typeMeta->classConstraint;
        }
        if (null === $cell->literalBoolType && null !== $typeMeta->literalBoolType) {
            $cell->literalBoolType = $typeMeta->literalBoolType;
        }
        if (null === $cell->unionTypeConstraints && null !== $typeMeta->unionTypeConstraints) {
            $cell->unionTypeConstraints = $typeMeta->unionTypeConstraints;
        }
        if (null === $cell->declaredTypeLabel && null !== $typeMeta->declaredTypeLabel) {
            $cell->declaredTypeLabel = $typeMeta->declaredTypeLabel;
        }
        if (null === $cell->genericArrayTypeSpec && null !== $typeMeta->genericArrayTypeSpec) {
            $cell->genericArrayTypeSpec = $typeMeta->genericArrayTypeSpec;
        }
        if (null === $cell->dnfArms && null !== $typeMeta->dnfArms) {
            $cell->dnfArms = $typeMeta->dnfArms;
        }
    }

    /** True when an assign lvalue is an array_walk HT alias that must skip set hooks (#29703). */
    private function lvalueSkipsPropertySetHook(Variable $lvalue): bool
    {
        $var = $lvalue;
        $seen = [];
        while (true) {
            $id = \spl_object_id($var);
            if (isset($seen[$id])) {
                return false;
            }
            $seen[$id] = true;
            if ($var->skipPropertySetHook) {
                return true;
            }
            if (!$var->isIndirect()) {
                return false;
            }
            $next = $var->directIndirectTarget();
            if (null === $next) {
                return false;
            }
            $var = $next;
        }
    }

    private function fetchPropertyWithHooks(ObjectEntry $object, string $name, Frame $frame): ?Variable
    {
        $fiber = $this->context->currentFiber;
        if (null !== $fiber?->propertyHookResumeRead) {
            $result = new Variable();
            $result->copyFrom($fiber->propertyHookResumeRead->resolveIndirect());
            $fiber->propertyHookResumeRead = null;
            $meta = $this->classPropertyMeta($object, $name);
            $catchFrame = $this->enforcePropertyHookGetReturn($object, $name, $meta, $result, $frame);
            if (null !== $catchFrame) {
                throw new VM\PropertyHookRefWriteSignal($catchFrame);
            }

            return $result;
        }
        if ($this->isPropertyHookRawWrite($frame, $name)) {
            $catchFrame = $this->enforceVirtualPropertyHookRawAccess($object, $name, true, $frame);
            if (null !== $this->context->propertyHookExternalCatchFrame) {
                throw new VM\PropertyHookRefWriteSignal($this->context->propertyHookExternalCatchFrame);
            }
            if (null !== $catchFrame) {
                throw new VM\PropertyHookRefWriteSignal($catchFrame);
            }

            return null;
        }
        $meta = $this->classPropertyMeta($object, $name);
        if (
            null !== $meta
            && $meta->lazy
            && null !== $meta->getHookMethodLc
            && isset($object->lazyRawInitializedProperties[$name])
        ) {
            $cached = new Variable();
            $cached->copyFrom($object->getProperty($name)->resolveIndirect());

            return $cached;
        }
        $runtimeGet = ReflectionPropertyHookSupport::runtimeHookClosure(
            $this->context,
            $object->class,
            $name,
            'get'
        );
        if (null !== $runtimeGet) {
            $thisVar = new Variable();
            $thisVar->object($object);
            $result = $this->invokeReflectionRuntimePropertyHook($runtimeGet, $thisVar, null, $frame);
            $catchFrame = $this->enforcePropertyHookGetReturn($object, $name, $meta, $result, $frame);
            if (null !== $catchFrame) {
                throw new VM\PropertyHookRefWriteSignal($catchFrame);
            }
            if (null !== $meta) {
                VM\LazyPropertySupport::cacheLazyGetHookResult($object, $name, $meta, $result);
            }

            return $result;
        }
        $getLc = $meta?->getHookMethodLc
            ?? strtolower(SourcePreprocessor\PropertyHooks::getHookMethodName($name));
        if (!isset($object->class->methods[$getLc])) {
            return null;
        }
        $func = $object->class->methods[$getLc];
        if (!$func instanceof Func\PHP) {
            return null;
        }
        $thisVar = new Variable();
        $thisVar->object($object);

        $result = $this->invokePhpFunctionWithPropertyHookRaw($func, $name, $frame, $thisVar);
        $catchFrame = $this->enforcePropertyHookGetReturn($object, $name, $meta, $result, $frame);
        if (null !== $catchFrame) {
            throw new VM\PropertyHookRefWriteSignal($catchFrame);
        }
        if (null !== $meta) {
            VM\LazyPropertySupport::cacheLazyGetHookResult($object, $name, $meta, $result);
        }

        return $result;
    }

    private function invokePhpFunctionWithPropertyHookRaw(Func\PHP $func, string $rawProperty, Frame $parentFrame, Variable ...$args): Variable
    {
        // Keep hook frames on the fiber run stack so Fiber::suspend() can resume (#9862).
        $savedStack = null !== $this->context->currentFiber
            ? null
            : $this->context->swapRunStack(null);
        $savedExternalCatch = $this->context->propertyHookExternalCatchFrame;
        $this->context->propertyHookExternalCatchFrame = null;
        $savedCallSiteLine = $parentFrame->callSiteLine;
        // Stamp assign/fetch site so set-hook param TypeErrors cite "called in … on line N" (#29666).
        if ($parentFrame->callSiteLine <= 0) {
            $fromOp = VM\FatalSite::lineFromOpcodes($parentFrame);
            if ($fromOp > 0) {
                $parentFrame->callSiteLine = $fromOp;
            }
        }
        try {
            $this->emitPropertyHookDeprecationNotice($func, $rawProperty, $parentFrame);
            $child = $func->getFrame($this->context, $parentFrame);
            $child->propertyHookRawProperty = $rawProperty;
            $child->calledArgs = $args;
            if (
                [] !== $args
                && null !== $func->block->func
                && null !== $func->block->func->class
            ) {
                $thisIdx = $func->block->slotIndexForVariableName('this');
                if (null !== $thisIdx) {
                    $child->scope[$thisIdx] = $args[0];
                }
            }
            $out = new Variable();
            $child->returnVar = $out;
            $this->context->push($child);
            $result = $this->runFrames();
            if (null !== $this->context->propertyHookExternalCatchFrame) {
                throw new VM\PropertyHookRefWriteSignal($this->context->propertyHookExternalCatchFrame);
            }
            if (self::FIBER_SUSPEND === $result) {
                throw new VM\PropertyHookFiberSuspendSignal($parentFrame);
            }
            if (self::SUCCESS !== $result) {
                throw new \LogicException('Property hook invocation failed in this compiler build');
            }

            return $out->resolveIndirect();
        } finally {
            $parentFrame->callSiteLine = $savedCallSiteLine;
            $this->context->propertyHookExternalCatchFrame = $savedExternalCatch;
            if (null !== $savedStack) {
                $this->context->swapRunStack($savedStack);
            }
        }
    }
}
