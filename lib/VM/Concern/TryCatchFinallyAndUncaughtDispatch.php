<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\VM\Variable;

/**
 * VM try/catch/finally unwind and uncaught-exception dispatch (#36403).
 *
 * Extracted from {@see \PHPCompiler\VM}: {@code findCatchFrameForThrow}
 * through {@code raiseUncaughtExceptionWithNext} (php-src Zend/zend_exceptions.c
 * zend_throw_exception_internal / EG(exception) catch matching; zend_vm_def.h
 * ZEND_CATCH / ZEND_FAST_CALL finally; return-through-finally unwind).
 * Concern trait — same namespace as parent so relative Frame / OpCode / Block
 * helpers resolve. Move-only; no new C ABI.
 */
trait TryCatchFinallyAndUncaughtDispatch
{
    private function findCatchFrameForThrow(Frame $frame, Variable $thrown): ?Frame
    {
        $pending = $this->context->pendingException;
        if (null !== $pending && $this->frameIsInFinallyBody($frame)) {
            VM\ExceptionSupport::chainPendingExceptionOnFinallyThrow($thrown, $pending);
        }
        $this->stashPendingException($thrown);
        $handlers = $this->context->activeTryHandlerFrames;
        for ($i = \count($handlers) - 1; $i >= 0; --$i) {
            $handler = $handlers[$i];
            $catchFrame = $this->dispatchCatchForHandlerFrame($handler, $frame);
            if (null !== $catchFrame) {
                \array_splice($this->context->activeTryHandlerFrames, $i);
                if ($this->context->coercingObjectToString) {
                    $this->context->magicMethodThrowHandled = true;
                }
                if ($this->shouldDeferCatchToOuterRunFrames($i)) {
                    throw new VM\BuiltinCallbackCatchRedirect($catchFrame);
                }
                $this->redirectCloneMagicExternalCatch($handler, $catchFrame);

                return $catchFrame;
            }
        }
        for ($handler = $frame->parent ?? $frame; null !== $handler; $handler = $handler->parent) {
            // Only match try/catch on frames that entered TYPE_TRY — not handler opcodes on
            // ancestors before the try body runs (#14504).
            if (!\in_array($handler, $this->context->activeTryHandlerFrames, true)) {
                continue;
            }
            $catchFrame = $this->dispatchCatchForHandlerFrame($handler, $frame);
            if (null !== $catchFrame) {
                if ($this->context->coercingObjectToString) {
                    $this->context->magicMethodThrowHandled = true;
                }
                $handlerIndex = \array_search($handler, $this->context->activeTryHandlerFrames, true);
                if ($this->shouldDeferCatchToOuterRunFrames(
                    false !== $handlerIndex ? (int) $handlerIndex : 0
                )) {
                    throw new VM\BuiltinCallbackCatchRedirect($catchFrame);
                }
                $this->redirectCloneMagicExternalCatch($handler, $catchFrame);

                return $catchFrame;
            }
        }

        return null;
    }

    /**
     * True when a matched try handler must resume on the outer runFrames (#14104, #25816).
     *
     * {@see Context::$deferBuiltinCallbackCatchToOuterRunFrames} defers every match (isolated
     * callbacks). {@see Context::$deferCatchBelowTryHandlerDepth} defers only handlers that were
     * already active before a nested eval() — inner eval try/catch stays on the nested loop.
     */
    private function shouldDeferCatchToOuterRunFrames(int $handlerIndex): bool
    {
        if ($this->context->deferBuiltinCallbackCatchToOuterRunFrames) {
            return true;
        }
        $depth = $this->context->deferCatchBelowTryHandlerDepth;

        return null !== $depth && $handlerIndex < $depth;
    }

