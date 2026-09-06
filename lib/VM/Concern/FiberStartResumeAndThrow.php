<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\VM\FiberState;
use PHPCompiler\VM\Variable;

/**
 * Fiber start / resume / throw lifecycle for the VM (#36403).
 *
 * Extracted from {@see \PHPCompiler\VM}: {@code startFiber} through
 * {@code applyFiberPendingThrow} (php-src Zend/zend_fibers.c;
 * Fiber::start / Fiber::resume / Fiber::throw / Fiber::suspend). Concern
 * trait — same namespace as parent so relative Frame helpers resolve.
 * Move-only; no new C ABI.
 */
trait FiberStartResumeAndThrow
{
    /**
     * Start a new fiber (issue #3130).
     *
     * @param list<Variable> $startArgs
     */
    public function startFiber(FiberState $fiber, Variable ...$startArgs): Variable
    {
        if (FiberState::STATUS_INIT !== $fiber->status) {
            throw new VM\NativeFiberError('Cannot start a fiber that has already been started');
        }
        $fiber->resumeArgument->null();
        $child = $fiber->callback->func->getFrame($this->context, null);
        // Bound-closure / instance-method fibers need $this in scope (Zend/zend_fibers.c, #25777).
        // applyClosureBinding also installs use()-captures (same as invokeClosure / generators).
        $this->applyClosureBinding($child, $fiber->callback);
        $child->calledArgs = $startArgs;
        $child->fiberState = $fiber;
        $returnSlot = new Variable();
        $child->returnVar = $returnSlot;
        $fiber->frame = $child;
        $fiber->status = FiberState::STATUS_RUNNING;

        return $this->runFiberExecution($fiber, $returnSlot);
    }

    /**
     * Resume a suspended fiber (issue #3130).
     *
     * @param list<Variable> $resumeArgs
     */
    public function resumeFiber(FiberState $fiber, Variable ...$resumeArgs): Variable
    {
        if (FiberState::STATUS_TERMINATED === $fiber->status) {
            throw new VM\NativeFiberError('Cannot resume a fiber that is not suspended');
        }
        if (FiberState::STATUS_SUSPENDED !== $fiber->status) {
            throw new VM\NativeFiberError('Cannot resume a fiber that is not suspended');
        }
        if ([] !== $resumeArgs) {
            $fiber->resumeArgument->copyFrom($resumeArgs[0]->resolveIndirect());
        } else {
            $fiber->resumeArgument->null();
        }
        if (null !== $fiber->pendingSuspendReturnVar) {
            $fiber->pendingSuspendReturnVar->copyFrom($fiber->resumeArgument);
            $fiber->pendingSuspendReturnVar = null;
        }
        $child = $fiber->frame;
        if (null === $child) {
            throw new \LogicException('Fiber resume missing suspended frame');
        }
        $fiber->status = FiberState::STATUS_RUNNING;
        $returnSlot = new Variable();
        $savedReturn = $child->returnVar;
        $child->returnVar = $returnSlot;
        try {
            return $this->runFiberExecution($fiber, $returnSlot);
        } finally {
            $child->returnVar = $savedReturn;
        }
    }

    /**
     * Throw into a suspended fiber (Fiber->throw()) (Zend/zend_fibers.c parity, #4481).
     */
    public function throwFiber(FiberState $fiber, Variable $exception): Variable
    {
        if (FiberState::STATUS_TERMINATED === $fiber->status) {
            throw new VM\NativeFiberError('Cannot resume a fiber that is not suspended');
        }
        if (FiberState::STATUS_SUSPENDED !== $fiber->status) {
            throw new VM\NativeFiberError('Cannot resume a fiber that is not suspended');
        }
        $fiber->pendingThrow->copyFrom($exception->resolveIndirect());
        $fiber->hasPendingThrow = true;
        $fiber->resumeArgument->null();
        // Mirror resumeFiber: RUNNING so catch→suspend stays legal; returnSlot is wired
        // onto the catch/entry frames inside runFiberExecution after throw dispatch (#23041).
        if (null === $fiber->frame) {
            throw new \LogicException('Fiber throw missing suspended frame');
        }
        $fiber->status = FiberState::STATUS_RUNNING;
        $returnSlot = new Variable();

        return $this->runFiberExecution($fiber, $returnSlot);
    }

    /**
     * Point suspended/catch CFG frames at this invocation's return slot through the fiber entry.
     *
     * Fiber::throw() catch bodies are getFrame()-d from the fiber-entry frame (fiberState),
     * which may be a parent of the suspended try-body frame — wiring only the suspended
     * frame leaves getReturn() empty (Zend/zend_fibers.c, #23041).
     */
    private function wireFiberReturnSlot(FiberState $fiber, Variable $returnSlot): void
    {
        for ($frame = $fiber->frame; null !== $frame; $frame = $frame->parent) {
            $frame->returnVar = $returnSlot;
            if ($frame->fiberState === $fiber) {
                break;
            }
        }
    }

