<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\ObjectLifetime;
use PHPCompiler\VM\Variable;

/**
 * Frame object-ref release, destructor invoke, and VM dead-temp reclaim (#36403).
 *
 * Extracted from {@see \PHPCompiler\VM}: {@code invokeUserDestructor} through
 * {@code variableIsGeneratorYieldStorage} (php-src Zend/zend_objects.c
 * zend_objects_destroy_object / zend_objects_store_del; EG(exception) scope
 * release in Zend/zend_execute.c; temp reclaim mirrors zend_vm_stack_free).
 * Concern trait — same namespace as parent so relative Frame / OpCode helpers
 * resolve. Move-only; no new C ABI.
 */
trait FrameObjectRefAndDeadTempRelease
{
    /**
     * Invoke user __destruct() once (Zend zend_objects_destroy_object; #3144).
     *
     * Generators run pending finally via {@see closeGenerator()} (zend_generator_dtor_storage, #19905).
     */
    public function invokeUserDestructor(ObjectEntry $object): void
    {
        if ($object->destructorInvoked) {
            return;
        }
        if (null !== $object->generatorState) {
            $object->destructorInvoked = true;
            $this->closeGenerator($object->generatorState);

            return;
        }
        $destructor = $object->class->destructor;
        if (null === $destructor) {
            $object->destructorInvoked = true;

            return;
        }
        $object->destructorInvoked = true;
        $thisVar = new Variable();
        $thisVar->object($object);
        ObjectLifetime::addRef($object);

        $savedStack = $this->context->swapRunStack(null);
        $destructorCatch = null;
        $this->context->isolatedDestructorInvoke = true;
        try {
            $child = $destructor->getFrame($this->context, null);
            $thisIdx = $destructor->block->slotIndexForVariableName('this');
            if (null !== $thisIdx) {
                $child->scope[$thisIdx] = $thisVar;
            }
            $child->calledArgs = [$thisVar];
            $this->context->push($child);
            $result = $this->runFrames();
            if (self::SUCCESS !== $result) {
                throw new \LogicException('__destruct() failed in this compiler build');
            }
        } catch (VM\DestructorThrowCatchSignal $signal) {
            $destructorCatch = $signal;
        } finally {
            $this->context->isolatedDestructorInvoke = false;
            $this->context->swapRunStack($savedStack);
            ObjectLifetime::releaseRef($object);
        }
        if (null !== $destructorCatch) {
            throw $destructorCatch;
        }
    }

    private function releaseFrameObjectRefs(Frame $frame): void
    {
        $preserveIds = $this->exceptionObjectIdsToPreserve();
        foreach ($frame->scope as $slotIndex => $slot) {
            if ($this->frameScopeSlotIsClosureByRefCapture($frame, (int) $slotIndex)) {
                continue;
            }
            // PROPERTY_FETCH_WRITE leaves INDIRECT aliases into instance property cells.
            // Releasing through those aliases drops the property's object refcount (Closures
            // stored via $this->prop) even though the cell still holds the ObjectEntry (#22656, #6041).
            if ($this->variableAliasesObjectPropertyCell($slot)) {
                continue;
            }
            // DECLARE_FUNCTION_STATIC / global / class-static CVs are INDIRECT into context-owned
            // storage (#28039 Closures, #28040 object property persistence).
            if ($this->variableAliasesFunctionStaticCell($slot)) {
                continue;
            }
            // Bridged throwable delivered to catch must survive callee CV release (#22541).
            if ($this->variableHoldsPreservedExceptionObject($slot, $preserveIds)) {
                continue;
            }
            ObjectLifetime::releaseDirectObject($slot);
        }
        foreach ($frame->iterators as $iter) {
            if ($this->variableHoldsPreservedExceptionObject($iter, $preserveIds)) {
                continue;
            }
            ObjectLifetime::releaseDirectObject($iter);
        }
    }