    /**
     * Resume a deferred user catch on the outer runFrames stack (#29521, #29534).
     *
     * {@see Context::truncateRunStackForCatch()} during __toString coercion runs on the
     * isolated empty stack from {@see invokePhpFunctionForCoercion()}; re-truncate here so
     * suspended try-body call sites (compare inside a callee) cannot resume AFTER catch.
     */
    private function resumeAfterBuiltinCallbackCatchRedirect(VM\BuiltinCallbackCatchRedirect $redirect): Frame
    {
        $handler = $this->context->activeCatchHandlerFrame;
        if (null !== $handler) {
            $this->context->truncateRunStackForCatch($handler);
        }

        return $redirect->catchFrame;
    }

    /**
     * When __clone() throws into a try/catch outside the isolated clone stack, defer the
     * catch to the clone opcode caller — do not goto restart on the nested stack (#23527).
     *
     * TYPE_TRY stores the pre-getFrame handler, so identity with run-stack frames is unreliable;
     * instead treat a handler as external when it is the clone caller or any of its ancestors.
     *
     * @throws VM\CloneMagicCatchRedirect
     */
    private function redirectCloneMagicExternalCatch(Frame $handler, Frame $catchFrame): void
    {
        if (!$this->context->invokingCloneMagic) {
            return;
        }
        $caller = $this->context->cloneMagicCallerFrame;
        if (null === $caller) {
            return;
        }
        for ($f = $caller; null !== $f; $f = $f->parent) {
            if ($f === $handler) {
                $this->context->cloneMagicExternalCatchFrame = $catchFrame;

                throw new VM\CloneMagicCatchRedirect($catchFrame);
            }
        }
    }

    private function dispatchCatchForHandlerFrame(Frame $handler, Frame $throwFrame): ?Frame
    {
        $this->rewindHandlerToCatchChain($handler);
        $catchFrame = $this->enterMatchingCatchHandler($handler);
        if (null !== $catchFrame) {
            // Catch frame holds the throwable; mirror onto the try handler so callee CV release
            // (#22541) can preserve it (pendingException was already cleared).
            if (null !== $catchFrame->activeCatchException) {
                $handler->activeCatchException = $catchFrame->activeCatchException;
            }
            $this->releaseCalleeObjectRefsBeforeExceptionHandler($throwFrame, $handler);

            return $catchFrame;
        }
        $finallyFrame = $this->enterFinallyHandlerForUnwind($handler, true);
        if (null !== $finallyFrame) {
            // Nested callees die before finally; handler-function locals stay until after finally.
            $this->releaseCalleeObjectRefsBeforeExceptionHandler($throwFrame, $handler);
            $this->context->pendingFinallyUnwindThrowFrame = $throwFrame;

            return $finallyFrame;
        }

        return null;
    }

    private function popTryHandlerIfAtMergeBlock(Frame $frame): void
    {
        if (null === $frame->block) {
            return;
        }
        $id = spl_object_id($frame->block);
        if (!isset($this->context->tryMergeBlockIds[$id])) {
            return;
        }
        unset($this->context->tryMergeBlockIds[$id]);
        if ([] !== $this->context->activeTryHandlerFrames) {
            \array_pop($this->context->activeTryHandlerFrames);
        }
    }

    private function resolveActiveCatchException(Frame $frame): ?Variable
    {
        for ($f = $frame; null !== $f; $f = $f->parent) {
            if (null !== $f->activeCatchException) {
                return $f->activeCatchException;
            }
        }

        return null;
    }

    /** Align handler position to the first TYPE_CATCH after TYPE_TRY (issue #1362). */
    private function rewindHandlerToCatchChain(Frame $handler): void
    {
        $ops = $handler->block->opCodes;
        $n = $handler->block->nOpCodes;
        for ($i = 0; $i < $n; ++$i) {
            if (!isset($ops[$i])) {
                continue;
            }
            if (OpCode::TYPE_TRY !== $ops[$i]->type) {
                continue;
            }
            for ($j = $i + 1; $j < $n; ++$j) {
                if (!isset($ops[$j])) {
                    continue;
                }
                if (OpCode::TYPE_CATCH === $ops[$j]->type) {
                    $handler->pos = $j;

                    return;
                }
                if (OpCode::TYPE_FINALLY === $ops[$j]->type) {
                    return;
                }
            }

            return;
        }
    }

