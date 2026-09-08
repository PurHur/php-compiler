<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\VM\Variable;

/**
 * VM TYPE_COALESCE / TYPE_NULLSAFE / TYPE_BEGIN_SILENCE / TYPE_END_SILENCE /
 * TYPE_EXIT dispatch (#36403).
 *
 * Extracted from {@see \PHPCompiler\VM}::runFramesInner coalesce / nullsafe /
 * silence / exit case bodies (php-src Zend/zend_vm_def.h ZEND_COALESCE /
 * ZEND_JMP_NULL / ZEND_BEGIN_SILENCE / ZEND_END_SILENCE / ZEND_EXIT;
 * zend_execute.c exit status + error-silence nesting). Concern trait — same
 * namespace as parent so relative Frame / OpCode helpers resolve. Move-only;
 * no new C ABI.
 *
 * @return Frame|int|null Frame → restart runFramesInner; int → return from
 *         runFramesInner (SUCCESS/EXCEPTION/FAILURE); null → opcode complete.
 */
trait CoalesceNullsafeSilenceExitDispatch
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
}
