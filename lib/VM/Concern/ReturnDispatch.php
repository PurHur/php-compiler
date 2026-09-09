<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\VM\Variable;

/**
 * VM TYPE_RETURN / TYPE_RETURN_VOID dispatch + return-complete epilogues (#36403).
 *
 * Extracted from {@see \PHPCompiler\VM}::runFramesInner case bodies and the
 * `return_void_complete` / `return_value_complete` labels (php-src
 * Zend/zend_vm_def.h ZEND_RETURN / ZEND_RETURN_BY_REF; Zend/zend_execute.c
 * return handlers; Zend/zend_generators.c markReturned; finally/return
 * interaction in zend_exceptions.c / zend_vm_execute.h). Concern trait —
 * same namespace as parent so relative Frame / OpCode helpers resolve.
 * Move-only; no new C ABI.
 *
 * Outcome protocol (caller maps to runFramesInner control flow):
 * - {@see Frame} → `goto restart` with that frame
 * - {@see VM::RETURN_NEXTFRAME} → `goto nextframe`
 * - other int → `return` from runFramesInner (SUCCESS / FAIL / …)
 */
trait ReturnDispatch
{
    /**
     * Execute TYPE_RETURN / TYPE_RETURN_VOID for the current opcode.
     *
     * @return Frame|int
     */
    private function executeReturnDispatch(Frame $frame, OpCode $op): Frame|int
    {
        switch ($op->type) {
        case OpCode::TYPE_RETURN_VOID:
            $frame->returnSiteLine = (int) ($op->arg1 ?? 0);
            // Explicit `return;` in a distinct finally body overrides pending try return
            // and suppresses a pending exception (#25239). Fused empty-finally epilogues
            // share the merge block and must keep exception unwind (#24728).
            if ($this->frameIsInDistinctFinallyBody($frame) && null !== $op->arg1) {
                if ($this->applyReturnInsideFinally($frame, null, true)) {
                    return $frame;
                }

                return $this->completeReturnVoid($frame);
            }
            $finallyFrame = $this->beginReturnFinallyUnwind($frame, null, true);
            if (null !== $finallyFrame) {
                return $finallyFrame;
            }
            // Empty finally may fuse with merge and end in RETURN_VOID instead of JUMP (#15738).
            if ($this->completeActiveFinallyUnwind($frame)) {
                return $frame;
            }

            return $this->completeReturnVoid($frame);
        case OpCode::TYPE_RETURN:
            $frame->returnSiteLine = (int) ($op->arg2 ?? 0);
            if (null !== $op->arg1 && isset($frame->scope[$op->arg1])) {
                $catchFrame = $this->guardUnboundThisRead($frame, (int) $op->arg1);
                if (null !== $catchFrame) {
                    return $catchFrame;
                }
            }
            $returnValue = $this->resolveVmReturnValue($frame, $op);
            // Explicit return inside a real finally body (finally block != merge) overrides
            // pending try return / pending exception (#25239). Fused empty finally shares
            // the merge block and must keep exception unwind (#24728).
            if ($this->frameIsInDistinctFinallyBody($frame)) {
                if ($this->applyReturnInsideFinally($frame, $returnValue, false)) {
                    return $frame;
                }

                return $this->completeReturnValue($frame, $returnValue);
            }
            // Empty finally may fuse with merge and end in TYPE_RETURN instead of JUMP (#24728).
            // Check exception-unwind completion BEFORE beginReturnFinallyUnwind so the
            // pending exception propagates to the outer catch instead of being swallowed
            // by a spurious return-finally chain.
            if (null !== $this->context->pendingException && $this->completeActiveFinallyUnwind($frame)) {
                return $frame;
            }
            $finallyFrame = $this->beginReturnFinallyUnwind($frame, $returnValue, false);
            if (null !== $finallyFrame) {
                return $finallyFrame;
            }
            if ($this->completeActiveFinallyUnwind($frame)) {
                return $frame;
            }

            return $this->completeReturnValue($frame, $returnValue);
        default:
            throw new \LogicException(
                'ReturnDispatch: unexpected opcode ' . opcode_type_name($op->type)
            );
        }
    }

