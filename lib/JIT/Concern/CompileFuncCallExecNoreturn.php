<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Func as CoreFunc;
use PHPCompiler\JIT\Variable;
use PHPLLVM;

/**
 * FUNCCALL_EXEC_NORETURN opcode lowering for JIT/AOT (#36387).
 *
 * Extracted from {@see CompileBlockInternal}: {@code TYPE_FUNCCALL_EXEC_NORETURN}.
 * Wrapped in {@code switch (true)} so original case-level {@code break} semantics
 * are preserved (move-only; no IR shape change).
 *
 * php-src: Zend/zend_vm_def.h (ZEND_DO_FCALL / ZEND_DO_ICALL / ZEND_DO_UCALL /
 * ZEND_DO_FCALL_BY_NAME discard / noreturn paths), Zend/zend_execute.c —
 * move-only Concern extract; no new C ABI.
 */
trait CompileFuncCallExecNoreturn
{
    private function compileFuncCallExecNoreturnOp(
        Block $block,
        OpCode $op,
        int $i,
        PHPLLVM\Value $func,
        PHPLLVM\BasicBlock $basicBlock
    ): void {
        switch (true) {
            case true:
                    try {
                    if (is_null($this->context->scope->toCall)) {
                        // short circuit (incl. Fiber::suspend() discard in resume fn, #26801)
                        $this->context->scope->fiberSuspendResultPending = false;
                        break;
                    }
                    $this->context->callSiteLine = (int) ($op->arg1 ?? 0);
                    [$callArgs, $callOperands, $deferredNamedBindingError] = $this->resolveJitOutgoingCall(
                        $this->context->scope->toCall,
                        $this->context->scope->args,
                        $this->context->scope->argOperands
                    );
                    if (!$deferredNamedBindingError && $this->isDateTimeMutationJitCall($this->context->scope->toCall)) {
                        $callArgs = $this->canonicalizeDateMutationCallArgs($callArgs, $callOperands);
                    }
                    if ($deferredNamedBindingError) {
                        \PHPCompiler\JIT\BasicBlockHelper::ensureOpenInsertBlock(
                            $this->context,
                            'named_binding_error_resume'
                        );
                        break;
                    }
                    $callArgs = $this->prependImplicitThisForStaticInstanceCall(
                        $block,
                        $this->context->scope->toCall,
                        $callArgs
                    );
                    if ($this->context->scope->toCall instanceof \PHPCompiler\JIT\Call\Native) {
                        $nativeCall = $this->context->scope->toCall;
                        $callOperands = $this->prependImplicitThisOperandForStaticInstanceCall(
                            $block,
                            $nativeCall,
                            $callOperands
                        );
                        $callArgs = $this->adaptByRefCallArgs($nativeCall, $callArgs, $callOperands, $block);
                    }
                    if ($this->context->scope->toCall instanceof CoreFunc\Internal) {
                        $callArgs = $this->adaptByRefCallArgsForInternal(
                            $this->context->scope->toCall->getName(),
                            $callArgs,
                            $callOperands,
                            $block
                        );
                        $callArgs = $this->foldSortFamilyFlagsArg(
                            $this->context->scope->toCall->getName(),
                            $callArgs,
                            $callOperands,
                            $block
                        );
                    }
                    if (null !== $block->func && '{main}' === $block->func->name) {
                        $toCall = $this->context->scope->toCall;
                        $label = get_class($toCall);
                        if ($toCall instanceof CoreFunc\Internal) {
                            $label .= ':'.$toCall->getName();
                        } elseif ($toCall instanceof \PHPCompiler\JIT\Call\Native) {
                            $label .= ':'.$toCall->name;
                        }
                        \PHPCompiler\JIT\Progress::noteFunction('{main}:call='.$label);
                    }
                    $prevStrict = $this->context->callerStrictTypes;
                    $this->context->callerStrictTypes = $block->strictTypes;
                    $this->emitJitLateStaticCallSiteBinding($callArgs);
                    if (
                        $this->context->scope->toCall instanceof \PHPCompiler\JIT\Call\Native
                        && isset($callArgs[0])
                        && $callArgs[0] instanceof Variable
                    ) {
                        // Named-arg maps may omit index 0 (`n(b: 7)`); only check when present (#23972).
                        \PHPCompiler\JIT\BackedEnumFromJit::emitCallSiteStrictCheck(
                            $this->context,
                            $this->context->scope->toCall,
                            $callArgs[0]
                        );
                    }
                    $savedJsonDecodeFlagsOperandNoreturn = $this->context->jitJsonDecodeFlagsOperand;
                    if (
                        $this->context->scope->toCall instanceof CoreFunc\Internal
                        && 'json_decode' === strtolower($this->context->scope->toCall->getName())
                        && isset($callOperands[3])
                    ) {
                        $this->context->jitJsonDecodeFlagsOperand = $callOperands[3];
                    }
                    $this->rewritePendingDateTimeGetOffsetIfNeeded($callArgs);
                    $this->promoteCompileTimeStringOnCallArgs($block, $callOperands, $callArgs);
                    $this->promoteCompileTimeDomOnCallArgs($block, $callOperands, $callArgs);
                    // Match FUNCCALL_EXEC_RETURN order — densify before promote drops
                    // compileTimeDateInterval on date_add($dt, $interval) NORETURN (#33781).
                    if ($this->context->scope->toCall instanceof CoreFunc\Internal) {
                        $callArgs = $this->densifyInternalCallArgs($this->context->scope->toCall, $callArgs);
                    }
                    $this->applyDateTimeLocalInstantsToCallArgs(
                        $callArgs,
                        $callOperands,
                        $this->context->scope->toCall
                    );
                    $this->applyDateMetaToDatePeriodConstructArgs(
                        $this->context->scope->toCall,
                        $callArgs,
                        $callOperands
                    );
                    if (!\PHPCompiler\JIT\DiscardedPureCallElision::tryElide($this->context, $this->context->scope->toCall, $callArgs)) {
                        $this->invokeJitCall($this->context->scope->toCall, $callArgs);
                    }
                    $this->markByRefOutParamsAssignedAfterCall(
                        $this->context->scope->toCall,
                        $callOperands,
                        $block
                    );
                    $this->context->jitJsonDecodeFlagsOperand = $savedJsonDecodeFlagsOperandNoreturn;
                    \PHPCompiler\JIT\NoDiscardCallGuard::emitAfterDiscardedReturn($this->context, $this->context->scope->toCall);
                    $this->markNewObjectConstructedAfterCall($this->context->scope->toCall, $callArgs);
                    $this->syncDateTimeZoneConstructMetaToAliases(
                        $this->context->scope->toCall,
                        $callArgs,
                        $callOperands
                    );
                    $this->syncDateTimeConstructMetaToAliases(
                        $this->context->scope->toCall,
                        $callArgs
                    );
                    $this->syncDateIntervalConstructMetaToAliases(
                        $this->context->scope->toCall,
                        $callArgs
                    );
                    $this->syncDatePeriodConstructMetaToAliases(
                        $this->context->scope->toCall,
                        $callArgs
                    );
                    if ($this->context->scope->toCall instanceof \PHPCompiler\JIT\Call\DateTimeDiff) {
                        $this->context->lastDateIntervalDiffState = null;
                    }
                    $this->context->callerStrictTypes = $prevStrict;
                    break;
                    } finally {
                        $this->clearJitOutgoingCallState();
                        $this->restoreJitPendingOutboundCall();
                    }
        }
    }
}