    /**
     * Object ids of the exception currently being delivered to catch/finally.
     *
     * @return array<int, true>
     */
    private function exceptionObjectIdsToPreserve(): array
    {
        $ids = [];
        $candidates = [];
        if (null !== $this->context->pendingException) {
            $candidates[] = $this->context->pendingException;
        }
        if (null !== $this->context->activeCatchHandlerFrame
            && null !== $this->context->activeCatchHandlerFrame->activeCatchException
        ) {
            $candidates[] = $this->context->activeCatchHandlerFrame->activeCatchException;
        }
        foreach ($this->context->activeTryHandlerFrames as $handler) {
            if (null !== $handler->activeCatchException) {
                $candidates[] = $handler->activeCatchException;
            }
        }
        foreach ($candidates as $var) {
            $resolved = $var->resolveIndirect();
            if (Variable::TYPE_OBJECT !== $resolved->type) {
                continue;
            }
            try {
                $ids[$resolved->toObject()->id] = true;
            } catch (\LogicException) {
            }
        }

        return $ids;
    }

    /** @param array<int, true> $preserveIds */
    private function variableHoldsPreservedExceptionObject(Variable $var, array $preserveIds): bool
    {
        if ([] === $preserveIds) {
            return false;
        }
        $resolved = $var->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $resolved->type) {
            return false;
        }
        try {
            return isset($preserveIds[$resolved->toObject()->id]);
        } catch (\LogicException) {
            return false;
        }
    }

    /**
     * Release object CVs for abandoned callee activations before catch/finally (Zend leave, #22541).
     *
     * Same-function locals as the handler stay alive (catch may still read them). Nested call
     * frames are released innermost-first; within a frame, scope slot order matches normal return.
     *
     * CFG merge / sequential try frames may have {@see Block::$func} null; resolve via ancestors
     * so `{main}` throw sites are not mistaken for callees when the TYPE_TRY handler block lacks
     * func (#26203 DatePeriod typed-uninit catch destroyForGc).
     */
    private function releaseCalleeObjectRefsBeforeExceptionHandler(Frame $throwFrame, Frame $handler): void
    {
        $handlerFunc = $this->resolveFrameFunc($handler) ?? $this->resolveFrameFunc($throwFrame);
        $pendingOuter = null;
        $pendingFunc = null;
        $toRelease = [];
        for ($f = $throwFrame; null !== $f && $f !== $handler; $f = $f->parent) {
            $frameFunc = $this->resolveFrameFunc($f);
            if ($frameFunc === $handlerFunc) {
                break;
            }
            if ($pendingFunc !== $frameFunc) {
                if (null !== $pendingOuter) {
                    $toRelease[] = $pendingOuter;
                }
                $pendingFunc = $frameFunc;
                $pendingOuter = $f;
            } else {
                $pendingOuter = $f;
            }
        }
        if (null !== $pendingOuter) {
            $toRelease[] = $pendingOuter;
        }
        foreach ($toRelease as $frame) {
            $this->releaseFrameObjectRefs($frame);
        }
    }

    /**
     * Owning PHPCfg Func for a VM frame — walk parents when the CFG block omitted func (#26203).
     */
    private function resolveFrameFunc(Frame $frame): ?\PHPCfg\Func
    {
        for ($f = $frame; null !== $f; $f = $f->parent) {
            $func = $f->block->func ?? null;
            if (null !== $func) {
                return $func;
            }
        }

        return null;
    }

    /**
     * After throw-path finally completes with no local catch, undef CVs of that function (#22541).
     *
     * Try-body locals may live only on the throw-site CFG frame (not the TYPE_TRY handler frame),
     * so walk from $throwFrame through $handler and release each same-function scope once.
     */
    private function releaseHandlerScopeObjectRefsOnExceptionLeave(Frame $handler, ?Frame $throwFrame = null): void
    {
        $func = $handler->block->func ?? null;
        $seenVars = [];
        for ($f = $throwFrame ?? $handler; null !== $f; $f = $f->parent) {
            if (($f->block->func ?? null) === $func) {
                $this->releaseFrameObjectRefsOnce($f, $seenVars);
            }
            if ($f === $handler) {
                for ($p = $handler->parent; null !== $p; $p = $p->parent) {
                    if (($p->block->func ?? null) !== $func) {
                        break;
                    }
                    $this->releaseFrameObjectRefsOnce($p, $seenVars);
                }
                break;
            }
        }
    }

    /**
     * @param array<int, true> $seenVars
     */
    private function releaseFrameObjectRefsOnce(Frame $frame, array &$seenVars): void
    {
        foreach ($frame->scope as $slotIndex => $slot) {
            if ($this->frameScopeSlotIsClosureByRefCapture($frame, (int) $slotIndex)) {
                continue;
            }
            if ($this->variableAliasesObjectPropertyCell($slot)) {
                continue;
            }
            if ($this->variableAliasesFunctionStaticCell($slot)) {
                continue;
            }
            if ($this->variableHoldsPreservedExceptionObject($slot, $this->exceptionObjectIdsToPreserve())) {
                continue;
            }
            $id = spl_object_id($slot);
            if (isset($seenVars[$id])) {
                continue;
            }
            $seenVars[$id] = true;
            ObjectLifetime::releaseDirectObject($slot);
        }
        foreach ($frame->iterators as $iter) {
            $id = spl_object_id($iter);
            if (isset($seenVars[$id])) {
                continue;
            }
            $seenVars[$id] = true;
            ObjectLifetime::releaseDirectObject($iter);
        }
    }

    /**
     * True when a compiler temp slot is still read/written by opcodes after the current PC (#6467).
     */
    private function isVmScopeSlotUsedByFollowingOps(Frame $frame, int $slot): bool
    {
        $block = $frame->block;
        if (null === $block) {
            return false;
        }
        for ($i = $frame->pos; $i < $block->nOpCodes; ++$i) {
            $next = $block->opCodes[$i];
            // Null-constructor stub does not consume the NEW result temp (#6467, #6620).
            if (OpCode::TYPE_FUNCCALL_EXEC_NORETURN === $next->type) {
                continue;
            }
            // Skip startLine / call-site line immediates — same rule as Block::opCodeReadsScopeSlot (#23484).
            foreach ($block->opCodeValueScopeArgs($next) as $arg) {
                if (is_int($arg) && $arg === $slot) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Release php-cfg dead compiler temps at statement boundary (Zend end-of-statement, #6456).
     *
     * @param int ...$keepSlots scope slots still needed by the current opcode
     */
    private function shouldDeferVmDeadTempRelease(Frame $frame): bool
    {
        return null !== $frame->listUnpackAssignMergeBlock;
    }

    /**
     * {main} temps may share the same Variable object as a named local CV (#16040, #15183).
     *
     * JUMPIF dead-temp release must not null that shared storage — e.g. ternary merge assign
     * result slot aliased with `$t`, then nested `$t[1][0]` in a later ternary sees null (#24017).
     * Use {@see Block::isNamedAssignDestSlot()} — the private index array is not visible here.
     */
    private function vmDeadTempReleaseWouldClobberNamedLocal(Frame $frame, int $slot): bool
    {
        if (!$frame->block->isMainScript() || !isset($frame->scope[$slot])) {
            return false;
        }
        $slotVar = $frame->scope[$slot];
        try {
            $target = $slotVar->resolveIndirect();
        } catch (\LogicException) {
            return false;
        }
        foreach ($frame->block->eachNamedScopeSlot() as [, $namedSlot]) {
            if ($namedSlot === $slot || !isset($frame->scope[$namedSlot])) {
                continue;
            }
            if (!$frame->block->isNamedAssignDestSlot($namedSlot)) {
                continue;
            }
            $namedVar = $frame->scope[$namedSlot];
            if ($namedVar === $slotVar) {
                return true;
            }
            try {
                if ($namedVar->resolveIndirect() === $target) {
                    return true;
                }
            } catch (\LogicException) {
            }
        }

        return false;
    }

    private function releaseVmStatementDeadTemps(Frame $frame, int ...$keepSlots): void
    {
        if ($this->shouldDeferVmDeadTempRelease($frame)) {
            return;
        }
        $keep = array_fill_keys($keepSlots, true);
        $cfg = $frame->block->orig;
        if (null === $cfg) {
            return;
        }
        foreach ($cfg->deadOperands as $op) {
            $slot = $frame->block->slotForOperand($op);
            if (null === $slot || isset($keep[$slot]) || !isset($frame->scope[$slot])) {
                continue;
            }
            if (isset($frame->block->constants[$slot])) {
                continue;
            }
            if ($frame->block->isNamedVariableSlot($slot)) {
                continue;
            }
            if (isset($frame->block->deferredArrayLiteralKeepSlots[$slot])) {
                continue;
            }
            if ($this->isVmScopeSlotUsedByFollowingOps($frame, $slot)) {
                continue;
            }
            if ($frame->block->scopeSlotReadInJumpTargets($slot)) {
                continue;
            }
            $this->releaseVmDeadScopeSlot($frame, $slot);
        }
    }

    /**
     * Drop compiler temps after a conditional branch — e.g. WeakReference::get() in `if ($wr->get() !== $o)` (#14103).
     */
    private function releaseVmJumpIfCondTemps(Frame $frame, int $keepSlot): void
    {
        $ephemeral = $frame->block->ephemeralScopeSlotIndexes();
        if ([] === $ephemeral) {
            return;
        }
        foreach ($ephemeral as $slot) {
            if ($slot === $keepSlot || !isset($frame->scope[$slot])) {
                continue;
            }
            if (isset($frame->block->deferredArrayLiteralKeepSlots[$slot])) {
                continue;
            }
            if (
                $frame->block->scopeSlotReadInJumpTargets($slot)
                || $frame->block->scopeSlotReadInDirectJumpTargets($slot)
            ) {
                continue;
            }
            // Large inline array literals materialize after ternary JUMPIFs in the same block (#14134).
            if ($this->isVmScopeSlotUsedByFollowingOps($frame, $slot)) {
                continue;
            }
            $this->releaseVmDeadScopeSlot($frame, $slot);
        }
    }

    /** @param int ...$keepSlots result + other operand slots to preserve */
    private function releaseVmBinaryOpOperandTemp(Frame $frame, int $operandSlot, int ...$keepSlots): void
    {
        $keep = array_fill_keys($keepSlots, true);
        if (isset($keep[$operandSlot]) || $frame->block->isNamedVariableSlot($operandSlot)) {
            return;
        }
        if (isset($frame->block->constants[$operandSlot])) {
            return;
        }
        $this->releaseVmDeadScopeSlot($frame, $operandSlot);
    }

    private function releaseVmDeadScopeSlot(Frame $frame, int $slot): void
    {
        if (!isset($frame->scope[$slot]) || $frame->block->isNamedVariableSlot($slot)) {
            return;
        }
        if ($this->vmDeadTempReleaseWouldClobberNamedLocal($frame, $slot)) {
            return;
        }
        if (isset($frame->block->deferredArrayLiteralKeepSlots[$slot])) {
            return;
        }
        if ($this->variableAliasesObjectPropertyCell($frame->scope[$slot])) {
            return;
        }
        if ($this->variableAliasesFunctionStaticCell($frame->scope[$slot])) {
            return;
        }
        if ($this->variableIsGeneratorYieldStorage($frame->scope[$slot])) {
            return;
        }
        $var = $frame->scope[$slot]->resolveIndirect();
        if ($var->generatorYieldStorage) {
            return;
        }
        if (Variable::TYPE_OBJECT === $var->type) {
            try {
                $objectId = $var->toObject()->id;
            } catch (\LogicException) {
                $objectId = null;
            }
            if (null !== $objectId) {
                foreach ($frame->scope as $otherSlot => $otherVar) {
                    if ($otherSlot === $slot) {
                        continue;
                    }
                    $other = $otherVar->resolveIndirect();
                    if (Variable::TYPE_OBJECT !== $other->type) {
                        continue;
                    }
                    try {
                        if ($other->toObject()->id === $objectId) {
                            $frame->scope[$slot]->null();

                            return;
                        }
                    } catch (\LogicException) {
                    }
                }
                if ($this->scopeArraysReferenceObjectId($frame, $objectId)) {
                    $frame->scope[$slot]->null();

                    return;
                }
                if ($this->context->userConstantReferencesObjectId($objectId)) {
                    $frame->scope[$slot]->null();

                    return;
                }
            }
        }
        // Direct TYPE_OBJECT holders: Variable::null()/reset() already releaseRef once.
        // INDIRECT aliases do not own a ref in reset(), so release the target once first
        // (#22868 — releaseDirectObject+null double-freed temps still bound on closures).
        $slotVar = $frame->scope[$slot];
        if ($slotVar->isIndirect()) {
            ObjectLifetime::releaseDirectObject($slotVar);
        }
        $slotVar->null();
    }

    /** Keep array-literal element objects alive when expr temps are released (#14120, #5593). */
    private function scopeArraysReferenceObjectId(Frame $frame, int $objectId): bool
    {
        foreach ($frame->scope as $scopeVar) {
            $resolved = $scopeVar->resolveIndirect();
            if (Variable::TYPE_ARRAY !== $resolved->type) {
                continue;
            }
            foreach ($resolved->toArray()->iterateKeyed(true) as [, $element]) {
                $cell = $element->resolveIndirect();
                if (Variable::TYPE_OBJECT !== $cell->type) {
                    continue;
                }
                try {
                    if ($cell->toObject()->id === $objectId) {
                        return true;
                    }
                } catch (\LogicException) {
                }
            }
        }

        return false;
    }

    /**
     * True when a scope/call-arg cell resolves to a live object property backing store (#6041, #22656).
     *
     * Used by dead-temp cleanup and frame object-ref release so INDIRECT write aliases do not
     * releaseRef() objects still owned by the instance property Variable.
     */
    private function variableAliasesObjectPropertyCell(Variable $var): bool
    {
        if (null !== $var->objectPropertyOwner) {
            return true;
        }
        $resolved = $var->resolveIndirect();

        return null !== $resolved->objectPropertyOwner;
    }

    /**
     * True when a scope cell is (or aliases) context-owned long-lived storage (#28039, #28040, #31937).
     *
     * DECLARE_FUNCTION_STATIC / global / class-static install an INDIRECT into a persistent cell;
     * FETCH_DIM_W into those arrays (and instance-property arrays) aliases a HashTable bucket.
     * Releasing through that alias on frame exit destroys Closures and wipes object properties
     * the persistent table still holds (destroyForGc while the cell pointer survives).
     */
    private function variableAliasesFunctionStaticCell(Variable $var): bool
    {
        $candidates = [$var];
        if ($var->isIndirect()) {
            $candidates[] = $var->resolveIndirect();
        }
        foreach ($candidates as $cell) {
            if ($cell->functionStaticStorage) {
                return true;
            }
            if ($cell->persistentHashTableBucket) {
                return true;
            }
            if (null !== $this->context->functionStaticKeyForStorage($cell)) {
                return true;
            }
            if ($this->context->isGlobalStorage($cell)) {
                return true;
            }
            if ($this->isStaticPropertyStorageCell($cell)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Mark a HashTable bucket that lives in persistent array storage (#31937).
     *
     * Class-static / function-static / global cells are already skipped by
     * {@see variableAliasesFunctionStaticCell}; the bucket Variable is a different
     * identity, so FETCH_DIM_W aliases must be tagged separately.
     */
    private function markPersistentHashTableBucketIfNeeded(Variable $containerSlot, Variable $bucket): void
    {
        if ($this->variableAliasesFunctionStaticCell($containerSlot)
            || $this->variableAliasesObjectPropertyCell($containerSlot)
        ) {
            $bucket->persistentHashTableBucket = true;

            return;
        }
        $resolved = $containerSlot->resolveIndirect();
        if ($resolved->persistentHashTableBucket) {
            $bucket->persistentHashTableBucket = true;
        }
    }

    /** Generator yield key/value cells must survive fcall temp release (#18184). */
    private function variableIsGeneratorYieldStorage(Variable $var): bool
    {
        if ($var->generatorYieldStorage) {
            return true;
        }

        return $var->resolveIndirect()->generatorYieldStorage;
    }
}
