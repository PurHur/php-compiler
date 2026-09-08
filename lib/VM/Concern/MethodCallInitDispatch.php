<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\VM\Variable;

/**
 * VM TYPE_METHODCALL_INIT dispatch (#36403).
 *
 * Extracted from {@see \PHPCompiler\VM}::runFramesInner methodcall-init case body
 * (php-src Zend/zend_vm_def.h ZEND_INIT_METHOD_CALL; zend_execute.c /
 * zend_object_handlers.c zend_std_get_method). Companion to
 * {@see MethodCallAndStaticCallableInit}::{@see initMethodCall}. Concern trait —
 * same namespace as parent so relative Frame / OpCode helpers resolve. Move-only;
 * no new C ABI.
 *
 * @return Frame|int|null Frame → restart runFramesInner; int → return from
 *         runFramesInner (SUCCESS/EXCEPTION/FAILURE); null → opcode complete.
 */
trait MethodCallInitDispatch
{
    /**
     * Execute TYPE_METHODCALL_INIT for the current opcode.
     *
     * @return Frame|int|null
     */
    private function executeMethodCallInitDispatch(Frame $frame, OpCode $op): Frame|int|null
    {
        $catchFrame = $this->guardUnboundThisRead($frame, (int) $op->arg1);
        if (null !== $catchFrame) {
            return $catchFrame;
        }
        $receiver = $frame->scope[$op->arg1]->resolveIndirect();
        $methodName = $frame->scope[$op->arg2]->toString();
        if (Variable::TYPE_OBJECT !== $receiver->type
            && Variable::TYPE_ENUM_CASE !== $receiver->type) {
            if (Variable::TYPE_NULL === $receiver->type
                && '__invoke' === strtolower($methodName)) {
                $catchFrame = $this->dispatchVmError(
                    'Value of type null is not callable',
                    $frame
                );
                if (null !== $catchFrame) {
                    return $catchFrame;
                }

                return self::EXCEPTION;
            }
            // zend_zval_value_name — bool prints true/false, not bool (#30054).
            $catchFrame = $this->dispatchVmError(
                sprintf(
                    'Call to a member function %s() on %s',
                    $methodName,
                    $this->valueDebugTypeLabel($receiver)
                ),
                $frame
            );
            if (null !== $catchFrame) {
                return $catchFrame;
            }

            return self::EXCEPTION;
        }
        if (Variable::TYPE_OBJECT === $receiver->type
            && VM\ResourceSupport::isResourceObject($receiver->toObject())) {
            $catchFrame = $this->dispatchVmError(
                sprintf('Call to a member function %s() on resource', $methodName),
                $frame
            );
            if (null !== $catchFrame) {
                return $catchFrame;
            }

            return self::EXCEPTION;
        }
        $receiver = VM\EnumCaseSupport::receiverForInstanceMethod($receiver);
        $catchFrame = $this->initMethodCall(
            $frame,
            $receiver,
            $methodName,
            $op->objectCallInvoke
        );
        if (null !== $catchFrame) {
            return $catchFrame;
        }
        if (
            '__invoke' === strtolower($methodName)
            && null !== $receiver->toObject()->closureState
        ) {
            $frame->closureCallableSlot = $op->arg1;
        }

        return null;
    }
}
