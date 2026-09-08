<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\VM\Variable;

/**
 * VM TYPE_FUNCCALL_INIT dispatch (#36403).
 *
 * Extracted from {@see \PHPCompiler\VM}::runFramesInner funccall-init case body
 * (php-src Zend/zend_vm_def.h ZEND_INIT_FCALL / ZEND_INIT_FCALL_BY_NAME /
 * ZEND_INIT_NS_FCALL_BY_NAME / ZEND_INIT_DYNAMIC_CALL; zend_execute.c call init).
 * Concern trait — same namespace as parent so relative Frame / OpCode helpers
 * resolve. Move-only; no new C ABI.
 *
 * @return Frame|int|null Frame → restart runFramesInner; int → return from
 *         runFramesInner (SUCCESS/EXCEPTION/FAILURE); null → opcode complete.
 */
trait FuncCallInitDispatch
{
    /**
     * Execute TYPE_FUNCCALL_INIT for the current opcode.
     *
     * @return Frame|int|null
     */
    private function executeFuncCallInitDispatch(Frame $frame, OpCode $op): Frame|int|null
    {
        $callee = $frame->scope[$op->arg1]->resolveIndirect();
        if (Variable::TYPE_NULL === $callee->type) {
            $catchFrame = $this->dispatchVmError(
                'Value of type null is not callable',
                $frame
            );
            if (null !== $catchFrame) {
                return $catchFrame;
            }

            return self::EXCEPTION;
        }
        if (Variable::TYPE_INTEGER === $callee->type
            || Variable::TYPE_FLOAT === $callee->type
            || Variable::TYPE_BOOLEAN === $callee->type) {
            $catchFrame = $this->dispatchVmError(
                VM\CallableCheck::scalarNotCallableMessage($callee),
                $frame
            );
            if (null !== $catchFrame) {
                return $catchFrame;
            }

            return self::EXCEPTION;
        }
        if (Variable::TYPE_OBJECT === $callee->type) {
            $closureState = $callee->toObject()->closureState;
            if (null !== $closureState) {
                $this->initClosureCall($frame, $closureState);
                $frame->closureCallableSlot = $op->arg1;
                return null;
            }
            if (!$this->hasInstanceMethod($callee->toObject()->class, '__invoke')) {
                $catchFrame = $this->dispatchVmError(
                    VM\CallableCheck::objectNotCallableMessage($callee),
                    $frame
                );
                if (null !== $catchFrame) {
                    return $catchFrame;
                }

                return self::EXCEPTION;
            }
            $catchFrame = $this->initMethodCall($frame, $callee, '__invoke', true);
            if (null !== $catchFrame) {
                return $catchFrame;
            }
            return null;
        }
        if (Variable::TYPE_ENUM_CASE === $callee->type) {
            $receiver = VM\EnumCaseSupport::receiverForInstanceMethod($callee);
            if (!$this->hasInstanceMethod($receiver->toObject()->class, '__invoke')) {
                $catchFrame = $this->dispatchVmError(
                    VM\CallableCheck::objectNotCallableMessage($callee),
                    $frame
                );
                if (null !== $catchFrame) {
                    return $catchFrame;
                }

                return self::EXCEPTION;
            }
            $catchFrame = $this->initMethodCall($frame, $receiver, '__invoke', true);
            if (null !== $catchFrame) {
                return $catchFrame;
            }
            return null;
        }
        if (Variable::TYPE_ARRAY === $callee->type) {
            $catchFrame = $this->initArrayCallable($frame, $callee);
            if (null !== $catchFrame) {
                return $catchFrame;
            }
            return null;
        }
        $name = $callee->toString();
        if (str_contains($name, '::')) {
            try {
                // Dynamic "$c()" / array callables do not resolve parent/self/static
                // as scope keywords — Zend Errors with Class "parent" not found (#25625).
                $this->initStaticCallable($frame, $name, false, false, false, true);
            } catch (\Error $e) {
                $catchFrame = $this->dispatchVmError($e->getMessage(), $frame);
                if (null !== $catchFrame) {
                    return $catchFrame;
                }
                return self::EXCEPTION;
            } catch (\LogicException $e) {
                $catchFrame = $this->dispatchVmError($e->getMessage(), $frame);
                if (null !== $catchFrame) {
                    return $catchFrame;
                }
                return self::EXCEPTION;
            }
            return null;
        }
        $lcname = $this->context->resolveFunctionCallLc($name);
        if (null === $lcname) {
            // Zend preserves source spelling (FCC / $fn(), zend_execute_API.c) (#26690).
            $catchFrame = $this->dispatchVmError(
                'Call to undefined function '.$name.'()',
                $frame
            );
            if (null !== $catchFrame) {
                return $catchFrame;
            }

            return self::EXCEPTION;
        }
        // ZEND_ACC_FORBIDDEN_WHEN_DYNAMIC — variable/$fn() calls only (#23591).
        if (
            $op->funcCallDynamic
            && VM\VariableFunctionCall::isForbiddenWhenDynamic($lcname)
        ) {
            $catchFrame = $this->dispatchVmError(
                VM\VariableFunctionCall::forbiddenWhenDynamicMessage($lcname),
                $frame
            );
            if (null !== $catchFrame) {
                return $catchFrame;
            }

            return self::EXCEPTION;
        }
        $this->savePendingOutboundCallForInlineNew($frame);
        $frame->call = $this->context->functions[$lcname];
        $frame->callArgs = [];
        $frame->callArgEntries = [];
        // Drop leftover Class::__construct from a prior `new` (#10009).
        $frame->builtinCalleeQualifiedMethod = null;
        return null;
    }
}
