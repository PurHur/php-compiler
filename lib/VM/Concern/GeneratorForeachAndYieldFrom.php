<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\VM\FiberState;
use PHPCompiler\VM\GeneratorState;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\ObjectPropertyIterator;
use PHPCompiler\VM\Variable;
use PHPCompiler\VM\WeakMapIterator;

/**
 * Foreach iteration, generators, and yield-from for the VM (#36403).
 *
 * Extracted from {@see \PHPCompiler\VM}: {@code variableIsGenerator} through
 * {@code advanceGeneratorIteration} (php-src Zend/zend_generators.c;
 * Zend/zend_execute.c FE_RESET_R / FE_RESET_RW / FE_FETCH; yield-from in
 * zend_generator_get_child / zend_generator_resume). Concern trait — same
 * namespace as parent so relative Frame / OpCode helpers resolve. Move-only;
 * no new C ABI.
 */
trait GeneratorForeachAndYieldFrom
{
    private function variableIsGenerator(Variable $container): bool
    {
        $container = $container->resolveIndirect();

        return Variable::TYPE_OBJECT === $container->type
            && null !== $container->toObject()->generatorState;
    }

    /**
     * Zend FE_RESET_R: foreach by-value keeps an addRef'd snapshot so mutators (array_pop, etc.)
     * COW-separate the live variable without truncating iteration (Zend/zend_execute.c, #13138).
     */
    private function bindArrayForeachIteratorContainer(Frame $frame, int $slot, Variable $source): void
    {
        $source = $source->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $source->type) {
            throw new \LogicException('Array foreach reset requires an array');
        }
        $ht = $source->toArray();
        $ht->addRef();
        $iterContainer = new Variable();
        $iterContainer->array($ht);
        $frame->iterators[$slot] = $iterContainer;
        $this->context->foreachIterators[$slot] = $iterContainer;
        $ht->iterReset();
    }

    /** Zend FE_RESET_RW: by-reference foreach iterates the live array HashTable. */
    private function rebindArrayForeachToLiveContainer(Frame $frame, int $slot): void
    {
        if (!isset($frame->scope[$slot])) {
            return;
        }
        $live = $frame->scope[$slot];
        // ITER_RESET always addRef's a snapshot wrapper (by-value COW). Switching to the
        // live CV must drop that extra HT ref — otherwise `$a[]` during foreach-by-ref
        // zend_array_dup's and leaves IS_REFERENCE aliases on the iterated slot (#32128).
        $old = $frame->iterators[$slot] ?? ($this->context->foreachIterators[$slot] ?? null);
        if (null !== $old && $old !== $live) {
            $resolved = $old->resolveIndirect();
            if (Variable::TYPE_ARRAY === $resolved->type) {
                $resolved->toArray()->delRef();
            }
        }
        // Zend FE_RESET_RW SEPARATE_ARRAY: property defaults / other shared tables must
        // become unique before ZVAL_MAKE_REF, or `$o->items[]` dups and shares IS_REFERENCE
        // with the class default (#32128).
        $live->separateArrayForWrite();
        $frame->iterators[$slot] = $live;
        $this->context->foreachIterators[$slot] = $live;
        // ITER_RESET stores the by-value snapshot on the header frame; CFG edges copy
        // frame->iterators to children (#36354). Rebind must replace that snapshot on
        // every ancestor in this activation — otherwise ITER_VALID on the reused header
        // restores context->foreachIterators to the snapshot and the next FE_FETCH_RW
        // delRefs it again (rc 1→0), destroying the live HT mid-foreach (i11 / #24010).
        $func = $frame->block->func ?? null;
        for ($ancestor = $frame->parent; null !== $ancestor; $ancestor = $ancestor->parent) {
            if (
                null !== $func
                && null !== $ancestor->block
                && null !== $ancestor->block->func
                && $ancestor->block->func !== $func
            ) {
                break;
            }
            if (isset($ancestor->iterators[$slot])) {
                $ancestor->iterators[$slot] = $live;
            }
        }
    }

    private function resolveForeachContainer(Frame $frame, int $slot): Variable
    {
        // Prefer the per-frame cache. context->foreachIterators is keyed only by operand
        // slot index, so a nested/recursive call that reuses the same slot number would
        // otherwise leave the caller's ITER_VALID reading the callee's exhausted HT
        // (recursive flatten / foreach-in-function calling foreach — #36354; Zend keeps
        // FE state on execute_data, see zend_execute.c ZEND_FE_RESET / FE_FETCH).
        if (isset($frame->iterators[$slot])) {
            $this->context->foreachIterators[$slot] = $frame->iterators[$slot];

            return $frame->iterators[$slot]->resolveIndirect();
        }
        if (isset($this->context->foreachIterators[$slot])) {
            return $this->context->foreachIterators[$slot]->resolveIndirect();
        }
        if ($this->isForeachObjectIteratorSlot($slot)) {
            throw new \LogicException('Foreach iterator container slot is not initialized');
        }

        return $frame->scope[$slot]->resolveIndirect();
    }

    private function objectForeachIterator(int $slot): ObjectPropertyIterator
    {
        if (!isset($this->context->objectPropertyIterators[$slot])) {
            throw new \LogicException('Object foreach iterator not initialized');
        }

        return $this->context->objectPropertyIterators[$slot];
    }

    private function weakMapForeachIterator(int $slot): WeakMapIterator
    {
        if (!isset($this->context->weakMapIterators[$slot])) {
            throw new \LogicException('WeakMap foreach iterator not initialized');
        }

        return $this->context->weakMapIterators[$slot];
    }

    private function isWeakMapForeachSlot(int $slot): bool
    {
        return isset($this->context->weakMapIterators[$slot]);
    }

    private function isForeachObjectIteratorSlot(int $slot): bool
    {
        return array_key_exists($slot, $this->context->foreachObjectAdvance);
    }

    private function isForeachInvalidSlot(int $slot): bool
    {
        return isset($this->context->foreachInvalidSlots[$slot]);
    }

    /**
     * Zend ZEND_FE_RESET_R invalid operand (zend_vm_def.h, #4879).
     *
     * Line comes from the ITER_RESET opcode's foreach site (#27953) — not the prior statement.
     */
    private function warnForeachNonTraversable(Variable $container, Frame $frame, ?OpCode $op = null): void
    {
        $resolved = $container->resolveIndirect();
        $line = 0;
        if (null !== $op?->sourceLocation && $op->sourceLocation->startLine > 0) {
            $line = $op->sourceLocation->startLine;
        }
        $this->context->errors->triggerErrorWithHandlerFirst(
            'foreach() argument must be of type array|object, '
            .VM\EnumCaseSupport::typeNameForTypeErrorActual($resolved).' given',
            ErrorReporter::E_WARNING,
            '' !== $frame->scriptPath ? $frame->scriptPath : null,
            $this->context,
            $frame,
            $line
        );
    }

    /**
     * @throws \Error zend_generators.c yield-from container validation (#4909, #5195)
     */
    private function throwYieldFromInvalidContainer(VM\Variable $container): void
    {
        throw new \Error('Can use "yield from" only with arrays and Traversables');
    }

    /**
     * Zend ZEND_YIELD_FROM completion: assign delegated return to the yield-from expression slot.
     */
    private function completeYieldFromDelegation(
        GeneratorState $gen,
        Frame $frame,
        OpCode $op,
        ?Variable $delegatedReturn,
    ): void {
        $gen->yieldFromActive = false;
        $gen->yieldFromIteratorAdvance = false;
        if (null === $op->arg1 || !isset($frame->scope[$op->arg1])) {
            return;
        }
        $slot = (int) $op->arg1;
        $gen->yieldResultSlot = $slot;
        if (null !== $delegatedReturn) {
            $frame->scope[$slot]->copyFrom($delegatedReturn->resolveIndirect());
        } else {
            $frame->scope[$slot]->null();
        }
    }

    private function yieldFromContainerIsTraversable(VM\Variable $container): bool
    {
        $container = $container->resolveIndirect();
        if (Variable::TYPE_ARRAY === $container->type) {
            return true;
        }
        if ($this->variableIsGenerator($container)) {
            return true;
        }
        if (Variable::TYPE_OBJECT !== $container->type) {
            return false;
        }
        $entry = $container->toObject()->class;
        if (VM\InterfaceCheck::entryImplements($entry, 'iteratoraggregate', $this->context)) {
            return true;
        }

        return VM\ForeachIterator::entryImplementsIteratorProtocol($entry, $this->context);
    }

    private function findGeneratorState(Frame $frame): ?GeneratorState
    {
        while (null !== $frame) {
            if (null !== $frame->generatorState) {
                return $frame->generatorState;
            }
            $frame = $frame->parent;
        }

        return null;
    }

    /**
     * Resume a generator (Generator::send / ::next / ::rewind / foreach), optionally injecting a send value.
     */
    public function resumeGenerator(GeneratorState $gen, ?Variable $sendValue = null): bool
    {
        if ($gen->done) {
            return false;
        }
        $sendStartsGenerator = null !== $sendValue && !$gen->started;
        if (null !== $sendValue) {
            $gen->pendingSend->copyFrom($sendValue);
            $gen->hasPendingSend = true;
        }

        $active = $this->advanceGeneratorIteration($gen);
        // Zend: first send() on an unstarted generator opens, then injects+resumes past the
        // first yield (bare `yield`, `$v = yield expr`, and plain `yield expr`) — #18108 / #23712.
        if ($sendStartsGenerator && $active) {
            // Plain `yield expr` has no receive slot: discard the sent value before resuming.
            if ($gen->hasPendingSend && null === $gen->yieldResultSlot) {
                $gen->hasPendingSend = false;
            }
            $active = $this->advanceGeneratorIteration($gen);
        }

        return $active;
    }

    /** Generator::throw() — inject Throwable at yield suspension (Zend zend_generators.c). */
    public function throwGenerator(GeneratorState $gen, Variable $exception): bool
    {
        if ($gen->done) {
            // Zend Generator::throw(): closed generator throws in caller context (#10414).
            $thrown = new Variable();
            $thrown->copyFrom($exception);
            throw new VM\GeneratorUncaughtThrow($thrown);
        }
        $gen->pendingThrow->copyFrom($exception);
        $gen->hasPendingThrow = true;

        return $this->advanceGeneratorIteration($gen);
    }

    /**
     * Close a started generator and run pending finally (Zend zend_generator_dtor_storage, #19905).
     *
     * Called from object release / unset / GC when the Generator instance is destroyed.
     * Foreach `break` alone does not close — only destroying the generator object does.
     *
     * @see https://github.com/php/php-src/blob/master/Zend/zend_generators.c zend_generator_dtor_storage
     */
    public function closeGenerator(GeneratorState $gen): void
    {
        if ($gen->done || $gen->forcedClose) {
            return;
        }
        $gen->forcedClose = true;
        try {
            if ($gen->started && null !== $gen->frame) {
                $this->resumeGeneratorFinallyOnForcedClose($gen);
            }
        } finally {
            if (!$gen->done) {
                $gen->markClosedWithoutReturn();
            }
        }
    }

    /**
     * Jump suspended generator into innermost pending finally and resume (php-src dtor_storage).
     */
    private function resumeGeneratorFinallyOnForcedClose(GeneratorState $gen): void
    {
        $suspended = $gen->frame;
        if (null === $suspended) {
            return;
        }
        if ($this->frameIsInFinallyBody($suspended)) {
            return;
        }

        for ($handler = $suspended; null !== $handler; $handler = $handler->parent) {
            if ($handler->generatorState !== $gen && $this->findGeneratorState($handler) !== $gen) {
                break;
            }
            if (!$this->hasPendingFinally($handler)) {
                continue;
            }
            $finallyOp = $this->findFinallyOpForHandler($handler);
            if (null === $finallyOp || null === $finallyOp->block1) {
                continue;
            }
            if (!$this->generatorSuspendedInsideTryBody($handler, $suspended)) {
                continue;
            }

            $this->context->completedFinallyHandlers[spl_object_id($handler)] = true;
            $finallyFrame = $finallyOp->block1->getFrame($this->context, $handler);
            $finallyFrame->generatorState = $gen;
            $gen->frame = $finallyFrame;
            $gen->clearCurrentValue();

            if ($this->advanceGeneratorIteration($gen)) {
                // Yield during forced-close finally — Zend Error (zend_generators.c).
                throw new \Error(GeneratorState::FORCED_CLOSE_YIELD_ERROR);
            }

            return;
        }
    }

    /** True when the suspended frame is still inside this handler's try body (before finally). */
    private function generatorSuspendedInsideTryBody(Frame $handler, Frame $suspended): bool
    {
        $tryOp = null;
        foreach ($handler->block->opCodes as $op) {
            if (OpCode::TYPE_TRY === $op->type) {
                $tryOp = $op;
                break;
            }
        }
        if (null === $tryOp || null === $tryOp->block1) {
            return false;
        }
        $tryBody = $tryOp->block1;
        if ($suspended->block === $tryBody) {
            return true;
        }

        return VM\GeneratorJitHelper::cfgBlockContains($tryBody, $suspended->block);
    }

    private function applyGeneratorPendingSend(GeneratorState $gen): void
    {
        if (!$gen->hasPendingSend || null === $gen->frame || null === $gen->yieldResultSlot) {
            return;
        }
        if (!isset($gen->frame->scope[$gen->yieldResultSlot])) {
            return;
        }
        $gen->frame->scope[$gen->yieldResultSlot]->copyFrom($gen->pendingSend);
        $gen->hasPendingSend = false;
    }

    private function applyGeneratorPendingThrow(GeneratorState $gen): void
    {
        if (!$gen->hasPendingThrow || null === $gen->frame) {
            return;
        }
        $thrown = new Variable();
        $thrown->copyFrom($gen->pendingThrow);
        $gen->hasPendingThrow = false;
        $frame = $gen->frame;
        $catchFrame = $this->findCatchFrameForGeneratorThrow($gen, $thrown);
        VM\ExceptionTrace::captureGeneratorThrowSite($this->context, $frame, $thrown);
        if (null !== $catchFrame) {
            $catchFrame->generatorState = $gen;
            $gen->frame = $catchFrame;

            return;
        }
        $gen->frame = null;
        $gen->markClosedWithoutReturn();
        throw new VM\GeneratorUncaughtThrow($thrown, $frame);
    }

    /** Catch handlers inside the generator function only (not caller try/catch). */
    private function findCatchFrameForGeneratorThrow(GeneratorState $gen, Variable $thrown): ?Frame
    {
        $this->stashPendingException($thrown);
        $throwFrame = $gen->frame;
        for ($handler = $gen->frame; null !== $handler; $handler = $handler->parent) {
            if ($handler->generatorState !== $gen && $this->findGeneratorState($handler) !== $gen) {
                break;
            }
            $catchFrame = $this->dispatchCatchForHandlerFrame($handler, $throwFrame ?? $handler);
            if (null !== $catchFrame) {
                return $catchFrame;
            }
        }
        $this->clearTryCatchUnwindState();

        return null;
    }

    /** Catch handlers inside the fiber callback only (not caller try/catch) (#19592). */
    private function findCatchFrameForFiberThrow(FiberState $fiber, Variable $thrown): ?Frame
    {
        $this->stashPendingException($thrown);
        $throwFrame = $fiber->frame;
        for ($handler = $fiber->frame; null !== $handler; $handler = $handler->parent) {
            if ($handler->fiberState !== $fiber && $this->findFiberState($handler) !== $fiber) {
                break;
            }
            $catchFrame = $this->dispatchCatchForHandlerFrame($handler, $throwFrame ?? $handler);
            if (null !== $catchFrame) {
                return $catchFrame;
            }
        }
        $this->clearTryCatchUnwindState();

        return null;
    }

    /**
     * Foreach / Iterator valid over a Generator; bridge uncaught generator throws to caller catch (#4338).
     *
     * @return Frame|null catch redirect frame when a handler consumed the throw
     */
    private function foreachAdvanceGenerator(Frame $frame, GeneratorState $gen, int $validSlot): ?Frame
    {
        $gen->foreachAdvance = true;
        try {
            // Zend foreach: rewind may leave the generator on the opening yield; first valid
            // must not advance past it (#23713). Later valids resume (next).
            if ($gen->hasCurrent && !$gen->done && !$gen->foreachNeedsAdvance) {
                $frame->scope[$validSlot]->bool(true);
                $gen->foreachNeedsAdvance = true;

                return null;
            }
            $frame->scope[$validSlot]->bool($this->advanceGeneratorIteration($gen));
            $gen->foreachNeedsAdvance = true;

            return null;
        } catch (VM\GeneratorUncaughtThrow $e) {
            return $this->dispatchUncaughtGeneratorThrow($e->thrown, $frame, null);
        } finally {
            $gen->foreachAdvance = false;
        }
    }

    private function advanceGeneratorIteration(GeneratorState $gen): bool
    {
        if ($gen->done) {
            return false;
        }
        if (null === $gen->frame) {
            $gen->frame = $gen->func->getFrame($this->context, null);
            $gen->frame->calledArgs = $gen->calledArgs;
            $gen->frame->generatorState = $gen;
            $gen->frame->pos = 0;
            if (null !== $gen->closureCall) {
                $this->applyClosureBinding($gen->frame, $gen->closureCall);
            }
            // Instance-method / bound-closure generators need $this in scope (#22067).
            VM\GeneratorTrace::ensureFrameThisBound($gen->frame, $gen);
        }
        // Zend zend_generator_resume clears ZEND_GENERATOR_AT_FIRST_YIELD (#23713).
        $gen->atFirstYield = false;
        $gen->started = true;
        $savedStack = $this->context->swapRunStack(null);
        // Isolate try/catch from the caller so a suspended generator try cannot absorb
        // uncaught exceptions after yield / throw→yield-in-catch (#22869).
        $savedTryHandlers = $this->context->activeTryHandlerFrames;
        $savedTryMergeIds = $this->context->tryMergeBlockIds;
        $this->context->activeTryHandlerFrames = $gen->suspendedTryHandlerFrames;
        $this->context->tryMergeBlockIds = $gen->suspendedTryMergeBlockIds;
        try {
            $this->applyGeneratorPendingSend($gen);
            $this->applyGeneratorPendingThrow($gen);
            $this->context->push($gen->frame);
            try {
                $result = $this->runFrames();
            } catch (\TypeError|\Error $e) {
                $thrown = VM\BuiltinExceptionSupport::materializeNativeError($this->context, $e);
                $frame = $gen->frame;
                VM\ExceptionTrace::captureOnThrow($this->context, $frame, $thrown);
                $generatorThrowTrace = null;
                $thrownObj = $thrown->resolveIndirect();
                if (Variable::TYPE_OBJECT === $thrownObj->type) {
                    $resolvedTrace = VM\ExceptionTrace::resolveTraceVariable($thrownObj->toObject());
                    if (Variable::TYPE_ARRAY === $resolvedTrace->type && $resolvedTrace->toArray()->getNumElements() > 0) {
                        $generatorThrowTrace = new Variable();
                        $generatorThrowTrace->duplicateFrom($resolvedTrace);
                    }
                }
                $catchFrame = $this->findCatchFrameForGeneratorThrow($gen, $thrown);
                if (null !== $catchFrame) {
                    $catchFrame->generatorState = $gen;
                    $gen->frame = $catchFrame;
                    // Keep live try state for the nested advance (still inside this isolation).
                    $gen->suspendedTryHandlerFrames = $this->context->activeTryHandlerFrames;
                    $gen->suspendedTryMergeBlockIds = $this->context->tryMergeBlockIds;

                    return $this->advanceGeneratorIteration($gen);
                }
                if (null !== $generatorThrowTrace) {
                    $thrownObj->toObject()
                        ->getProperty(VM\ExceptionSupport::PROP_TRACE)
                        ->duplicateFrom($generatorThrowTrace);
                } else {
                    VM\ExceptionTrace::captureGeneratorThrowSite($this->context, $frame, $thrown);
                }
                $gen->frame = null;
                $gen->markClosedWithoutReturn();
                throw new VM\GeneratorUncaughtThrow($thrown, $frame);
            }
        } finally {
            // Snapshot only while still suspended; closed/returned generators must not keep
            // try handlers that would leak into the caller on the next throw (#22869).
            if (!$gen->done && null !== $gen->frame) {
                $gen->suspendedTryHandlerFrames = $this->context->activeTryHandlerFrames;
                $gen->suspendedTryMergeBlockIds = $this->context->tryMergeBlockIds;
            } else {
                $gen->clearSuspendedTryState();
            }
            $this->context->activeTryHandlerFrames = $savedTryHandlers;
            $this->context->tryMergeBlockIds = $savedTryMergeIds;
            $this->context->swapRunStack($savedStack);
        }
        if (self::GENERATOR_YIELD === $result) {
            return $gen->hasCurrent;
        }
        $gen->frame = null;
        $gen->clearSuspendedTryState();
        if (self::SUCCESS === $result) {
            if (!$gen->hasReturned) {
                $gen->markReturned(null);
            }
        }

        return false;
    }
}
