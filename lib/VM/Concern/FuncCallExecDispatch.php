<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\VM\CallableCheck;
use PHPCompiler\VM\GeneratorState;
use PHPCompiler\VM\IterableCheck;
use PHPCompiler\VM\ReferencableCheck;
use PHPCompiler\VM\TypeCheck;

/**
 * VM TYPE_FUNCCALL_EXEC_RETURN / TYPE_FUNCCALL_EXEC_NORETURN dispatch (#36403).
 *
 * Extracted from {@see \PHPCompiler\VM}::runFramesInner funccall-exec case body
 * (php-src Zend/zend_vm_def.h ZEND_DO_FCALL / ZEND_DO_ICALL / ZEND_DO_UCALL /
 * ZEND_DO_FCALL_BY_NAME; zend_execute.c execute_ex call trampoline). Concern trait —
 * same namespace as parent so relative Frame / OpCode / Func helpers resolve.
 * Move-only; no new C ABI.
 *
 * @return Frame|int|null Frame → restart runFramesInner; int → return from
 *         runFramesInner (SUCCESS/EXCEPTION/FAILURE); null → opcode complete.
 */
trait FuncCallExecDispatch
{
    /**
     * Execute FUNCCALL_EXEC_RETURN / FUNCCALL_EXEC_NORETURN for the current opcode.
     *
     * @return Frame|int|null
     */
    private function executeFuncCallExecDispatch(Frame $frame, OpCode $op): Frame|int|null
    {
        if (is_null($frame->call)) {
            // Used for null constructors, etc
            $this->markPendingNewObjectConstructed($frame);
            if (OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type && is_int($op->arg1)) {
                $this->markScopeSlotInitialized($frame, (int) $op->arg1);
            }
            $frame->callArgs = [];
            $frame->callArgEntries = [];
            // Null ctor stub: drop Class::__construct so later builtins use real names (#10009).
            if ([] === $frame->pendingOutboundCallRestore) {
                $frame->builtinCalleeQualifiedMethod = null;
            }
            $this->restorePendingOutboundCallAfterInlineNew($frame);
            return null;
        }
        $frame->callSiteLine = OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type
            ? (int) ($op->arg2 ?? 0)
            : (int) ($op->arg1 ?? 0);
        $this->emitCallDeprecationNotice($frame);
        $this->emitCallNoDiscardNotice($frame, $op);
        if ($frame->call instanceof Func\PHP && $frame->call->block->isGenerator) {
            try {
                $calledArgs = $this->resolveOutgoingCallArgs($frame);
                ReferencableCheck::assertOutgoingCallArgs($frame->call, $frame, $calledArgs);
            } catch (\ArgumentCountError $e) {
                $catchFrame = $this->dispatchVmArgumentCountError($e, $frame);
                if (null !== $catchFrame) {
                    return $catchFrame;
                }
                return null;
            } catch (\TypeError $e) {
                $catchFrame = $this->dispatchVmTypeError($e, $frame);
                if (null !== $catchFrame) {
                    return $catchFrame;
                }
                return null;
            } catch (\Error $e) {
                $catchFrame = $this->dispatchVmError($e->getMessage(), $frame);
                if (null !== $catchFrame) {
                    return $catchFrame;
                }
                return null;
            } catch (\LogicException $e) {
                return $this->raise($e->getMessage(), $frame);
            }
            $closureState = $this->resolvePendingClosureState($frame);
            $state = new GeneratorState($this, $frame->call, $calledArgs);
            if (
                null !== $closureState
                && $frame->call instanceof Func\PHP
                && $frame->call === $closureState->func
            ) {
                $state->closureCall = $closureState;
            }
            if (OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type) {
                $this->scopeSlot($frame, (int) $op->arg1)->object($state->wrapObject());
            }
            $frame->call = null;
            $this->clearOutgoingCallState($frame);
            return null;
        }
        try {
            $calledArgs = $this->resolveOutgoingCallArgs($frame);
            ReferencableCheck::assertOutgoingCallArgs($frame->call, $frame, $calledArgs);
            // Typed-int self-recursive leaf (fibo_r): evaluate in host PHP (#36411 / #36449).
            if (
                $frame->call instanceof Func\PHP
                && $this->tryExecuteTypedIntSelfRecursive($frame->call, $calledArgs, $frame, $op)
            ) {
                return null;
            }
            // Zend strict_types is a *caller* (call-site) rule; standalone literal types
            // (`true`/`false`/`null`) always exact-match (issue #7057).
            if (
                $frame->call instanceof Func\PHP
                && [] !== $calledArgs
            ) {
                $callSiteLine = OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type
                    ? (int) ($op->arg2 ?? 0)
                    : (int) ($op->arg1 ?? 0);
                $calleeBlock = $frame->call->block;
                $callerStrict = $frame->block->strictTypes;
                $thisArgOffset = 0;
                if (
                    null !== $calleeBlock->func
                    && null !== $calleeBlock->func->class
                    && !(($calleeBlock->func->flags ?? 0) & \PHPCfg\Func::FLAG_STATIC)
                    && !(($calleeBlock->func->flags ?? 0) & \PHPCfg\Func::FLAG_CLOSURE)
                ) {
                    $thisArgOffset = 1;
                }
                foreach ($calleeBlock->argRecvOpcodes() as $recv) {
                    $paramIdx = (int) $recv->arg2;
                    $argIndex = $paramIdx + $thisArgOffset;
                    if (!array_key_exists($argIndex, $calledArgs)) {
                        continue;
                    }
                    $slot = (int) $recv->arg1;
                    if (
                        !$callerStrict
                        && !$calleeBlock->paramRequiresExactLiteralMatch($slot)
                    ) {
                        continue;
                    }
                    $arg = $calledArgs[$argIndex];
                    if (
                        TypeCheck::skipParameterTypeCheckForImplicitNullable(
                            $calleeBlock,
                            $slot,
                            $arg
                        )
                    ) {
                        continue;
                    }
                    if (isset($calleeBlock->paramNeverSlots[$slot])) {
                        $paramName = $calleeBlock->paramNames[$paramIdx] ?? 'param'.$paramIdx;
                        throw VM\ParamTypeError::forUserCallWithExpectedType(
                            SourcePreprocessor\PropertyHooks::zendTypeErrorCallableName(
                                $frame->call->getName()
                            ),
                            $paramIdx,
                            $paramName,
                            'never',
                            $arg,
                            $frame->scriptPath,
                            $callSiteLine
                        );
                    }
                    if (isset($calleeBlock->paramIterableSlots[$slot])) {
                        if (!IterableCheck::isIterable($arg, $this->context)) {
                            $paramName = $calleeBlock->paramNames[$paramIdx] ?? 'param'.$paramIdx;
                            throw VM\ParamTypeError::forUserCallWithExpectedType(
                                SourcePreprocessor\PropertyHooks::zendTypeErrorCallableName(
                                    $frame->call->getName()
                                ),
                                $paramIdx,
                                $paramName,
                                IterableCheck::TYPE_LABEL,
                                $arg,
                                $frame->scriptPath,
                                $callSiteLine
                            );
                        }
                        continue;
                    }
                    if (isset($calleeBlock->paramCallableSlots[$slot])) {
                        if (!CallableCheck::isCallable($arg, $this->context, $frame)) {
                            $paramName = $calleeBlock->paramNames[$paramIdx] ?? 'param'.$paramIdx;
                            throw VM\ParamTypeError::forUserCallWithExpectedType(
                                SourcePreprocessor\PropertyHooks::zendTypeErrorCallableName(
                                    $frame->call->getName()
                                ),
                                $paramIdx,
                                $paramName,
                                CallableCheck::TYPE_LABEL,
                                $arg,
                                $frame->scriptPath,
                                $callSiteLine
                            );
                        }
                        continue;
                    }
                    if (isset($calleeBlock->paramIntersectionConstraints[$slot])) {
                        $paramName = $calleeBlock->paramNames[$paramIdx] ?? 'param'.$paramIdx;
                        $expected = $calleeBlock->paramIntersectionDisplayLabels[$slot]
                            ?? implode('&', $calleeBlock->paramIntersectionConstraints[$slot]);
                        try {
                            TypeCheck::assertParamIntersection(
                                $arg,
                                $calleeBlock->paramIntersectionConstraints[$slot],
                                $this->context,
                                $expected
                            );
                        } catch (\TypeError $e) {
                            throw VM\ParamTypeError::forUserCallWithExpectedType(
                                SourcePreprocessor\PropertyHooks::zendTypeErrorCallableName(
                                    $frame->call->getName()
                                ),
                                $paramIdx,
                                $paramName,
                                $expected,
                                $arg,
                                $frame->scriptPath,
                                $callSiteLine
                            );
                        }
                        continue;
                    }
                    $constraint = $calleeBlock->paramTypeConstraints[$slot] ?? null;
                    if (null === $constraint) {
                        continue;
                    }
                    $literalBool = $calleeBlock->paramLiteralBoolTypes[$slot] ?? null;
                    if (!TypeCheck::parameterMatchesType($arg, $constraint, $literalBool)) {
                        $paramName = $calleeBlock->paramNames[$paramIdx] ?? 'param'.$paramIdx;
                        throw VM\ParamTypeError::forUserCall(
                            SourcePreprocessor\PropertyHooks::zendTypeErrorCallableName(
                                $frame->call->getName()
                            ),
                            $paramIdx,
                            $paramName,
                            $constraint,
                            $arg,
                            $frame->scriptPath,
                            $callSiteLine,
                            $literalBool
                        );
                    }
                }
            }
        } catch (\ArgumentCountError $e) {
            $catchFrame = $this->dispatchVmArgumentCountError($e, $frame);
            if (null !== $catchFrame) {
                return $catchFrame;
            }
            return null;
        } catch (\TypeError $e) {
            $catchFrame = $this->dispatchVmTypeError($e, $frame);
            if (null !== $catchFrame) {
                return $catchFrame;
            }
            return null;
        } catch (\Error $e) {
            $catchFrame = $this->dispatchVmError($e->getMessage(), $frame);
            if (null !== $catchFrame) {
                return $catchFrame;
            }
            return null;
        } catch (\LogicException $e) {
            return $this->raise($e->getMessage(), $frame);
        }
        $new = $frame->call->getFrame(
            $this->context,
            $frame
        );
        $closureState = $this->resolvePendingClosureState($frame);
        $frame->closureCallableSlot = null;
        $ownClosureState = $frame->closureCall;
        $preserveOwnClosureCall = null !== $ownClosureState
            && null !== $frame->block->func
            && (($frame->block->func->flags ?? 0) & \PHPCfg\Func::FLAG_CLOSURE) !== 0;
        if (!$preserveOwnClosureCall) {
            $frame->closureCall = null;
        }
        $frame->pendingClosureInvoke = null;
        // Only bind captures/$this/called-scope when entering the closure body, not nested
        // $this->method() (#4927). wrappedFunc is the fromCallable/FCC static-method target
        // (stub $closureState->func differs) — still apply boundScopeClass for LSB (#24431).
        if (
            null !== $closureState
            && (
                ($frame->call instanceof Func\PHP && $frame->call === $closureState->func)
                || (null !== $closureState->wrappedFunc && $frame->call === $closureState->wrappedFunc)
            )
        ) {
            $this->applyClosureBinding($new, $closureState);
        }
        if (null === $new->calledClass || '' === $new->calledClass) {
            $new->calledClass = $this->inferCalledClass($frame);
        }
        $new->returnVar = null;
        if ($op->type === OpCode::TYPE_FUNCCALL_EXEC_RETURN) {
            $new->returnVar = $this->scopeSlot($frame, (int) $op->arg1);
        } else {
            $new->returnVar = null;
        }
        $new->calledArgs = $calledArgs;
        if ($new->hasHandler()) {
            $new->parent = $frame;
            $new->vmContext = $this->context;
            $catchFrame = $this->executeInternalHandler($new, $frame);
            if (null !== $catchFrame) {
                return $catchFrame;
            }
            if ($frame->fiberSuspend) {
                $frame->fiberSuspend = false;
                // Pos already advanced past FUNCCALL_EXEC_*; stale callArgEntries
                // would replay the prior suspend operand on the next resume (#18162).
                $frame->call = null;
                $this->clearOutgoingCallState($frame);
                $this->restorePendingOutboundCallAfterInlineNew($frame);

                return self::FIBER_SUSPEND;
            }
            $frame->call = null;
            $keepReturnSlot = OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type
                ? (int) $op->arg1
                : null;
            $this->clearOutgoingCallState($frame, $keepReturnSlot);
            $this->restorePendingOutboundCallAfterInlineNew($frame);
            if (OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type) {
                $this->releaseVmStatementDeadTemps($frame, (int) $op->arg1);
            }
            return null;
        }
        $catchFrame = $this->guardFiberStackBeforeCall($frame);
        if (null !== $catchFrame) {
            return $catchFrame;
        }
        $this->context->push($frame);
        return $new;
    }
}