    private function runFiberExecution(FiberState $fiber, Variable $returnSlot): Variable
    {
        $savedFiber = $this->context->currentFiber;
        $this->context->currentFiber = $fiber;
        $savedStack = $this->context->swapRunStack(null);
        try {
            $this->applyFiberPendingThrow($fiber);
            if (null !== $fiber->propertyHookSuspendFrame) {
                $hookFrame = $fiber->propertyHookSuspendFrame;
                $fiber->propertyHookSuspendFrame = null;
                $this->context->push($hookFrame);
                try {
                    $hookStatus = $this->runFrames();
                } catch (VM\FiberUncaughtThrow $e) {
                    $this->terminateFiberAfterThrow($fiber);
                    throw $e;
                } catch (\Throwable $e) {
                    $this->terminateFiberAfterThrow($fiber);
                    throw $e;
                }
                if (self::FIBER_SUSPEND === $hookStatus) {
                    $fiber->propertyHookSuspendFrame = $hookFrame;
                    $fiber->status = FiberState::STATUS_SUSPENDED;
                    $out = new Variable();
                    $out->duplicateFrom($fiber->suspendReturn->resolveIndirect());

                    return $out;
                }
                if (self::SUCCESS !== $hookStatus) {
                    throw new \LogicException('Property hook fiber resume failed in this compiler build');
                }
                if (null === $hookFrame->returnVar) {
                    throw new \LogicException('Property hook fiber resume missing return slot');
                }
                $fiber->propertyHookResumeRead = new Variable();
                $fiber->propertyHookResumeRead->copyFrom($hookFrame->returnVar->resolveIndirect());
            }
            $child = $fiber->frame;
            if (null === $child) {
                throw new \LogicException('Fiber execution missing frame after throw dispatch');
            }
            $this->wireFiberReturnSlot($fiber, $returnSlot);
            $this->context->push($child);
            try {
                $result = $this->runFrames();
            } catch (VM\FiberUncaughtThrow $e) {
                $this->terminateFiberAfterThrow($fiber);
                throw $e;
            } catch (\Throwable $e) {
                $this->terminateFiberAfterThrow($fiber);
                throw $e;
            }
        } finally {
            $this->context->swapRunStack($savedStack);
            $this->context->currentFiber = $savedFiber;
        }
        if (self::FIBER_SUSPEND === $result) {
            $fiber->status = FiberState::STATUS_SUSPENDED;
            $out = new Variable();
            $out->duplicateFrom($fiber->suspendReturn->resolveIndirect());

            return $out;
        }
        if (self::SUCCESS === $result) {
            $fiber->status = FiberState::STATUS_TERMINATED;
            $fiber->frame = null;
            $resolved = $returnSlot->resolveIndirect();
            $fiber->returnValue->copyFrom($resolved);
            $fiber->hasReturnValue = true;
            $fiber->threw = false;
            $out = new Variable();
            // Zend/zend_fibers.c: resume()/start() return NULL when fiber is dead (#10149).
            $out->null();

            return $out;
        }

        throw new \LogicException('Fiber execution failed in this compiler build');
    }

    private function terminateFiberAfterThrow(FiberState $fiber): void
    {
        $fiber->status = FiberState::STATUS_TERMINATED;
        $fiber->frame = null;
        $fiber->pendingSuspendReturnVar = null;
        $fiber->propertyHookSuspendFrame = null;
        $fiber->propertyHookResumeRead = null;
        $fiber->hasReturnValue = false;
        $fiber->threw = true;
    }

    private function findFiberState(Frame $frame): ?FiberState
    {
        while (null !== $frame) {
            if (null !== $frame->fiberState) {
                return $frame->fiberState;
            }
            $frame = $frame->parent;
        }

        return null;
    }

    private function applyFiberPendingThrow(FiberState $fiber): void
    {
        if (!$fiber->hasPendingThrow) {
            return;
        }
        $thrown = new Variable();
        $thrown->copyFrom($fiber->pendingThrow);
        $fiber->hasPendingThrow = false;
        $fiber->pendingThrow->null();
        $frame = $fiber->frame;
        if (null === $frame) {
            $fiber->status = FiberState::STATUS_TERMINATED;
            $fiber->hasReturnValue = false;
            $fiber->threw = true;
            throw new VM\FiberUncaughtThrow($thrown);
        }
        $catchFrame = $this->findCatchFrameForFiberThrow($fiber, $thrown);
        if (null !== $catchFrame) {
            $catchFrame->fiberState = $fiber;
            $fiber->frame = $catchFrame;

            return;
        }
        $fiber->status = FiberState::STATUS_TERMINATED;
        $fiber->frame = null;
        $fiber->hasReturnValue = false;
        $fiber->threw = true;
        throw new VM\FiberUncaughtThrow($thrown);
    }
}