    private function enterMatchingCatchHandler(Frame $handler): ?Frame
    {
        if (null === $this->context->pendingException) {
            return null;
        }
        while ($handler->pos < $handler->block->nOpCodes) {
            $op = $handler->block->opCodes[$handler->pos];
            if (OpCode::TYPE_CATCH !== $op->type) {
                if (OpCode::TYPE_FINALLY === $op->type) {
                    break;
                }

                return null;
            }
            $handler->pos++;
            if (!$this->catchTypesMatch($op, $this->context->pendingException)) {
                continue;
            }
            $caught = $this->context->pendingException;
            $this->context->pendingException = null;
            if (null !== $op->arg3) {
                if (!isset($handler->scope[$op->arg3])) {
                    $handler->scope[$op->arg3] = new Variable();
                }
                $handler->scope[$op->arg3]->copyFrom($caught);
            }
            // php-cfg may fuse an empty catch body with the merge block (no TYPE_JUMP edge out of
            // the catch). Ensure finally still runs before resuming the merge (#14959).
            if (
                null !== $op->block2
                && $op->block1 === $op->block2
                && $this->hasPendingFinally($handler)
            ) {
                $this->skipTryCatchHandlerTail($handler);
                $this->context->activeCatchHandlerFrame = $handler;
                $this->context->pendingMergeAfterFinally = $op->block2;
                $this->context->truncateRunStackForCatch($handler);
                $this->clearThrowDispatchState();

                return $this->enterFinallyHandlerForUnwind($handler, false);
            }
            $catchFrame = $op->block1->getFrame($this->context, $handler);
            $this->bindCatchVariableToFrame($catchFrame, $op->arg3, $caught);
            $gen = $this->findGeneratorState($handler);
            if (null !== $gen) {
                $catchFrame->generatorState = $gen;
            }
            $mergeFrame = null;
            if (null !== $op->block2) {
                $mergeFrame = $op->block2->getFrame($this->context, $handler);
                $mergeFrame->parent = $handler->parent;
                if (null !== $gen) {
                    $mergeFrame->generatorState = $gen;
                }
            }
            $this->skipTryCatchHandlerTail($handler);
            if (null !== $mergeFrame) {
                $handler->pos = $handler->block->nOpCodes;
                $catchFrame->parent = $mergeFrame;
            }
            $this->context->activeCatchHandlerFrame = $handler;
            // Abandon suspended try-body call sites (throw from callee/finally; #5331).
            $this->context->truncateRunStackForCatch($handler);
            $this->clearThrowDispatchState();

            return $catchFrame;
        }

        return null;
    }

    private function enterFinallyHandlerForUnwind(Frame $handler, bool $resumeCatchAfter = true): ?Frame
    {
        $handlerId = spl_object_id($handler);
        if (isset($this->context->completedFinallyHandlers[$handlerId])) {
            return null;
        }
        $finallyOp = $this->findFinallyOpForHandler($handler);
        if (null === $finallyOp || null === $finallyOp->block1) {
            return null;
        }
        $this->context->completedFinallyHandlers[$handlerId] = true;
        $this->context->pendingCatchResumeHandler = $resumeCatchAfter ? $handler : null;
        // When the finally block is fused with the try-merge block, prevent
        // popTryHandlerIfAtMergeBlock from removing an outer handler (#24728).
        unset($this->context->tryMergeBlockIds[spl_object_id($finallyOp->block1)]);
        return $finallyOp->block1->getFrame($this->context, $handler);
    }

