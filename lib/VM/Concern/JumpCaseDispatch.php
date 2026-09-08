<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * VM TYPE_JUMP / TYPE_JUMPIF / TYPE_CASE dispatch (#36403).
 *
 * Extracted from {@see \PHPCompiler\VM}::runFramesInner jump / jumpif / case
 * bodies (php-src Zend/zend_vm_def.h ZEND_JMP / ZEND_JMPZ / ZEND_JMPNZ /
 * ZEND_CASE / ZEND_BRK / ZEND_CONT; finally unwind before leave-try edges
 * #25240). Concern trait — same namespace as parent so relative Frame /
 * OpCode helpers resolve. Move-only; no new C ABI.
 *
 * @return Frame|int|null Frame → restart runFramesInner; int → return from
 *         runFramesInner (SUCCESS/EXCEPTION/FAILURE); null → opcode complete.
 */
trait JumpCaseDispatch
{
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
