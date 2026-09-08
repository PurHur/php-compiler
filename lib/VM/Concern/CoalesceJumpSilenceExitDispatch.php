<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\VM\Variable;

/**
 * VM TYPE_COALESCE / TYPE_NULLSAFE / silence / TYPE_EXIT / TYPE_JUMP /
 * TYPE_JUMPIF / TYPE_CASE dispatch (#36403).
 *
 * Extracted from {@see \PHPCompiler\VM}::runFramesInner branch/silence/exit
 * case bodies (php-src Zend/zend_vm_def.h ZEND_COALESCE / ZEND_JMP_NULL /
 * ZEND_BEGIN_SILENCE / ZEND_END_SILENCE / ZEND_EXIT / ZEND_JMP / ZEND_JMPZ /
 * ZEND_CASE; zend_execute.c finally/break unwind). Concern trait — same
 * namespace as parent so relative Frame / OpCode helpers resolve. Move-only;
 * no new C ABI.
 *
 * @return Frame|int|null Frame → restart runFramesInner; int → return from
 *         runFramesInner (SUCCESS/EXCEPTION/FAILURE); null → opcode complete.
 */
trait CoalesceJumpSilenceExitDispatch
{
    /**
     * Execute TYPE_COALESCE for the current opcode.
     *
     * @return Frame|int|null
     */
    private function executeCoalesceDispatch(Frame $frame, OpCode $op): Frame|int|null
    {
        $check = $frame->scope[$op->arg2]->resolveIndirect();
        if (Variable::TYPE_BOOLEAN === $check->type) {
            $takeLeft = $check->toBool($this);
        } else {
            $takeLeft = VM\CoalesceJitHelper::takeLeftBranchFromTypeByte($check->type);
        }

        return ($takeLeft ? $op->block1 : $op->block2)->getFrame(
            $this->context,
            $frame
        );
    }

    /**
     * Execute TYPE_NULLSAFE for the current opcode.
     *
     * @return Frame|int|null
     */
    private function executeNullsafeDispatch(Frame $frame, OpCode $op): Frame|int|null
    {
        $receiver = $frame->scope[$op->arg2];

        return (
            VM\TypedPropertyCheck::nullsafeShortCircuitReceiver(
                $receiver,
                $op->nullsafeMethodCall
            )
                ? $op->block1
                : $op->block2
        )->getFrame($this->context, $frame);
    }

    /**
     * Execute TYPE_BEGIN_SILENCE for the current opcode.
     *
     * @return Frame|int|null
     */
    private function executeBeginSilenceDispatch(Frame $frame, OpCode $op): Frame|int|null
    {
        $this->context->errors->beginSilence();

        return null;
    }

    /**
     * Execute TYPE_END_SILENCE for the current opcode.
     *
     * @return Frame|int|null
     */
    private function executeEndSilenceDispatch(Frame $frame, OpCode $op): Frame|int|null
    {
        $this->context->errors->endSilence();

        return null;
    }

    /**
     * Execute TYPE_EXIT for the current opcode.
     *
     * @return Frame|int|null
     */
    private function executeExitDispatch(Frame $frame, OpCode $op): Frame|int|null
    {
        $exitArg = null;
        if (null !== $op->arg2) {
            $exitArg = $frame->scope[$op->arg2];
        }
        $exitMessage = null;
        if (null !== $op->exitMessageSlot) {
            $exitMessage = $frame->scope[$op->exitMessageSlot];
        }
        $savedCallSiteLine = $frame->callSiteLine;
        if (null !== $op->arg3 && $op->arg3 > 0) {
            $frame->callSiteLine = $op->arg3;
        }
        try {
            ext\standard\VmExit::terminate($exitArg, $frame, $exitMessage);
        } catch (\TypeError $e) {
            $catchFrame = $this->dispatchVmTypeError($e, $frame);
            $frame->callSiteLine = $savedCallSiteLine;
            if (null !== $catchFrame) {
                return $catchFrame;
            }
        } catch (\Error $e) {
            $catchFrame = $this->dispatchVmError($e->getMessage(), $frame);
            $frame->callSiteLine = $savedCallSiteLine;
            if (null !== $catchFrame) {
                return $catchFrame;
            }
        }
        $frame->callSiteLine = $savedCallSiteLine;

        return null;
    }