    /** Run finally after a matching catch body before the try/catch merge block (Zend order). */
    private function beginCatchExitFinallyUnwind(Frame $frame, Block $target): ?Frame
    {
        // Catch bodies may reparent to the merge frame (handler frame is not necessarily in the
        // current parent chain); rely on the tracked active catch handler instead (#14959).
        if (!isset($this->context->tryMergeBlockIds[spl_object_id($target)])) {
            return null;
        }
        $handler = $this->context->activeCatchHandlerFrame;
        if (null === $handler || !$this->hasPendingFinally($handler)) {
            return null;
        }
        $this->context->pendingMergeAfterFinally = $target;
        $this->context->activeCatchHandlerFrame = null;

        return $this->enterFinallyHandlerForUnwind($handler, false);
    }

    private function resumeMergeAfterFinally(Frame $frame): ?Frame
    {
        $merge = $this->context->pendingMergeAfterFinally;
        if (null === $merge) {
            return null;
        }
        $this->context->pendingMergeAfterFinally = null;
        $this->context->activeCatchHandlerFrame = null;
        $frame->activeCatchException = null;
        $frame->catchVarSlot = null;

        return $merge->getFrame($this->context, $frame);
    }

    /** Bind catch `$e` and mark initialized — avoid #10358 false undefined warnings on catch reads. */
    private function bindCatchVariableToFrame(Frame $frame, ?int $catchVarSlot, Variable $caught): void
    {
        $frame->activeCatchException = $caught;
        if (null === $catchVarSlot) {
            $frame->catchVarSlot = null;

            return;
        }
        $frame->catchVarSlot = $catchVarSlot;
        if (!isset($frame->scope[$catchVarSlot])) {
            $frame->scope[$catchVarSlot] = new Variable();
        }
        $frame->scope[$catchVarSlot]->copyFrom($caught);
        $this->markScopeSlotInitialized($frame, $catchVarSlot);
    }

    private function resumeGotoAfterFinally(Frame $frame): ?Frame
    {
        $target = $this->context->pendingGotoAfterFinally;
        if (null === $target) {
            return null;
        }
        $this->context->pendingGotoAfterFinally = null;

        return $this->frameForBranch($frame, $target);
    }

    /**
     * Leaving a try body via goto / break / continue must run finally before the jump target
     * (Zend order, #4491 / #25240).
     *
     * php-cfg often wires break/continue (and try fall-through when those edges exist) straight
     * to the try merge block, skipping the finally CFG edge. Jumping to that merge with a pending
     * finally is an unwind. JumpIf edges that stay inside the try body must not unwind.
     */
    private function beginGotoFinallyUnwind(Frame $frame, Block $target): ?Frame
    {
        $handlers = $this->context->activeTryHandlerFrames;
        for ($i = \count($handlers) - 1; $i >= 0; --$i) {
            $handler = $handlers[$i];
            if (!$this->hasPendingFinally($handler)) {
                continue;
            }
            $finallyOp = $this->findFinallyOpForHandler($handler);
            if (null === $finallyOp || null === $finallyOp->block1) {
                continue;
            }
            if ($target === $finallyOp->block1) {
                continue;
            }
            if ($finallyOp->block1 === $frame->block) {
                continue;
            }
            if (!$this->frameIsDescendantOf($frame, $handler)) {
                continue;
            }
            $isMerge = isset($this->context->tryMergeBlockIds[spl_object_id($target)]);
            // Intra-try JumpIf (e.g. `if` fall-through inside the try body) is not a leave.
            if (!$isMerge && $this->blockIsInsideActiveTryBody($handler, $target)) {
                continue;
            }
            $this->context->pendingGotoAfterFinally = $target;

            return $this->enterFinallyHandlerForUnwind($handler, false);
        }

        return null;
    }

