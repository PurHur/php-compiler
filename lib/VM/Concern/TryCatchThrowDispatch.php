<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\VM\Variable;

/**
 * VM TYPE_TRY / TYPE_CATCH / TYPE_FINALLY / TYPE_THROW / TYPE_RETHROW
 * dispatch (#36403).
 *
 * Extracted from {@see \PHPCompiler\VM}::runFramesInner exception-opcode
 * case bodies (php-src Zend/zend_vm_def.h ZEND_HANDLE_EXCEPTION /
 * ZEND_THROW / ZEND_CATCH / ZEND_FAST_CALL finally; zend_exceptions.c;
 * property-hook throw bubble in zend_property_hooks.c — #9503/#9666/#9670;
 * __clone external catch #23527/#12068). Companion unwind helpers remain in
 * {@see TryCatchFinallyAndUncaughtDispatch}. Concern trait — same namespace
 * as parent so relative Frame / OpCode helpers resolve. Move-only; no new
 * C ABI.
 *
 * @return Frame|int|null Frame → restart runFramesInner; int → return from
 *         runFramesInner (SUCCESS/EXCEPTION/FAILURE); null → opcode complete.
 */
trait TryCatchThrowDispatch
{
    /**
     * Execute TYPE_TRY / TYPE_CATCH / TYPE_FINALLY / TYPE_THROW / TYPE_RETHROW
     * for the current opcode.
     *
     * @return Frame|int|null
     */
    private function executeTryCatchThrowDispatch(Frame $frame, OpCode $op): Frame|int|null
    {
        switch ($op->type) {
        case OpCode::TYPE_TRY:
            $this->context->activeTryHandlerFrames[] = $frame;
            // Loop re-entry reuses the handler frame object; clear stale "finally done"
            // so break/continue unwind can run finally again (#25240).
            unset($this->context->completedFinallyHandlers[spl_object_id($frame)]);
            if (null !== $op->block2) {
                $this->context->tryMergeBlockIds[spl_object_id($op->block2)] = true;
            }
            // php-cfg may fuse try body with merge when try is only `goto` to a later label (#4491).
            if (
                null !== $op->block2
                && $op->block1 === $op->block2
                && $this->hasPendingFinally($frame)
            ) {
                $this->context->pendingGotoAfterFinally = $op->block1;
                $finallyFrame = $this->enterFinallyHandlerForUnwind($frame, false);
                if (null !== $finallyFrame) {
                    return $finallyFrame;
                }
            }
            return $op->block1->getFrame($this->context, $frame);
        case OpCode::TYPE_CATCH:
            if (null !== $this->context->pendingException) {
                if ($this->catchTypesMatch($op, $this->context->pendingException)) {
                    $caught = $this->context->pendingException;
                    $this->context->pendingException = null;
                    if (null !== $op->arg3) {
                        if (!isset($frame->scope[$op->arg3])) {
                            $frame->scope[$op->arg3] = new Variable();
                        }
                        $frame->scope[$op->arg3]->copyFrom($caught);
                    }
                    $frame = $op->block1->getFrame($this->context, $frame);
                    $this->bindCatchVariableToFrame($frame, $op->arg3, $caught);
                    return $frame;
                }
                break;
            }
            if (null !== $op->block2) {
                return $op->block2->getFrame($this->context, $frame);
            }
            break;
        case OpCode::TYPE_FINALLY:
            if (null !== $this->context->pendingException) {
                break;
            }
            if (null !== $op->block1) {
                return $op->block1->getFrame($this->context, $frame);
            }
            break;
        case OpCode::TYPE_THROW:
            $thrown = $frame->scope[$op->arg1]->resolveIndirect();
            if (null !== $op->arg2) {
                VM\ExceptionSupport::stampThrowLine($thrown, (int) $op->arg2);
            }
            // External catch during __clone throws CloneMagicCatchRedirect from
            // findCatchFrameForThrow (#23527 / #12068). Local try/catch inside __clone
            // falls through to the normal dispatchEngineThrow path below.
            if ($this->frameIsPropertyGetHook($frame)) {
                $catchFrame = $this->dispatchEngineThrow($frame, $thrown);
                if (null !== $catchFrame) {
                    // Bubble to caller stack — do not finish property read (#9503, zend_property_hooks.c).
                    $this->context->propertyHookExternalCatchFrame = $catchFrame;

                    return self::FAILURE;
                }
                break;
            }
            if ($this->frameIsPropertyUnsetHook($frame)) {
                $catchFrame = $this->dispatchEngineThrow($frame, $thrown);
                if (null !== $catchFrame) {
                    // Bubble to caller stack — do not finish unset (#9666, zend_property_hooks.c).
                    $this->context->propertyHookExternalCatchFrame = $catchFrame;

                    return self::FAILURE;
                }
                break;
            }
            if ($this->frameIsPropertySetHook($frame)) {
                $catchFrame = $this->dispatchEngineThrow($frame, $thrown);
                if (null !== $catchFrame) {
                    // Bubble to caller stack — do not finish assignment (#9670, zend_property_hooks.c).
                    $this->context->propertyHookExternalCatchFrame = $catchFrame;
                    $this->context->propertyHookSetAborted = true;

                    return self::FAILURE;
                }
                break;
            }
            $catchFrame = $this->dispatchEngineThrow($frame, $thrown);
            if (null !== $catchFrame) {
                return $catchFrame;
            }
            break;
        case OpCode::TYPE_RETHROW:
            $thrown = $this->resolveActiveCatchException($frame);
            if (null === $thrown) {
                throw new \LogicException('Cannot use "throw;" outside of a catch block');
            }
            $catchFrame = $this->dispatchEngineThrow($frame, $thrown);
            if (null !== $catchFrame) {
                return $catchFrame;
            }
            break;
        default:
            throw new \LogicException(
                'TryCatchThrowDispatch: unexpected opcode '
                . opcode_type_name($op->type)
            );
        }

        return null;
    }
}