    /**
     * Execute TYPE_JUMP for the current opcode.
     *
     * @return Frame|int|null
     */
    private function executeJumpDispatch(Frame $frame, OpCode $op): Frame|int|null
    {
        if ($this->completeActiveFinallyUnwind($frame)) {
            return $frame;
        }
        $finallyFrame = $this->beginCatchExitFinallyUnwind($frame, $op->block1);
        if (null !== $finallyFrame) {
            return $finallyFrame;
        }
        $finallyFrame = $this->beginGotoFinallyUnwind($frame, $op->block1);
        if (null !== $finallyFrame) {
            return $finallyFrame;
        }
        if (
            null !== $frame->listUnpackAssignMergeBlock
            && $op->block1 === $frame->listUnpackAssignMergeBlock
        ) {
            $frame->listUnpackAssignMergeBlock = null;
        }

        return $this->frameForBranch($frame, $op->block1);
    }

    /**
     * Execute TYPE_JUMPIF for the current opcode.
     *
     * @return Frame|int|null
     */
    private function executeJumpIfDispatch(Frame $frame, OpCode $op): Frame|int|null
    {
        $condSlot = (int) $op->arg1;
        $arg1 = $frame->scope[$condSlot]->toBool();
        if (
            $arg1
            && [] === $this->context->activeTryHandlerFrames
            && null === $this->context->activeCatchHandlerFrame
            && !$this->frameIsInFinallyBody($frame)
        ) {
            $loopExit = $this->tryExecuteCountedIntForLoopAtJumpIf($frame, $op);
            if (null !== $loopExit) {
                return $loopExit;
            }
        }
        $this->releaseVmStatementDeadTemps($frame, $condSlot);
        $this->releaseVmJumpIfCondTemps($frame, $condSlot);
        $branchTarget = $arg1 ? $op->block1 : $op->block2;
        if (
            [] === $this->context->activeTryHandlerFrames
            && null === $this->context->activeCatchHandlerFrame
            && !$this->frameIsInFinallyBody($frame)
        ) {
            return $this->frameForBranch($frame, $branchTarget);
        }
        // break/continue lower to JumpIf edges that leave the try body; run finally
        // before the branch target (Zend ZEND_BRK/ZEND_CONT, #25240).
        if ($this->completeActiveFinallyUnwind($frame)) {
            return $frame;
        }
        $finallyFrame = $this->beginCatchExitFinallyUnwind($frame, $branchTarget);
        if (null !== $finallyFrame) {
            return $finallyFrame;
        }
        $finallyFrame = $this->beginGotoFinallyUnwind($frame, $branchTarget);
        if (null !== $finallyFrame) {
            return $finallyFrame;
        }

        return $this->frameForBranch($frame, $branchTarget);
    }

    /**
     * Execute TYPE_CASE for the current opcode.
     *
     * @return Frame|int|null
     */
    private function executeCaseDispatch(Frame $frame, OpCode $op): Frame|int|null
    {
        $arg1 = $frame->scope[$op->arg1];
        $arg2 = $frame->scope[$op->arg2];
        try {
            if ($arg1->equals($arg2, $this)) {
                return $op->block1->getFrame($this->context, $frame);
            }
        } catch (VM\BuiltinCallbackCatchRedirect $redirect) {
            return $this->resumeAfterBuiltinCallbackCatchRedirect($redirect);
        } catch (VM\MagicMethodInvocationAborted) {
            $this->clearTryCatchUnwindState();
            ++$frame->pos;

            return null;
        }

        return null;
    }
}