    /**
     * True when $target is part of the try body for $handler: reachable from the try entry
     * without crossing merge/finally, and able to reach the merge (or finally) afterward.
     * Leave edges such as `break` to the loop exit are successors of the try JumpIf but do
     * not reach the merge — those must still unwind (#25240).
     */
    private function blockIsInsideActiveTryBody(Frame $handler, Block $target): bool
    {
        $tryOp = $this->findTryOpForHandler($handler);
        if (null === $tryOp || null === $tryOp->block1) {
            return false;
        }
        $entry = $tryOp->block1;
        $merge = $tryOp->block2;
        $finallyOp = $this->findFinallyOpForHandler($handler);
        $finallyBlock = null !== $finallyOp ? $finallyOp->block1 : null;
        if ($target === $entry) {
            return true;
        }
        if ($target === $merge || $target === $finallyBlock) {
            return false;
        }
        $leaveBlocked = [];
        if (null !== $merge) {
            $leaveBlocked[spl_object_id($merge)] = true;
        }
        if (null !== $finallyBlock) {
            $leaveBlocked[spl_object_id($finallyBlock)] = true;
        }
        if (!$this->blockCanReach($entry, $target, $leaveBlocked)) {
            return false;
        }
        // Still inside the try region only if control can rejoin via merge/finally.
        if (null !== $merge && $this->blockCanReach($target, $merge, [])) {
            return true;
        }
        if (null !== $finallyBlock && $this->blockCanReach($target, $finallyBlock, [])) {
            return true;
        }

        return false;
    }

    /**
     * @param array<int, true> $blocked
     */
    private function blockCanReach(Block $from, Block $to, array $blocked): bool
    {
        if ($from === $to) {
            return true;
        }
        $seen = [];
        $queue = [$from];
        while ([] !== $queue) {
            /** @var Block $block */
            $block = \array_pop($queue);
            $id = spl_object_id($block);
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            foreach ($this->blockBranchTargets($block) as $succ) {
                if ($succ === $to) {
                    return true;
                }
                if (isset($blocked[spl_object_id($succ)])) {
                    continue;
                }
                $queue[] = $succ;
            }
        }

        return false;
    }

    /** @return list<Block> */
    private function blockBranchTargets(Block $block): array
    {
        $targets = [];
        foreach ($block->opCodes as $op) {
            foreach ([$op->block1, $op->block2, $op->block3] as $t) {
                if ($t instanceof Block) {
                    $targets[] = $t;
                }
            }
        }

        return $targets;
    }

    private function findTryOpForHandler(Frame $handler): ?OpCode
    {
        foreach ($handler->block->opCodes as $op) {
            if (OpCode::TYPE_TRY === $op->type) {
                return $op;
            }
        }

        return null;
    }

    private function frameIsDescendantOf(Frame $frame, Frame $ancestor): bool
    {
        for ($f = $frame; null !== $f; $f = $f->parent) {
            if ($f === $ancestor) {
                return true;
            }
        }

        return false;
    }

    private function findFinallyOpForHandler(Frame $handler): ?OpCode
    {
        foreach ($handler->block->opCodes as $op) {
            if (OpCode::TYPE_FINALLY === $op->type) {
                return $op;
            }
        }

        return null;
    }

    private function resumeCatchAfterFinally(Frame $frame): ?Frame
    {
        $handler = $this->context->pendingCatchResumeHandler;
        if (null === $handler) {
            return null;
        }
        $this->context->pendingCatchResumeHandler = null;
        $this->rewindHandlerToCatchChain($handler);
        $catchFrame = $this->enterMatchingCatchHandler($handler);
        if (null !== $catchFrame) {
            return $catchFrame;
        }
        $thrown = $this->context->pendingException;
        if (null === $thrown) {
            return null;
        }
        // Leaving this try/finally function scope: destroy locals before outer catch (#22541).
        $throwFrame = $this->context->pendingFinallyUnwindThrowFrame;
        $this->context->pendingFinallyUnwindThrowFrame = null;
        $this->releaseHandlerScopeObjectRefsOnExceptionLeave($handler, $throwFrame);
        // Generator try/finally must bubble to the resume caller via GeneratorUncaughtThrow —
        // not findCatchFrameForThrow into a caller try that is isolated during advance (#22869).
        $gen = $this->findGeneratorState($handler);
        if (null === $gen && null !== $throwFrame) {
            $gen = $this->findGeneratorState($throwFrame);
        }
        if (null !== $gen) {
            $gen->frame = null;
            $gen->markClosedWithoutReturn();
            throw new VM\GeneratorUncaughtThrow($thrown, $throwFrame ?? $handler);
        }
        $fiber = $this->context->currentFiber;
        if (null !== $fiber && (
            $this->findFiberState($handler) === $fiber
            || (null !== $throwFrame && $this->findFiberState($throwFrame) === $fiber)
        )) {
            throw new VM\FiberUncaughtThrow($thrown);
        }
        $outerCatch = $this->findCatchFrameForThrow($handler->parent ?? $handler, $thrown);
        if (null !== $outerCatch) {
            return $outerCatch;
        }
        $this->raiseUncaughtException($thrown);
    }

