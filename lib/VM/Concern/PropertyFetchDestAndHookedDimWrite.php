<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Func;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * PROPERTY_FETCH destination look-ahead and hooked-property dim write-back for the VM (#36403).
 *
 * Extracted from {@see \PHPCompiler\VM}: {@code propertyFetchDestUsedAsIncDec} through
 * {@code deliverHookedStaticPropertyDimWriteContainer} (php-src zend_execute /
 * zend_property_hooks get_property_ptr_ptr + dim/append RMW). Concern trait — same
 * namespace as parent so relative Frame / OpCode / Block helpers resolve. Move-only;
 * no new C ABI.
 */
trait PropertyFetchDestAndHookedDimWrite
{
    /** True when fetch dest is mutated by a following ++/-- (#7431, zend_execute.c). */
    private function propertyFetchDestUsedAsIncDec(Frame $frame, OpCode $op): bool
    {
        $destSlot = (int) $op->arg1;
        $next = $frame->block->opCodes[$frame->pos] ?? null;
        if (null === $next) {
            return false;
        }

        return \in_array($next->type, [
            OpCode::TYPE_PRE_INC,
            OpCode::TYPE_POST_INC,
            OpCode::TYPE_PRE_DEC,
            OpCode::TYPE_POST_DEC,
        ], true) && $next->arg3 === $destSlot;
    }

    /**
     * True when an existing instance slot is UNDEF for get_property_ptr_ptr BP_VAR_RW (#29241).
     *
     * Typed uninitialized props stay Error-on-read; untyped/explicitly-unset warn like a plain read.
     */
    private function objectPropertySlotIsUndefinedForRwWarn(
        ObjectEntry $object,
        string $name,
        Frame $frame
    ): bool {
        if ($object->isPropertyExplicitlyUnset($name)) {
            return true;
        }
        $propMeta = $this->classPropertyMeta($object, $name, $frame);
        $propSlot = null !== $propMeta && $object->hasPropertyForMeta($propMeta)
            ? $object->getPropertyForMeta($propMeta)
            : ($object->hasProperty($name) ? $object->getProperty($name) : null);
        if (null === $propSlot) {
            return false;
        }

        return $propSlot->resolveIndirect()->isUndefined()
            && !VM\TypedPropertyCheck::isUninitialized($propSlot);
    }

    /**
     * After creating/binding a property for ++/-- (BP_VAR_RW), emit Undefined property
     * (zend_std_get_property_ptr_ptr — after allocation, #29241).
     */
    private function warnUndefinedPropertyAfterIncDecRwFetch(
        ObjectEntry $object,
        string $name,
        Frame $frame
    ): void {
        $scriptFile = '' !== $frame->scriptPath ? $frame->scriptPath : null;
        $this->context->errors->undefinedPropertyRead(
            $object->class->name,
            $name,
            $this->context,
            $frame,
            $scriptFile
        );
    }

    /**
     * True when fetch dest is the LHS of a following TYPE_ASSIGN (zend_std_write_property).
     * Excludes ASSIGN_REF / ++/-- / compound — those use get_property_ptr_ptr (#31949).
     */
    private function propertyFetchDestUsedAsPlainAssign(Frame $frame, OpCode $op): bool
    {
        $next = $frame->block->opCodes[$frame->pos] ?? null;
        if (null === $next) {
            return false;
        }

        return OpCode::TYPE_ASSIGN === $next->type
            && $next->arg2 === (int) $op->arg1
            && $next->arg3 !== (int) $op->arg1;
    }

    /** True when a following opcode assigns through this PROPERTY_FETCH destination slot (#5370). */
    private function propertyFetchDestUsedAsAssignLvalue(Frame $frame, OpCode $op): bool
    {
        // Only the immediate next opcode (pos already advanced past this fetch). Scanning the
        // whole block false-positives on dead-temp reuse after ARG_SEND / nested fetches (#23986).
        $nextIndex = $frame->pos;
        if ($nextIndex >= $frame->block->nOpCodes) {
            return false;
        }
        $next = $frame->block->opCodes[$nextIndex] ?? null;
        if (null === $next) {
            return false;
        }

        return OpCode::destSlotUsedAsAssignLvalue($next, (int) $op->arg1);
    }

    /** True when fetch dest is the RHS of a following ASSIGN_REF (#22475). */
    private function propertyFetchDestUsedAsAssignRefSource(Frame $frame, OpCode $op): bool
    {
        $destSlot = (int) $op->arg1;
        for ($j = $frame->pos, $n = $frame->block->nOpCodes; $j < $n; $j++) {
            $candidate = $frame->block->opCodes[$j] ?? null;
            if (null === $candidate) {
                continue;
            }
            if (OpCode::destSlotUsedAsAssignRefSource($candidate, $destSlot)) {
                return true;
            }
        }

        return false;
    }

    /**
     * True when fetch dest is returned from a by-ref function (`return $this->prop`, #29456).
     *
     * PROPERTY_FETCH is immediately followed by RETURN on the same slot in the typical
     * `function &get(){ return $this->x; }` lowering.
     */
    private function propertyFetchDestUsedAsReturnByRef(Frame $frame, OpCode $op): bool
    {
        if (!$this->functionReturnsByRef($frame)) {
            return false;
        }
        $destSlot = (int) $op->arg1;
        $next = $frame->block->opCodes[$frame->pos] ?? null;
        if (null === $next) {
            return false;
        }

        return OpCode::destSlotUsedAsReturnValue($next, $destSlot);
    }

    /**
     * Live property alias without treating the fetch as a direct assign lvalue
     * (ASSIGN_REF RHS or by-ref return — #22475 / #29456).
     */
    private function propertyFetchDestUsedAsLiveRefBinding(Frame $frame, OpCode $op): bool
    {
        return $this->propertyFetchDestUsedAsAssignRefSource($frame, $op)
            || $this->propertyFetchDestUsedAsReturnByRef($frame, $op);
    }

    /** True when fetch dest is lhs of a following compound assign ($a[$k] += …, #31991). */
    private function propertyFetchDestUsedAsCompoundAssign(Frame $frame, OpCode $op): bool
    {
        $next = $frame->block->opCodes[$frame->pos] ?? null;
        if (null === $next) {
            return false;
        }
        $destSlot = (int) $op->arg1;

        return OpCode::destSlotUsedAsCompoundAssignRead($next, $destSlot)
            || OpCode::destSlotUsedAsInPlaceCompoundAssign($next, $destSlot);
    }

    /** True when fetch dest is read by a compound op before a later assign (#6438, zend_property_hooks.c). */
    private function propertyFetchDestUsedAsReadBeforeAssign(Frame $frame, OpCode $op): bool
    {
        $destSlot = (int) $op->arg1;
        $next = $frame->block->opCodes[$frame->pos] ?? null;
        if (null === $next) {
            return false;
        }

        return OpCode::destSlotUsedAsCompoundAssignRead($next, $destSlot);
    }

    /**
     * True when fetch dest is the container for `foreach (… as &$v)` (FE_RESET_RW, #29215).
     *
     * PROPERTY_FETCH is immediately followed by ITER_RESET on the same slot; the by-ref flag
     * lives on ITER_VALUE in a successor block (arg3), so scan reachable CFG edges.
     */
    private function propertyFetchDestUsedAsByRefForeachIterable(Frame $frame, OpCode $op): bool
    {
        $destSlot = (int) $op->arg1;
        $next = $frame->block->opCodes[$frame->pos] ?? null;
        if (
            null === $next
            || OpCode::TYPE_ITER_RESET !== $next->type
            || (int) $next->arg1 !== $destSlot
        ) {
            return false;
        }

        return $this->foreachContainerSlotHasByRefValueFetch($frame->block, $destSlot);
    }

    /**
     * Walk successor blocks from {@see $start} for ITER_VALUE with by-ref on {@see $containerSlot}.
     */
    private function foreachContainerSlotHasByRefValueFetch(\PHPCompiler\Block $start, int $containerSlot): bool
    {
        $seen = [];
        $queue = [$start];
        while ([] !== $queue) {
            $block = array_shift($queue);
            $id = spl_object_id($block);
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            foreach ($block->opCodes as $candidate) {
                if (OpCode::destSlotUsedAsByRefForeachValueContainer($candidate, $containerSlot)) {
                    return true;
                }
                foreach ([$candidate->block1, $candidate->block2, $candidate->block3] as $edge) {
                    if ($edge instanceof \PHPCompiler\Block) {
                        $queue[] = $edge;
                    }
                }
            }
        }

        return false;
    }

    /**
     * True when fetch dest is the container for a following dim mutation
     * ($prop[]= / $prop[k]=, or unset($prop[k]) — #6775, #24250).
     *
     * Multi-target unset batches PropertyFetch ops before TYPE_UNSET, so look beyond the
     * immediate next opcode through sibling fetches / other unsets (#24250).
     */
    private function propertyFetchDestUsedAsDimWriteContainer(Frame $frame, OpCode $op): bool
    {
        $destSlot = (int) $op->arg1;
        $ops = $frame->block->opCodes;
        $n = \count($ops);
        for ($i = $frame->pos; $i < $n; ++$i) {
            $next = $ops[$i];
            if (OpCode::destSlotUsedAsDimWriteContainer($next, $destSlot)) {
                return true;
            }
            if (
                OpCode::TYPE_PROPERTY_FETCH === $next->type
                || OpCode::TYPE_PROPERTY_FETCH_WRITE === $next->type
            ) {
                if ((int) $next->arg1 === $destSlot) {
                    // Same temp redefined before a dim mutation — not an aliasing consumer.
                    return false;
                }
                continue;
            }
            if (
                OpCode::TYPE_ARRAY_DIM_FETCH === $next->type
                || OpCode::TYPE_ARRAY_DIM_FETCH_WRITE === $next->type
            ) {
                continue;
            }
            if (OpCode::TYPE_UNSET === $next->type) {
                // unset of a different container; keep scanning for ours.
                continue;
            }
            // `$this->prop['lit'] = $arr`: INIT_ARRAY / JUMP / unrelated ASSIGN may sit
            // between PROPERTY_FETCH and ARRAY_DIM_FETCH_WRITE in the same block (#36380).
            // Only stop when this opcode redefines the property-fetch dest slot.
            if (!$this->opcodeRedefinesScopeSlot($next, $destSlot)) {
                continue;
            }

            return false;
        }

        // Dim write may live in a JUMP / JUMPIF successor (same frame scope).
        return $this->successorBlocksUseDestAsDimWriteContainer($frame->block, $destSlot, $frame->pos);
    }

    /**
     * True when {@see $op} writes a new value into scope slot {@see $slot} (#36380).
     */
    private function opcodeRedefinesScopeSlot(OpCode $op, int $slot): bool
    {
        if (OpCode::TYPE_ASSIGN === $op->type || OpCode::TYPE_ASSIGN_REF === $op->type) {
            return (int) $op->arg2 === $slot || (int) $op->arg1 === $slot;
        }
        if (
            OpCode::TYPE_JUMP === $op->type
            || OpCode::TYPE_JUMPIF === $op->type
            || OpCode::TYPE_JUMPIF_FUNCTION_STATIC_INITIALIZED === $op->type
            || OpCode::TYPE_RETURN === $op->type
            || OpCode::TYPE_RETURN_VOID === $op->type
            || OpCode::TYPE_ECHO === $op->type
            || OpCode::TYPE_PRINT === $op->type
        ) {
            return false;
        }
        // Most expression opcodes deposit into arg1.
        return null !== $op->arg1 && (int) $op->arg1 === $slot;
    }

    /**
     * Follow JUMP / JUMPIF edges for dim-write container look-ahead (#36380).
     */
    private function successorBlocksUseDestAsDimWriteContainer(
        \PHPCompiler\Block $start,
        int $destSlot,
        int $fromPos
    ): bool {
        $ops = $start->opCodes;
        $n = \count($ops);
        $queue = [];
        for ($i = $fromPos; $i < $n; ++$i) {
            $op = $ops[$i];
            foreach ([$op->block1, $op->block2, $op->block3] as $edge) {
                if ($edge instanceof \PHPCompiler\Block) {
                    $queue[] = $edge;
                }
            }
        }
        $seen = [spl_object_id($start) => true];
        while ([] !== $queue) {
            $block = array_shift($queue);
            $id = spl_object_id($block);
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            foreach ($block->opCodes as $candidate) {
                if (OpCode::destSlotUsedAsDimWriteContainer($candidate, $destSlot)) {
                    return true;
                }
                if ($this->opcodeRedefinesScopeSlot($candidate, $destSlot)) {
                    break;
                }
                foreach ([$candidate->block1, $candidate->block2, $candidate->block3] as $edge) {
                    if ($edge instanceof \PHPCompiler\Block) {
                        $queue[] = $edge;
                    }
                }
            }
        }

        return false;
    }

    /**
     * BP_VAR_W dim-assign/append may auto-init typed array props (#31770); BP_VAR_RW may not (#31784).
     */
    private function propertyFetchAllowsTypedArrayDimAutoInit(Frame $frame, OpCode $op): bool
    {
        return $this->propertyFetchDestUsedAsDimWriteContainer($frame, $op)
            && !$this->propertyFetchDestUsedAsDimRwContainer($frame, $op);
    }

    /**
     * True when property fetch feeds ARRAY_DIM_FETCH_WRITE whose element is then
     * ++/--/compound-assigned — Zend BP_VAR_RW (zend_std_get_property_ptr_ptr, #31784).
     */
    private function propertyFetchDestUsedAsDimRwContainer(Frame $frame, OpCode $op): bool
    {
        $destSlot = (int) $op->arg1;
        $ops = $frame->block->opCodes;
        $n = \count($ops);
        for ($i = $frame->pos; $i < $n; ++$i) {
            $next = $ops[$i];
            if (
                OpCode::TYPE_ARRAY_DIM_FETCH_WRITE === $next->type
                && (int) $next->arg2 === $destSlot
            ) {
                $dimSlot = (int) $next->arg1;
                for ($j = $i + 1; $j < $n; ++$j) {
                    $consumer = $ops[$j];
                    if (OpCode::dimSlotUsedAsRwOp($consumer, $dimSlot)) {
                        return true;
                    }
                    // Pure `$dim = expr` (RHS ≠ dim) is BP_VAR_W — stop.
                    if (
                        OpCode::TYPE_ASSIGN === $consumer->type
                        && (int) $consumer->arg2 === $dimSlot
                        && (int) $consumer->arg3 !== $dimSlot
                    ) {
                        return false;
                    }
                    if ((int) $consumer->arg1 === $dimSlot) {
                        if (
                            OpCode::TYPE_PROPERTY_FETCH === $consumer->type
                            || OpCode::TYPE_PROPERTY_FETCH_WRITE === $consumer->type
                            || OpCode::TYPE_ARRAY_DIM_FETCH === $consumer->type
                            || OpCode::TYPE_ARRAY_DIM_FETCH_WRITE === $consumer->type
                        ) {
                            return false;
                        }
                    }
                }

                return false;
            }
            if (
                OpCode::TYPE_PROPERTY_FETCH === $next->type
                || OpCode::TYPE_PROPERTY_FETCH_WRITE === $next->type
            ) {
                if ((int) $next->arg1 === $destSlot) {
                    return false;
                }
                continue;
            }
            if (
                OpCode::TYPE_ARRAY_DIM_FETCH === $next->type
                || OpCode::TYPE_ARRAY_DIM_FETCH_WRITE === $next->type
            ) {
                continue;
            }
            if (OpCode::TYPE_UNSET === $next->type) {
                continue;
            }

            return false;
        }

        return false;
    }

    private function containerNeedsHookedDimWriteBack(Variable $containerSlot): bool
    {
        $container = $containerSlot->resolveIndirect();

        return $container->propertyHookDimWriteBackPending;
    }

    private function tagHookedPropertyDimWriteLvalue(Variable $dimLvalue, Variable $containerSlot): void
    {
        if (
            !$this->containerNeedsHookedDimWriteBack($containerSlot)
            && (null === $containerSlot->objectPropertyOwner || null === $containerSlot->objectPropertyName)
        ) {
            return;
        }
        $dimLvalue->hookedPropertyDimWriteBackContainer = $containerSlot;
    }

    /** Skip eager set-hook dispatch on $prop[] = / $prop[$k] = element writes (#6775, #9875). */
    private function assignDefersHookedPropertyDimWriteBack(Variable $lvalue): bool
    {
        if (null !== $lvalue->hookedPropertyDimWriteBackContainer) {
            return true;
        }
        $target = $lvalue->resolveIndirect();
        if ($target !== $lvalue && null !== $target->hookedPropertyDimWriteBackContainer) {
            return true;
        }

        return false;
    }

    /**
     * Tag property-fetch container for readonly dim-write enforcement (#7245, zend_readonly.c).
     */
    private function tagReadonlyPropertyDimWriteContainer(
        Variable $containerSlot,
        ObjectEntry $owner,
        string $propName
    ): void {
        if (!$owner->constructed) {
            return;
        }
        if (isset($owner->reinitableProperties[$propName])) {
            return;
        }
        if (null === $this->readonlyPropertyDeclaringClass($owner, $propName)) {
            return;
        }
        $containerSlot->objectPropertyOwner = $owner;
        $containerSlot->objectPropertyName = $propName;
    }

    private function flushHookedPropertyDimWriteBackAfterAssign(Variable $writtenLvalue, Frame $frame): ?Frame
    {
        $containerSlot = $writtenLvalue->hookedPropertyDimWriteBackContainer;
        if (null === $containerSlot) {
            $target = $writtenLvalue->resolveIndirect();
            if ($target !== $writtenLvalue) {
                $containerSlot = $target->hookedPropertyDimWriteBackContainer;
            }
        }
        if (null === $containerSlot) {
            return null;
        }
        $container = $containerSlot->resolveIndirect();
        if (!$container->propertyHookDimWriteBackPending) {
            return null;
        }
        $container->propertyHookDimWriteBackPending = false;
        $writtenLvalue->hookedPropertyDimWriteBackContainer = null;
        if ($this->dispatchPropertySetHookAssign($containerSlot, $containerSlot, $frame)) {
            return null;
        }
        if ($this->context->propertyHookSetAborted) {
            $this->context->propertyHookSetAborted = false;

            return null;
        }
        $catchFrame = $this->enforceVirtualPropertyHookWrite($containerSlot, $frame);
        if (null !== $catchFrame) {
            return $catchFrame;
        }
        $containerSlot->copyFrom($container);

        return null;
    }

    /**
     * Dim/append/unset-dim on a hooked property without `&get` is Error in php-src 8.4.24+
     * (zend_object_handlers get_property_ptr_ptr / #28590). Older get→set RMW (#6775/#19171)
     * no longer matches Zend; keep RMW only for virtual `&get`+`set`.
     */
    private function deliverHookedPropertyDimWriteContainer(
        Variable $dest,
        Variable $hookValue,
        ObjectEntry $owner,
        string $propName,
        Frame $frame,
    ): ?Frame {
        $proxy = new Variable();
        $proxy->objectPropertyOwner = $owner;
        $proxy->objectPropertyName = $propName;
        // Caller already enforced `&get` via enforceHookedPropertyDimWriteRequiresByRefGet;
        // keep the check here for static/shared call sites.
        if (!$this->propertyHookGetIsByRef($proxy)) {
            return $this->dispatchVmError(
                $this->indirectModificationOfHookedPropertyMessage($proxy),
                $frame
            );
        }
        $catchFrame = $this->enforceVirtualPropertyHookWrite($proxy, $frame);
        if (null !== $catchFrame) {
            return $catchFrame;
        }
        $working = new Variable();
        $working->duplicateFrom($hookValue);
        $dest->copyFrom($working);
        $dest->objectPropertyOwner = $owner;
        $dest->objectPropertyName = $propName;
        $dest->propertyHookDimWriteBackPending = true;

        return null;
    }

    /**
     * Refuse `$o->hooked[]=` / `$o->hooked[$k]=` / `unset($o->hooked[$k])` unless `&get` (#28590).
     */
    private function enforceHookedPropertyDimWriteRequiresByRefGet(
        ObjectEntry $owner,
        string $propName,
        Frame $frame,
    ): ?Frame {
        $meta = $this->classPropertyMeta($owner, $propName);
        if (null === $meta) {
            return null;
        }
        if (null === $meta->getHookMethodLc && null === $meta->setHookMethodLc) {
            return null;
        }
        if ($meta->getHookByRef) {
            return null;
        }
        $proxy = new Variable();
        $proxy->objectPropertyOwner = $owner;
        $proxy->objectPropertyName = $propName;

        return $this->dispatchVmError(
            $this->indirectModificationOfHookedPropertyMessage($proxy),
            $frame
        );
    }

    /**
     * `&get`-only hooked property: `$o->x[] =` / `$o->x[$k] =` mutates the by-ref get target
     * without a set hook or write-back (#21098, zend_property_hooks.c).
     *
     * @return bool true when the dest was wired as a live by-ref dim container
     */
    private function deliverByRefGetHookedPropertyDimWriteContainer(
        Variable $dest,
        ObjectEntry $owner,
        string $propName,
        Frame $frame,
    ): bool {
        $meta = $this->classPropertyMeta($owner, $propName);
        if (null === $meta || !$meta->getHookByRef || null === $meta->getHookMethodLc) {
            return false;
        }
        if (null !== $meta->setHookMethodLc) {
            // Backed `&get`+`set` is illegal in php-src; virtual may allow both — use RMW path.
            return false;
        }
        $lcClass = strtolower($owner->class->name);
        $propMeta = $this->context->propertyHookRegistry[$lcClass][$propName]
            ?? $this->context->propertyHookRegistry[$lcClass][strtolower($propName)]
            ?? null;
        $backingName = is_array($propMeta)
            ? ($propMeta['getBacking'] ?? null)
            : null;
        if (null !== $backingName && $owner->hasProperty($backingName)) {
            $dest->indirect($owner->getProperty($backingName));

            return true;
        }
        // No recorded backing: invoke `&get` and keep the returned reference live.
        $hookValue = $this->fetchPropertyWithHooksByRef($owner, $propName, $frame);
        if (null === $hookValue) {
            return false;
        }
        $dest->indirect($hookValue);

        return true;
    }

    /**
     * Invoke a get hook preserving return-by-ref aliases (#21098).
     */
    private function fetchPropertyWithHooksByRef(ObjectEntry $object, string $name, Frame $frame): ?Variable
    {
        $meta = $this->classPropertyMeta($object, $name);
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

        return $this->invokePhpFunctionWithPropertyHookRawByRef($func, $name, $frame, $thisVar);
    }

    private function invokePhpFunctionWithPropertyHookRawByRef(
        Func\PHP $func,
        string $rawProperty,
        Frame $parentFrame,
        Variable ...$args
    ): Variable {
        $savedStack = null !== $this->context->currentFiber
            ? null
            : $this->context->swapRunStack(null);
        $savedExternalCatch = $this->context->propertyHookExternalCatchFrame;
        $this->context->propertyHookExternalCatchFrame = null;
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
            // Preserve TYPE_INDIRECT so dim writes mutate the `&get` target (#21098).
            if (Variable::TYPE_INDIRECT === $out->type) {
                return $out;
            }

            return $out->resolveIndirect();
        } finally {
            $this->context->propertyHookExternalCatchFrame = $savedExternalCatch;
            if (null !== $savedStack) {
                $this->context->swapRunStack($savedStack);
            }
        }
    }

    private function deliverHookedStaticPropertyDimWriteContainer(
        Variable $dest,
        Variable $hookValue,
        string $classLc,
        string $propNameRaw,
        Frame $frame,
    ): ?Frame {
        $proxy = new Variable();
        $proxy->staticPropertyClassLc = $classLc;
        $proxy->objectPropertyName = $propNameRaw;
        // Same `&get` requirement as instance dim writes (#28590).
        if (!$this->propertyHookGetIsByRef($proxy)) {
            return $this->dispatchVmError(
                $this->indirectModificationOfHookedPropertyMessage($proxy),
                $frame
            );
        }
        $catchFrame = $this->enforceVirtualPropertyHookWrite($proxy, $frame);
        if (null !== $catchFrame) {
            return $catchFrame;
        }
        $working = new Variable();
        $working->duplicateFrom($hookValue);
        $dest->copyFrom($working);
        $dest->staticPropertyClassLc = $classLc;
        $dest->objectPropertyName = $propNameRaw;
        $dest->propertyHookDimWriteBackPending = true;

        return null;
    }
}