    /**
     * Former `return_void_complete` label body.
     *
     * @return Frame|int
     */
    private function completeReturnVoid(Frame $frame): Frame|int
    {
        if ($frame->ephemeral) {
            $this->context->scriptStack->pop();
        }
        try {
            $this->enforceReturnType($frame, null);
        } catch (\TypeError $e) {
            $catchFrame = $this->dispatchVmTypeError($e, $frame);
            if (null !== $catchFrame) {
                return $catchFrame;
            }

            return self::FAIL;
        } catch (\Error $e) {
            $catchFrame = $this->dispatchVmError($e->getMessage(), $frame);
            if (null !== $catchFrame) {
                return $catchFrame;
            }

            return self::FAIL;
        }
        // Do not null returnVar: it may alias the caller result slot (#1885).
        $this->markObjectConstructedIfLeavingConstruct($frame);
        $gen = $this->findGeneratorState($frame);
        if (null !== $gen) {
            $gen->markReturned(null);
            $this->releaseFrameObjectRefs($frame);

            return self::RETURN_NEXTFRAME;
        }
        if ($frame->ephemeral && null !== $frame->parent) {
            return $this->resumeEphemeralCallerFrame($frame);
        }
        // Match return_value_complete: clear caller callSiteLine so later opcodes
        // (readonly property writes, etc.) do not cite the prior call (#25556, #21953).
        $callee = $frame;
        $caller = $this->context->pop();
        $this->releaseFrameObjectRefs($callee);
        if (null !== $caller) {
            $this->clearOutgoingCallState($caller);
            $this->restorePendingOutboundCallAfterInlineNew($caller);

            return $caller;
        }

        return self::SUCCESS;
    }

    /**
     * Former `return_value_complete` label body.
     *
     * @return Frame|int
     */
    private function completeReturnValue(Frame $frame, ?Variable $returnValue): Frame|int
    {
        if ($frame->ephemeral) {
            $this->context->scriptStack->pop();
        }
        try {
            $this->enforceReturnType($frame, $returnValue);
        } catch (\TypeError $e) {
            $catchFrame = $this->dispatchVmTypeError($e, $frame);
            if (null !== $catchFrame) {
                return $catchFrame;
            }

            return self::FAIL;
        }
        $gen = $this->findGeneratorState($frame);
        if (null !== $gen) {
            $gen->markReturned($returnValue);
            $this->markObjectConstructedIfLeavingConstruct($frame);

            return self::RETURN_NEXTFRAME;
        }
        if (!is_null($frame->returnVar)) {
            if ($this->functionReturnsByRef($frame)) {
                $frame->returnVar->indirect($returnValue);
            } else {
                $frame->returnVar->copyFrom($returnValue);
            }
        }
        $this->markObjectConstructedIfLeavingConstruct($frame);
        $callee = $frame;
        $caller = $this->context->pop();
        $this->releaseFrameObjectRefs($callee);
        if (null !== $caller) {
            $this->clearOutgoingCallState($caller);

            return $caller;
        }
        // Nested return <call>(): callee may finish with an empty run stack (#1885).
        if (null !== $frame->parent && null !== $frame->returnVar) {
            if ($this->isFunctionStaticInitContinueReturn($frame)) {
                $entry = $frame->parent;
                if (null !== $entry->returnVar) {
                    $entry->returnVar->copyFrom($returnValue);
                }
                $this->releaseFrameObjectRefs($frame);
                $caller = $this->context->pop();
                if (null !== $caller) {
                    $this->clearOutgoingCallState($caller);

                    return $caller;
                }

                return self::SUCCESS;
            }
            // Property hooks run via swapRunStack(null); parent is only for static-init
            // continue detection — must not resume the caller frame here (#7097, #7108).
            if (null !== $frame->propertyHookRawProperty) {
                return self::SUCCESS;
            }
            $child = $frame;
            $frame = $frame->parent;
            $this->releaseFrameObjectRefs($child);

            return $frame;
        }
        if ($frame->ephemeral && null !== $frame->parent) {
            return $this->resumeEphemeralCallerFrame($frame);
        }

        return self::SUCCESS;
    }
}