    private function clearThrowDispatchState(): void
    {
        $this->context->pendingException = null;
        $this->context->pendingCatchResumeHandler = null;
        $this->context->pendingFinallyUnwindThrowFrame = null;
        $this->context->completedFinallyHandlers = [];
    }

    private function clearTryCatchUnwindState(): void
    {
        $this->clearThrowDispatchState();
        $this->context->activeCatchHandlerFrame = null;
        $this->context->pendingMergeAfterFinally = null;
        $this->context->pendingGotoAfterFinally = null;
        $this->clearPendingReturnState();
    }

    private function clearPendingReturnState(): void
    {
        $this->context->pendingReturnActive = false;
        $this->context->pendingReturnDispatch = false;
        $this->context->pendingReturnIsVoid = true;
        $this->context->pendingReturnValue = null;
        $this->context->pendingReturnResumeFrame = null;
    }

    /** Snapshot throw operand so scope reuse cannot clobber pending try exceptions (#5867, #6457). */
    private function stashPendingException(Variable $thrown): void
    {
        if (null !== $this->context->lazyInitializingObject) {
            VM\LazyObjectSupport::captureLazyInitException(
                $this->context->lazyInitializingObject,
                $thrown
            );
        }
        if (null === $this->context->pendingException) {
            $this->context->pendingException = new Variable();
        }
        $this->context->pendingException->copyFrom($thrown);
    }

    private function hasPendingFinally(Frame $handler): bool
    {
        if (null === $this->findFinallyOpForHandler($handler)) {
            return false;
        }

        return !isset($this->context->completedFinallyHandlers[spl_object_id($handler)]);
    }

    private function frameIsInFinallyBody(Frame $frame): bool
    {
        return null !== $this->findFinallyOpForFrameBody($frame);
    }

    /**
     * True when executing a finally CFG block that is distinct from the try merge block.
     * Empty `finally {}` often fuses with the merge (#24728); those epilogues must not be
     * treated as an explicit `return` inside finally (#25239).
     */
    private function frameIsInDistinctFinallyBody(Frame $frame): bool
    {
        $finallyOp = $this->findFinallyOpForFrameBody($frame);
        if (null === $finallyOp) {
            return false;
        }

        return $finallyOp->block1 !== $finallyOp->block2;
    }

    private function findFinallyOpForFrameBody(Frame $frame): ?OpCode
    {
        for ($handler = $frame->parent; null !== $handler; $handler = $handler->parent) {
            $finallyOp = $this->findFinallyOpForHandler($handler);
            if (null === $finallyOp || null === $finallyOp->block1) {
                continue;
            }
            if ($finallyOp->block1 === $frame->block) {
                return $finallyOp;
            }
        }

        return null;
    }

