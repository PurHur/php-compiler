<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCfg\Operand;
use PHPCompiler\JIT\Variable;
use PHPLLVM;

/**
 * FUNCCALL_EXEC_RETURN opcode lowering for JIT/AOT (#36387).
 *
 * Extracted from {@see CompileBlockInternal}: {@code TYPE_FUNCCALL_EXEC_RETURN}.
 * Wrapped in {@code switch (true)} so original case-level {@code break} semantics
 * are preserved (move-only; no IR shape change).
 *
 * php-src: Zend/zend_vm_def.h (ZEND_DO_FCALL / ZEND_DO_ICALL / ZEND_DO_UCALL /
 * ZEND_DO_FCALL_BY_NAME return paths), Zend/zend_execute.c — move-only Concern
 * extract; no new C ABI.
 */
trait CompileFuncCallExecReturn
{
    private function compileFuncCallExecReturnOp(
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
                        $this->context->callSiteLine = (int) ($op->arg2 ?? 0);
                        $resumeNameEarly = $this->context->scope->generatorResumeCallee;
                        if (null !== $resumeNameEarly) {
                            $this->context->scope->generatorResumeCallee = null;
                            $genArgs = [];
                            foreach ($this->context->scope->args as $a) {
                                if ($a instanceof Variable) {
                                    $genArgs[] = $a;
                                } elseif (\is_array($a) && isset($a['value']) && $a['value'] instanceof Variable) {
                                    $genArgs[] = $a['value'];
                                }
                            }
                            $genVar = \PHPCompiler\JIT\GeneratorHelper::emitCreateFromCall(
                                $this,
                                $resumeNameEarly,
                                $genArgs
                            );
                            $this->assignOperandForced($block->getOperand($op->arg1), $genVar);
                            break;
                        }
                        // Self-host stub/short-circuit (eg runtime variable function): represent as null.
                        if ($this->context->scope->preserveNewResultOnNullCall) {
                            $this->context->scope->preserveNewResultOnNullCall = false;
                            break;
                        }
                        // Fiber::suspend() in a resume function → value from Fiber::resume()/throw (#26801).
                        if ($this->context->scope->fiberSuspendResultPending) {
                            $this->context->scope->fiberSuspendResultPending = false;
                            \PHPCompiler\JIT\FiberHelper::ensureTypes($this->context);
                            $stateParam = $this->context->fiberStateParam;
                            if (null === $stateParam) {
                                throw new \LogicException('Fiber::suspend() result requires fiber state param');
                            }
                            $map = $this->context->structFieldMap['__fiber_state__'];
                            $resumeArgField = $this->context->builder->structGep(
                                $stateParam,
                                $map['resume_argument']
                            );
                            $resultVar = new Variable(
                                $this->context,
                                Variable::TYPE_VALUE,
                                Variable::KIND_VARIABLE,
                                \PHPCompiler\JIT\JitValueBox::alloc($this->context)
                            );
                            \PHPCompiler\JIT\JitValueBox::copyFromPointer(
                                $this->context,
                                $resultVar->value,
                                $resumeArgField
                            );
                            $this->assignOperandForced($block->getOperand($op->arg1), $resultVar);
                            break;
                        }
                        $nullVar = new Variable(
                            $this->context,
                            Variable::TYPE_NULL,
                            Variable::KIND_VALUE,
                            $this->context->getTypeFromString('__value__*')->constNull()
                        );
                        $nullVar->isNullConstant = true;
                        $this->assignOperandValue($block->getOperand($op->arg1), $nullVar->value);
                        break;
                    }
                    $this->context->callSiteLine = (int) ($op->arg2 ?? 0);
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
                        $nullVar = new Variable(
                            $this->context,
                            Variable::TYPE_NULL,
                            Variable::KIND_VALUE,
                            $this->context->getTypeFromString('__value__*')->constNull()
                        );
                        $nullVar->isNullConstant = true;
                        $this->assignCallResultOperand(
                            $block->getOperand($op->arg1),
                            $nullVar->value,
                            $this->calleeReturnsByRef($this->context->scope->toCall)
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
                    if (
                        $this->context->scope->toCall instanceof CoreFunc\Internal
                        && 'parse_url' === strtolower($this->context->scope->toCall->getName())
                        && 2 === count($callArgs)
                        && isset($callOperands[1])
                    ) {
                        $component = \PHPCompiler\ext\standard\JitParseUrl::tryResolveComponent(
                            $this->context,
                            $callArgs[1],
                            $this->context->jitEnclosingBlock,
                            $callOperands[1]
                        );
                        if (null !== $component) {
                            $prevStrict = $this->context->callerStrictTypes;
                            $this->context->callerStrictTypes = $block->strictTypes;
                            $result = \PHPCompiler\ext\standard\JitParseUrl::parseUrl(
                                $this->context,
                                $callArgs[0],
                                Variable::fromConstantInt($this->context, $component)
                            );
                            $this->context->callerStrictTypes = $prevStrict;
                            $this->assignCallResultOperand(
                                $block->getOperand($op->arg1),
                                $result,
                                $this->calleeReturnsByRef($this->context->scope->toCall)
                            );
                            break;
                        }
                    }
                    if (
                        $this->context->scope->toCall instanceof CoreFunc\Internal
                        && 'round' === strtolower($this->context->scope->toCall->getName())
                        && 3 === count($callArgs)
                        && isset($callOperands[2])
                    ) {
                        $mode = \PHPCompiler\ext\standard\JitRoundModeResolve::tryResolveMode(
                            $this->context,
                            $callArgs[2],
                            $block,
                            $callOperands[2]
                        );
                        if (null !== $mode) {
                            $prevStrict = $this->context->callerStrictTypes;
                            $this->context->callerStrictTypes = $block->strictTypes;
                            $result = \PHPCompiler\ext\standard\JitRound::roundWithModeInt(
                                $this->context,
                                $callArgs[0],
                                $callArgs[1],
                                $mode
                            );
                            $this->context->callerStrictTypes = $prevStrict;
                            $this->assignCallResultOperand(
                                $block->getOperand($op->arg1),
                                $result,
                                $this->calleeReturnsByRef($this->context->scope->toCall)
                            );
                            break;
                        }
                    }
                    if (
                        $this->context->scope->toCall instanceof CoreFunc\Internal
                        && 'pathinfo' === strtolower($this->context->scope->toCall->getName())
                        && 2 === count($callArgs)
                        && isset($callOperands[1])
                    ) {
                        $mask = \PHPCompiler\ext\standard\JitPathinfo::tryResolveFlags($this->context, $callArgs[1])
                            ?? \PHPCompiler\ext\standard\JitPathinfo::tryResolveFlagsFromBlock(
                                $this->context,
                                $block,
                                $callOperands[1]
                            );
                        if (null !== $mask) {
                            $prevStrict = $this->context->callerStrictTypes;
                            $this->context->callerStrictTypes = $block->strictTypes;
                            $result = \PHPCompiler\ext\standard\JitPathinfo::invoke(
                                $this->context,
                                $callArgs[0],
                                Variable::fromConstantInt($this->context, $mask)
                            );
                            $this->context->callerStrictTypes = $prevStrict;
                            $this->assignCallResultOperand(
                                $block->getOperand($op->arg1),
                                $result,
                                $this->calleeReturnsByRef($this->context->scope->toCall)
                            );
                            break;
                        }
                    }
                    if (
                        $this->context->scope->toCall instanceof CoreFunc\Internal
                        && isset($callOperands[1])
                    ) {
                        $mbFn = strtolower($this->context->scope->toCall->getName());
                        if ('mb_encode_numericentity' === $mbFn || 'mb_decode_numericentity' === $mbFn) {
                            $folded = $this->context->extensionLowering->tryFoldMbNumericEntity(
                                $this->context,
                                $block,
                                $callOperands,
                                $callArgs,
                                $mbFn
                            );
                            if (null !== $folded) {
                                $this->assignCallResultOperand(
                                    $block->getOperand($op->arg1),
                                    $folded,
                                    $this->calleeReturnsByRef($this->context->scope->toCall)
                                );
                                break;
                            }
                        }
                    }
                    $resumeName = $this->context->scope->generatorResumeCallee;
                    $this->context->scope->generatorResumeCallee = null;
                    if (null !== $resumeName) {
                        // Prefer resolved callArgs; fall back to raw scope argv (#35142).
                        $genArgs = $callArgs;
                        if ([] === $genArgs && [] !== $this->context->scope->args) {
                            foreach ($this->context->scope->args as $a) {
                                if ($a instanceof Variable) {
                                    $genArgs[] = $a;
                                } elseif (\is_array($a) && isset($a['value']) && $a['value'] instanceof Variable) {
                                    $genArgs[] = $a['value'];
                                }
                            }
                        }
                        $genVar = \PHPCompiler\JIT\GeneratorHelper::emitCreateFromCall(
                            $this,
                            $resumeName,
                            $genArgs
                        );
                        $this->assignOperandForced($block->getOperand($op->arg1), $genVar);
                        break;
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
                    $savedUnserializeOptionsOperand = $this->context->jitUnserializeOptionsOperand;
                    if (
                        $this->context->scope->toCall instanceof CoreFunc\Internal
                        && 'unserialize' === strtolower($this->context->scope->toCall->getName())
                        && isset($callOperands[1])
                    ) {
                        $this->context->jitUnserializeOptionsOperand = $callOperands[1];
                    }
                    $savedJsonEncodeValueOperand = $this->context->jitJsonEncodeValueOperand;
                    $savedJsonEncodeFlagsOperand = $this->context->jitJsonEncodeFlagsOperand;
                    $savedJsonDecodeFlagsOperand = $this->context->jitJsonDecodeFlagsOperand;
                    if (
                        $this->context->scope->toCall instanceof CoreFunc\Internal
                        && 'json_encode' === strtolower($this->context->scope->toCall->getName())
                    ) {
                        if (isset($callOperands[0])) {
                            $this->context->jitJsonEncodeValueOperand = $callOperands[0];
                        }
                        if (isset($callOperands[1])) {
                            $this->context->jitJsonEncodeFlagsOperand = $callOperands[1];
                        }
                    }
                    if (
                        $this->context->scope->toCall instanceof CoreFunc\Internal
                        && 'json_decode' === strtolower($this->context->scope->toCall->getName())
                        && isset($callOperands[3])
                    ) {
                        $this->context->jitJsonDecodeFlagsOperand = $callOperands[3];
                    }
                    $savedIteratorToArrayOperand = $this->context->jitIteratorToArrayIteratorOperand;
                    if (
                        $this->context->scope->toCall instanceof CoreFunc\Internal
                        && 'iterator_to_array' === strtolower($this->context->scope->toCall->getName())
                        && isset($callOperands[0])
                    ) {
                        $this->context->jitIteratorToArrayIteratorOperand = $callOperands[0];
                    }
                    $savedXmlrpcEncodeValueOperand = $this->context->jitXmlrpcEncodeValueOperand;
                    if (
                        $this->context->scope->toCall instanceof CoreFunc\Internal
                        && 'xmlrpc_encode' === strtolower($this->context->scope->toCall->getName())
                        && isset($callOperands[0])
                    ) {
                        $this->context->jitXmlrpcEncodeValueOperand = $callOperands[0];
                    }
                    $savedCallUserFuncArrayOperand = $this->context->jitCallUserFuncArrayParamsOperand;
                    $savedCallUserFuncCallbackOperand = $this->context->jitCallUserFuncCallbackOperand;
                    if (
                        $this->context->scope->toCall instanceof CoreFunc\Internal
                        && 'call_user_func_array' === strtolower($this->context->scope->toCall->getName())
                        && isset($callOperands[1])
                    ) {
                        $this->context->jitCallUserFuncArrayParamsOperand = $callOperands[1];
                    }
                    if (
                        $this->context->scope->toCall instanceof CoreFunc\Internal
                        && 'call_user_func' === strtolower($this->context->scope->toCall->getName())
                        && isset($callOperands[0])
                    ) {
                        $this->context->jitCallUserFuncCallbackOperand = $callOperands[0];
                    }
                    $savedMbNumericEntityConvmapOperand = $this->context->jitMbNumericEntityConvmapOperand;
                    $savedMbNumericEntityConvmapBlock = $this->context->jitMbNumericEntityConvmapBlock;
                    if (
                        $this->context->scope->toCall instanceof CoreFunc\Internal
                        && isset($callOperands[1])
                        && \in_array(strtolower($this->context->scope->toCall->getName()), ['mb_encode_numericentity', 'mb_decode_numericentity'], true)
                    ) {
                        $this->context->jitMbNumericEntityConvmapOperand = $callOperands[1];
                        $this->context->jitMbNumericEntityConvmapBlock = $block;
                    }
                    $this->promoteCompileTimeStringOnCallArgs($block, $callOperands, $callArgs);
                    $this->promoteCompileTimeDomOnCallArgs($block, $callOperands, $callArgs);
                    if ($this->context->scope->toCall instanceof CoreFunc\Internal) {
                        $callArgs = $this->densifyInternalCallArgs($this->context->scope->toCall, $callArgs);
                    }
                    $this->rewritePendingDateTimeGetOffsetIfNeeded($callArgs);
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
                    $result = $this->invokeJitCall($this->context->scope->toCall, $callArgs);
                    $this->markByRefOutParamsAssignedAfterCall(
                        $this->context->scope->toCall,
                        $callOperands,
                        $block
                    );
                    $this->context->jitUnserializeOptionsOperand = $savedUnserializeOptionsOperand;
                    $this->context->jitJsonEncodeValueOperand = $savedJsonEncodeValueOperand;
                    $this->context->jitJsonEncodeFlagsOperand = $savedJsonEncodeFlagsOperand;
                    $this->context->jitJsonDecodeFlagsOperand = $savedJsonDecodeFlagsOperand;
                    $this->context->jitIteratorToArrayIteratorOperand = $savedIteratorToArrayOperand;
                    $this->context->jitXmlrpcEncodeValueOperand = $savedXmlrpcEncodeValueOperand;
                    $this->context->jitCallUserFuncArrayParamsOperand = $savedCallUserFuncArrayOperand;
                    $this->context->jitCallUserFuncCallbackOperand = $savedCallUserFuncCallbackOperand;
                    $this->context->jitMbNumericEntityConvmapOperand = $savedMbNumericEntityConvmapOperand;
                    $this->context->jitMbNumericEntityConvmapBlock = $savedMbNumericEntityConvmapBlock;
                    $this->markNewObjectConstructedAfterCall($this->context->scope->toCall, $callArgs);
                    $this->syncDateTimeZoneConstructMetaToAliases(
                        $this->context->scope->toCall,
                        $callArgs,
                        $callOperands
                    );
                    $this->context->callerStrictTypes = $prevStrict;
                    $this->assignCallResultOperand(
                        $block->getOperand($op->arg1),
                        $result,
                        $this->calleeReturnsByRef($this->context->scope->toCall)
                    );
                    $this->attachReturnedClosureInvokeMetadata($block, $op);
                    $this->syncDateTimeDiffMetaToResult(
                        $this->context->scope->toCall,
                        $block->getOperand($op->arg1)
                    );
                    $this->syncDatePeriodUnserializeMetaToResult(
                        $this->context->scope->toCall,
                        $block->getOperand($op->arg1)
                    );
                    $this->syncDateTimeUnserializeMetaToResult(
                        $this->context->scope->toCall,
                        $block->getOperand($op->arg1)
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
                    $this->propagateXmlReaderFactoryResultType(
                        $block->getOperand($op->arg1),
                        $this->context->scope->toCall
                    );
                    $this->propagateXmlWriterFactoryResultType(
                        $block->getOperand($op->arg1),
                        $this->context->scope->toCall
                    );
                    $this->propagateDomHtmlDocumentCfsResultType(
                        $block->getOperand($op->arg1),
                        $this->context->scope->toCall
                    );
                    $this->propagateDomRemoveAttributeNodeResultType(
                        $block->getOperand($op->arg1),
                        $this->context->scope->toCall
                    );
                    $this->propagateDirectoryFactoryResultType(
                        $block->getOperand($op->arg1),
                        $this->context->scope->toCall
                    );
                    $this->propagateSerializePayloadClass(
                        $block->getOperand($op->arg1),
                        $this->context->scope->toCall,
                        $callArgs
                    );
                    $this->propagateUnserializeSplFixedArrayResultType(
                        $block->getOperand($op->arg1),
                        $this->context->scope->toCall,
                        $callArgs
                    );
                    $this->attachBoundClosureInvokeMetadata($block, $op);
                    $this->propagateDomCreateElementCompileTimeTag(
                        $block->getOperand($op->arg1),
                        $callArgs
                    );
                    $this->propagateDomGetElementByIdCompileTimeAttrs(
                        $block->getOperand($op->arg1),
                        $callArgs
                    );
                    $this->propagateDomAttrNodeCompileTimeKey(
                        $block->getOperand($op->arg1),
                        $callArgs
                    );
                    $this->propagateDomAppendChildCompileTimeTag(
                        $block->getOperand($op->arg1),
                        $callArgs
                    );
                    $this->propagateDomCreateDocumentTypeCompileTimeTag(
                        $block->getOperand($op->arg1)
                    );
                    $this->propagateDomImportNodeCompileTimeTag(
                        $block->getOperand($op->arg1),
                        $callArgs
                    );
                    $this->propagateDomNodeListItemCompileTimeChildIndex(
                        $block->getOperand($op->arg1),
                        $callArgs
                    );
                    $this->propagateDomCloneNodeCompileTimeTag(
                        $block->getOperand($op->arg1)
                    );
                    $this->propagateDomCreateTextNodeCompileTimeData(
                        $block->getOperand($op->arg1)
                    );
                    $this->propagateDomCreateCommentCompileTimeData(
                        $block->getOperand($op->arg1)
                    );
                    $this->propagateDomCreateCDATASectionCompileTimeData(
                        $block->getOperand($op->arg1)
                    );
                    $this->propagateDomCreateProcessingInstructionCompileTimeData(
                        $block->getOperand($op->arg1)
                    );
                    $this->propagateDomCreateDocumentFragmentCompileTime(
                        $block->getOperand($op->arg1)
                    );
                    $this->propagateGetClassCompileTimeString(
                        $block->getOperand($op->arg1),
                        $this->context->scope->toCall,
                        $callArgs
                    );
                    $this->propagateDomTextSplitTextCompileTimeData(
                        $block->getOperand($op->arg1)
                    );
                    $this->propagateBcMathNumberMethodCompileTime(
                        $block->getOperand($op->arg1),
                        $this->context->scope->toCall
                    );
                    $this->propagateDatePeriodCreateFromISO8601CompileTime(
                        $block->getOperand($op->arg1),
                        $this->context->scope->toCall
                    );
                    $this->propagateSimpleXmlXpathCompileTime(
                        $block->getOperand($op->arg1),
                        $this->context->scope->toCall
                    );
                    $this->propagateSimpleXmlElementCompileTime(
                        $block->getOperand($op->arg1),
                        $this->context->scope->toCall
                    );
                    $this->propagateIteratorToArrayCompileTime(
                        $block->getOperand($op->arg1),
                        $this->context->scope->toCall
                    );
                    $this->propagateDomImportSimpleXmlCompileTime(
                        $block->getOperand($op->arg1),
                        $this->context->scope->toCall
                    );
                    $this->propagateJsonEncodeFoldedString(
                        $block->getOperand($op->arg1),
                        $this->context->scope->toCall
                    );
                    $this->propagateSerializeFoldedString(
                        $block->getOperand($op->arg1),
                        $this->context->scope->toCall
                    );
                    $this->propagateStrRepeatFoldedString(
                        $block->getOperand($op->arg1),
                        $this->context->scope->toCall
                    );
                    break;
                    } finally {
                        // Peer VM clearOutgoingCallState + restorePendingOutboundCall (#15217 / #27242).
                        $this->clearJitOutgoingCallState();
                        $this->restoreJitPendingOutboundCall();
                    }
        }
    }
}