    /**
     * Leaving a finally body (via jump or fused merge RETURN_VOID) — continue return/catch/merge chains.
     *
     * @return bool true when the caller should goto restart (frame updated or pending return scheduled)
     */
    private function completeActiveFinallyUnwind(Frame &$frame): bool
    {
        if (!$this->frameIsInFinallyBody($frame)) {
            return false;
        }
        $this->markFinallyCompletedWhenLeavingFinallyBody($frame);
        $finallyFrame = $this->continueReturnFinallyChain();
        if (null !== $finallyFrame) {
            $frame = $finallyFrame;

            return true;
        }
        if ($this->schedulePendingReturnDispatch()) {
            return true;
        }
        $resumeFrame = $this->resumeCatchAfterFinally($frame);
        if (null !== $resumeFrame) {
            $frame = $resumeFrame;

            return true;
        }
        $mergeFrame = $this->resumeMergeAfterFinally($frame);
        if (null !== $mergeFrame) {
            $frame = $mergeFrame;

            return true;
        }
        // Nested try/finally: run outer finally before the pending break/continue/goto (#25240).
        if (null !== $this->context->pendingGotoAfterFinally) {
            $outerFinally = $this->beginGotoFinallyUnwind(
                $frame,
                $this->context->pendingGotoAfterFinally
            );
            if (null !== $outerFinally) {
                $frame = $outerFinally;

                return true;
            }
        }
        $gotoFrame = $this->resumeGotoAfterFinally($frame);
        if (null !== $gotoFrame) {
            $frame = $gotoFrame;

            return true;
        }

        return false;
    }

    /** Normal try completion runs the finally CFG block directly; mark it done (#3082). */
    private function markFinallyCompletedWhenLeavingFinallyBody(Frame $frame): void
    {
        if (!$this->frameIsInFinallyBody($frame)) {
            return;
        }
        for ($handler = $frame->parent; null !== $handler; $handler = $handler->parent) {
            $finallyOp = $this->findFinallyOpForHandler($handler);
            if (null === $finallyOp || null === $finallyOp->block1) {
                continue;
            }
            if ($finallyOp->block1 !== $frame->block) {
                continue;
            }
            $this->context->completedFinallyHandlers[spl_object_id($handler)] = true;

            return;
        }
    }

    private function findNextFinallyHandlerForReturn(Frame $from): ?Frame
    {
        // Catch frames may skip the handler frame in their parent chain; still need to run the
        // handler finally on `return` from catch (#14959).
        if (
            null !== $this->context->activeCatchHandlerFrame
            && null !== $this->resolveActiveCatchException($from)
            && $this->hasPendingFinally($this->context->activeCatchHandlerFrame)
        ) {
            $catchHandler = $this->context->activeCatchHandlerFrame;
            // Do not run a caller's finally when a nested call (e.g. __construct) returns (#22541).
            if (($catchHandler->block->func ?? null) === ($from->block->func ?? null)) {
                return $catchHandler;
            }
        }
        // Only finally blocks in the returning function — nested callees must not trigger the
        // caller's try/finally (Zend; premature finally after `new` broke exception dtor order #22541).
        $fromFunc = $from->block->func ?? null;
        for ($handler = $from->parent; null !== $handler; $handler = $handler->parent) {
            if (($handler->block->func ?? null) !== $fromFunc) {
                break;
            }
            if ($this->hasPendingFinally($handler)) {
                return $handler;
            }
        }

        return null;
    }

    private function beginReturnFinallyUnwind(Frame $frame, ?Variable $value, bool $isVoid): ?Frame
    {
        $handler = $this->findNextFinallyHandlerForReturn($frame);
        if (null === $handler) {
            return null;
        }
        $this->context->pendingReturnActive = true;
        $this->context->pendingReturnIsVoid = $isVoid;
        $this->context->pendingReturnValue = $value;
        $this->context->pendingReturnResumeFrame = $frame;

        return $this->enterFinallyHandlerForUnwind($handler, true);
    }

    /**
     * Zend: return inside finally replaces any pending try/catch return and clears a pending
     * exception so the finally return value is what the caller observes (#25239).
     *
     * php-src: Zend/zend_vm_def.h (finally return / ZEND_FAST_CALL), Zend/zend_execute.c
     *
     * @return bool true when the caller should goto restart (outer finally or pending dispatch)
     */
    private function applyReturnInsideFinally(Frame &$frame, ?Variable $value, bool $isVoid): bool
    {
        // Suppress pending try exception — finally return wins over EG(exception).
        $this->context->pendingException = null;
        $this->context->pendingCatchResumeHandler = null;
        $this->context->pendingFinallyUnwindThrowFrame = null;
        $this->context->activeCatchHandlerFrame = null;
        $this->context->pendingMergeAfterFinally = null;
        $this->context->pendingGotoAfterFinally = null;

        $this->context->pendingReturnActive = true;
        $this->context->pendingReturnIsVoid = $isVoid;
        $this->context->pendingReturnValue = $value;
        $this->context->pendingReturnResumeFrame = $frame;
        $this->context->pendingReturnDispatch = false;

        $this->markFinallyCompletedWhenLeavingFinallyBody($frame);

        $finallyFrame = $this->continueReturnFinallyChain();
        if (null !== $finallyFrame) {
            $frame = $finallyFrame;

            return true;
        }
        if ($this->schedulePendingReturnDispatch()) {
            return true;
        }

        // No outer finally left — complete the return from this opcode now.
        $this->clearPendingReturnState();

        return false;
    }

    private function continueReturnFinallyChain(): ?Frame
    {
        if (!$this->context->pendingReturnActive || null === $this->context->pendingReturnResumeFrame) {
            return null;
        }
        $handler = $this->findNextFinallyHandlerForReturn($this->context->pendingReturnResumeFrame);
        if (null === $handler) {
            return null;
        }

        return $this->enterFinallyHandlerForUnwind($handler, true);
    }

    private function schedulePendingReturnDispatch(): bool
    {
        if (!$this->context->pendingReturnActive || null === $this->context->pendingReturnResumeFrame) {
            return false;
        }
        if (null !== $this->findNextFinallyHandlerForReturn($this->context->pendingReturnResumeFrame)) {
            return false;
        }
        $this->context->pendingReturnDispatch = true;

        return true;
    }

    /** @return never */
    private function raiseUncaughtException(Variable $thrown): void
    {
        $this->clearTryCatchUnwindState();
        if ($this->context->exceptionHandlers->dispatch($this->context, $thrown)) {
            throw new ScriptExit(0);
        }
        if (Variable::TYPE_OBJECT === $thrown->type) {
            $entry = $thrown->toObject();
            $native = VM\ExceptionSupport::nativeUncaughtThrowable(
                $entry,
                VM\ExceptionSupport::readThrowableMessage($entry)
            );
            if ($this->context->isolatedPhpFunctionInvoke || $this->context->bubbleUncaughtToNative) {
                throw $native;
            }
            VM\ExceptionSupport::emitNativeUncaughtFatal(
                $native,
                $entry,
                $this->context->errors->getDisplayErrors(),
            );
        }
        throw new \Exception($thrown->toString());
    }

    /** @return never */
    private function raiseUncaughtExceptionWithNext(Variable $primary, Variable $next): void
    {
        $this->clearTryCatchUnwindState();
        if ($this->context->exceptionHandlers->dispatch($this->context, $primary)) {
            throw new ScriptExit(0);
        }
        if (Variable::TYPE_OBJECT !== $primary->type || Variable::TYPE_OBJECT !== $next->type) {
            $this->raiseUncaughtException($primary);
        }
        $primaryEntry = $primary->toObject();
        $nextEntry = $next->toObject();
        if ($this->context->isolatedPhpFunctionInvoke || $this->context->bubbleUncaughtToNative) {
            throw VM\ExceptionSupport::nativeUncaughtThrowable(
                $primaryEntry,
                VM\ExceptionSupport::readThrowableMessage($primaryEntry)
            );
        }
        VM\ExceptionSupport::emitNativeUncaughtFatalWithNext(
            VM\ExceptionSupport::nativeUncaughtThrowable(
                $primaryEntry,
                VM\ExceptionSupport::readThrowableMessage($primaryEntry)
            ),
            VM\ExceptionSupport::nativeUncaughtThrowable(
                $nextEntry,
                VM\ExceptionSupport::readThrowableMessage($nextEntry)
            ),
            $this->context->errors->getDisplayErrors(),
        );
    }

}
